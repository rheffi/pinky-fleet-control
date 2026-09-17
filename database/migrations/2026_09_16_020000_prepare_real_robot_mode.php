<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('run_events')->whereIn('run_id', DB::table('runs')->where('mode', 'sample')->select('id'))->delete();
        DB::table('commands')->whereIn('run_id', DB::table('runs')->where('mode', 'sample')->select('id'))->delete();
        DB::table('run_robots')->whereIn('run_id', DB::table('runs')->where('mode', 'sample')->select('id'))->delete();
        DB::table('runs')->where('mode', 'sample')->delete();

        DB::table('robots')->update([
            'motion_state' => 'unknown',
            'pose' => null,
            'received_at' => null,
        ]);
        DB::table('fleet_state')->where('id', 1)->update([
            'active_run_id' => null,
            'heartbeat_at' => null,
            'revision' => DB::raw('revision + 1'),
        ]);
    }

    public function down(): void
    {
        // Sample positions and run history cannot be reconstructed safely.
    }
};
