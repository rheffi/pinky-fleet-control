<?php

namespace Database\Seeders;

use App\Models\FleetState;
use App\Models\Robot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FleetSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            FleetState::firstOrCreate(['id' => 1]);
            FleetState::whereKey(1)->lockForUpdate()->firstOrFail();
            foreach (config('fleet.robots') as $id => $settings) {
                Robot::firstOrCreate(['id' => $id], [
                    'label' => 'Pinky '.$id,
                    'ros_domain_id' => $settings['domain_id'],
                    'motion_state' => 'unknown',
                    'pose' => null,
                    'received_at' => null,
                ]);
            }
        });
    }
}
