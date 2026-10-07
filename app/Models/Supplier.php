<?php

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use LogsChanges, SoftDeletes;

    protected $fillable = [
        'name',
        'contact_person',
        'mobile',
        'address',
        'terms',
        'notes',
    ];
}
