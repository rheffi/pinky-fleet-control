<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RunEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'immutable_datetime'];
}
