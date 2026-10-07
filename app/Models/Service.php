<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use LogsChanges, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'labor_fee',
        'est_minutes',
        'commission_type',
        'commission_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'labor_fee' => 'decimal:2',
            'est_minutes' => 'integer',
            'commission_type' => CommissionType::class,
            'commission_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
