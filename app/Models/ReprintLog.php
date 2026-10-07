<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReprintLog extends Model
{
    protected $fillable = [
        'document',
        'printable_type',
        'printable_id',
        'user_id',
        'ip_address',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function printable(): MorphTo
    {
        return $this->morphTo();
    }
}
