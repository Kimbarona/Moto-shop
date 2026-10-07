<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ManagerApproval extends Model
{
    protected $fillable = [
        'action',
        'approver_id',
        'requested_by',
        'subject_type',
        'subject_id',
        'details',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'details' => 'array',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
