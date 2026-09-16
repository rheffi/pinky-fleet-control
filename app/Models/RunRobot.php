<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RunRobot extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['goal_pose' => 'array', 'planned_path' => 'array', 'result' => 'array', 'stop_ack_at' => 'immutable_datetime'];
}
