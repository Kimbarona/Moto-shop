<?php

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerMotorcycle extends Model
{
    use LogsChanges, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'motorcycle_model_id',
        'plate_no',
        'year',
        'color',
        'last_odometer',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_odometer' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function motorcycleModel(): BelongsTo
    {
        return $this->belongsTo(MotorcycleModel::class);
    }

    /**
     * Plates are stored upper-case with single spaces so searches match.
     */
    protected function plateNo(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null || trim($value) === ''
            ? null
            : strtoupper((string) preg_replace('/\s+/', ' ', trim($value))));
    }
}
