<?php

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use LogsChanges, SoftDeletes;

    protected $fillable = [
        'name',
        'mobile',
        'address',
        'notes',
    ];

    public function motorcycles(): HasMany
    {
        return $this->hasMany(CustomerMotorcycle::class);
    }
}
