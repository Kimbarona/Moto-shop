<?php

namespace App\Filament\Resources\ServicePackages;

use App\Filament\Resources\ServicePackages\Pages\CreateServicePackage;
use App\Filament\Resources\ServicePackages\Pages\EditServicePackage;
use App\Filament\Resources\ServicePackages\Pages\ListServicePackages;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * A bundle of labor + parts (e.g. "PMS – Scooter"). Adding a package to an order expands
 * it into individual lines; a fixed package price is spread over those lines.
 */
class ServicePackageResource extends Resource
{
    protected static ?string $model = ServicePackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Masterlist';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(150),
                        TextInput::make('package_price')->label('Fixed package price')->numeric()->prefix('₱')->minValue(0)
                            ->helperText('Leave blank to charge each line at its normal price.'),
                        Textarea::make('description')->rows(2)->columnSpanFull(),
                        Toggle::make('is_active')->default(true),
                    ]),
                Section::make('Contents')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->columns(4)
                            ->minItems(1)
                            ->addActionLabel('Add line')
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => static::normalizeLine($data))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => static::normalizeLine($data))
                            ->schema([
                                Select::make('line_type')->label('Type')
                                    ->options(['labor' => 'Labor', 'part' => 'Part'])
                                    ->default('labor')
                                    ->required()
                                    ->live()
                                    ->afterStateHydrated(fn (Select $component, Get $get) => $component->state($get('product_id') ? 'part' : 'labor'))
                                    ->dehydrated(false),
                                Select::make('service_id')->label('Service')
                                    ->options(fn () => Service::active()->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->visible(fn (Get $get) => $get('line_type') !== 'part')
                                    ->required(fn (Get $get) => $get('line_type') !== 'part')
                                    ->columnSpan(2),
                                Select::make('product_id')->label('Part')
                                    ->options(fn () => Product::active()->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->visible(fn (Get $get) => $get('line_type') === 'part')
                                    ->required(fn (Get $get) => $get('line_type') === 'part')
                                    ->columnSpan(2),
                                TextInput::make('qty')->numeric()->minValue(0.01)->default(1)->required(),
                            ]),
                        TextEntry::make('regular_total')->label('Regular price of contents')
                            ->state(fn (Get $get) => Money::format(static::regularTotal($get('items') ?? []))),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('items'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold')
                    ->description(fn (ServicePackage $record) => $record->description),
                TextColumn::make('items_count')->label('Lines')->alignEnd(),
                TextColumn::make('package_price')->money('PHP')->alignEnd()->placeholder('Regular prices'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label('Archive'),
                RestoreAction::make(),
            ]);
    }

    /**
     * A line is either labor or a part, never both.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeLine(array $data): array
    {
        if (filled($data['product_id'] ?? null)) {
            $data['service_id'] = null;
        } else {
            $data['product_id'] = null;
        }

        return $data;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $items
     */
    public static function regularTotal(array $items): float
    {
        $services = Service::whereIn('id', collect($items)->pluck('service_id')->filter())->pluck('labor_fee', 'id');
        $products = Product::whereIn('id', collect($items)->pluck('product_id')->filter())->pluck('srp', 'id');

        return collect($items)->sum(function (array $item) use ($services, $products) {
            $qty = (float) ($item['qty'] ?? 0);

            return ($item['line_type'] ?? null) === 'part' || filled($item['product_id'] ?? null)
                ? $qty * (float) ($products[$item['product_id'] ?? 0] ?? 0)
                : $qty * (float) ($services[$item['service_id'] ?? 0] ?? 0);
        });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServicePackages::route('/'),
            'create' => CreateServicePackage::route('/create'),
            'edit' => EditServicePackage::route('/{record}/edit'),
        ];
    }
}
