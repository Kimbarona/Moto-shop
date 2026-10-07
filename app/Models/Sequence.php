<?php

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    use LogsChanges;

    protected $fillable = [
        'key',
        'prefix',
        'next_number',
        'padding',
    ];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'padding' => 'integer',
        ];
    }
}
