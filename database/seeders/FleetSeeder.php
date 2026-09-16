<?php

namespace Database\Seeders;

use App\Fleet\SampleFixture;
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
            $fixture = new SampleFixture;
            foreach (SampleFixture::ROBOTS as $id => $domain) {
                Robot::firstOrCreate(['id' => $id], [
                    'label' => 'Pinky '.$id, 'ros_domain_id' => $domain,
                    'motion_state' => 'idle', 'pose' => $fixture->pose(0.6, SampleFixture::LANES[$id]),
                ]);
            }
        });
    }
}
