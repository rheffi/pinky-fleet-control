<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetState extends Model
{
    protected $table = 'fleet_state';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['heartbeat_at' => 'immutable_datetime', 'revision' => 'integer'];
}
