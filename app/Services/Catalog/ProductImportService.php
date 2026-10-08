<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MotorcycleModel;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bulk add / update products from a spreadsheet. Rows match on SKU (blank SKU = new
 * product with the next SKU). Categories and brands are created when new; units and
 * motorcycle models must already exist. Stock is NEVER imported — opening stock goes
 * through Stock In so every qty has a movement and a cost.
 *
 * Each row is saved on its own: good rows go in, bad rows are reported with the reason.
 * SRP is only changed by users allowed to edit pricing.
 */
class ProductImportService
{
    public const COLUMNS = [
        'sku', 'name', 'part_number', 'category', 'brand', 'unit', 'srp', 'reorder_level',
        'bin_location', 'universal', 'active', 'fits', 'description',
    ];

    /**
     * @param  iterable<array<string, mixed>>  $rows  heading-keyed rows (from the first sheet)
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(iterable $rows, User $actor): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $canPrice = $actor->can('edit-pricing');
        $units = Unit::all();
        $models = MotorcycleModel::with('make')->get()->keyBy(fn (MotorcycleModel $m) => Str::lower($m->full_name));
        $line = 1;

        foreach ($rows as $raw) {
            $line++;
            $row = $this->normalize($raw);

            if (collect($row)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                continue; // blank line
            }

            $existing = filled($row['sku']) ? Product::withTrashed()->where('sku', $row['sku'])->first() : null;

            $validator = Validator::make($row, [
                'name' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
                'srp' => [$existing || ! $canPrice ? 'nullable' : 'required', 'numeric', 'min:0', 'max:9999999'],
                'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:999999'],
                'unit' => [$existing ? 'nullable' : 'required', 'string'],
                'part_number' => ['nullable', 'string', 'max:100'],
                'bin_location' => ['nullable', 'string', 'max:50'],
                'category' => ['nullable', 'string', 'max:100'],
                'brand' => ['nullable', 'string', 'max:100'],
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $unit = null;

            if (filled($row['unit'])) {
                $unit = $units->first(fn (Unit $u) => Str::lower($u->abbreviation) === Str::lower($row['unit']) || Str::lower($u->name) === Str::lower($row['unit']));

                if (! $unit) {
                    $result['errors'][] = "Row {$line}: unknown unit \"{$row['unit']}\". Add it under Masterlist → Units first.";
                    $result['skipped']++;

                    continue;
                }
            }

            $fits = collect(preg_split('/[;\n]+/', (string) $row['fits']))->map(fn ($v) => trim($v))->filter()->values();
            $unknownFits = $fits->reject(fn ($name) => $models->has(Str::lower($name)));

            if ($unknownFits->isNotEmpty()) {
                $result['errors'][] = "Row {$line}: unknown motorcycle model(s) ".$unknownFits->map(fn ($n) => "\"{$n}\"")->implode(', ').' — use "Make Model" exactly as in Masterlist.';
                $result['skipped']++;

                continue;
            }

            if (! $canPrice && filled($row['srp'])) {
                if (! $existing || round((float) $row['srp'], 2) !== round((float) $existing->srp, 2)) {
                    $result['errors'][] = "Row {$line}: SRP ignored (only admins set prices); the rest of the row was saved.";
                }

                $row['srp'] = null;
            }

            DB::transaction(function () use ($row, $existing, $unit, $fits, $models, &$result) {
                $attributes = array_filter([
                    'name' => $row['name'],
                    'part_number' => $row['part_number'],
                    'category_id' => filled($row['category']) ? Category::withTrashed()->firstOrCreate(['name' => $row['category']])->id : null,
                    'brand_id' => filled($row['brand']) ? Brand::withTrashed()->firstOrCreate(['name' => $row['brand']])->id : null,
                    'unit_id' => $unit?->id,
                    'srp' => filled($row['srp']) ? round((float) $row['srp'], 2) : null,
                    'reorder_level' => filled($row['reorder_level']) ? (float) $row['reorder_level'] : null,
                    'bin_location' => $row['bin_location'],
                    'description' => $row['description'],
                    'is_universal' => $this->bool($row['universal']),
                    'is_active' => $this->bool($row['active']),
                ], fn ($v) => $v !== null && $v !== '');

                if ($existing) {
                    $existing->fill($attributes)->save();
                    $product = $existing;
                    $result['updated']++;
                } else {
                    $product = Product::create([
                        'sku' => $row['sku'] ?: null,
                        'is_active' => true,
                        'reorder_level' => 0,
                        ...$attributes,
                    ]);
                    $result['created']++;
                }

                if ($fits->isNotEmpty()) {
                    $product->fitsModels()->sync($fits->map(fn ($name) => $models[Str::lower($name)]->id)->unique()->all());
                }
            });
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|Collection<string, mixed>  $raw
     * @return array<string, string|null>
     */
    private function normalize(array|Collection $raw): array
    {
        $raw = collect($raw)->mapWithKeys(fn ($value, $key) => [Str::snake(trim(preg_replace('/\(.*\)/', '', (string) $key))) => $value]);

        return collect(self::COLUMNS)->mapWithKeys(function (string $column) use ($raw) {
            $value = $raw[$column] ?? null;

            return [$column => $value === null ? null : trim((string) $value)];
        })->all();
    }

    private function bool(?string $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array(Str::lower($value), ['1', 'yes', 'y', 'true', 'oo'], true);
    }
}
