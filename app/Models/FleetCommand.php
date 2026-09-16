<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetCommand extends Model
{
    protected $table = 'commands';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'immutable_datetime'];
}
