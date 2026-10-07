<?php

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MotorcycleModel extends Model
{
    use LogsChanges, SoftDeletes;

    protected $fillable = [
        'motorcycle_make_id',
        'name',
        'engine_cc',
        'year_from',
        'year_to',
    ];

    protected function casts(): array
    {
        return [
            'engine_cc' => 'integer',
            'year_from' => 'integer',
            'year_to' => 'integer',
        ];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(MotorcycleMake::class, 'motorcycle_make_id');
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(ProductFitment::class);
    }

    /**
     * "Honda Click 125"
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim(($this->make?->name ?? '').' '.$this->name));
    }
}
