<?php

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use LogsChanges, SoftDeletes;

    protected $fillable = [
        'name',
        'abbreviation',
        'allows_decimal',
    ];

    protected function casts(): array
    {
        return [
            'allows_decimal' => 'boolean',
        ];
    }
}
