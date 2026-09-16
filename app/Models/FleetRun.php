<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetRun extends Model
{
    protected $table = 'runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['error' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'tick' => 'integer'];

    public function robots(): HasMany
    {
        return $this->hasMany(RunRobot::class, 'run_id')->orderBy('robot_id');
    }
}
