<?php

namespace App\Console\Commands;

use App\Fleet\FleetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SimulateFleet extends Command
{
    protected $signature = 'fleet:simulate {--ticks=0 : Stop after this many ticks, 0 runs continuously}';

    protected $description = 'Run the sample-only fleet replay (no robot connection)';

    public function handle(FleetService $fleet): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->error('The sample worker requires MySQL for its exclusive connection lock.');

            return self::FAILURE;
        }
        $lock = 'pinky-fleet-sample-worker';
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired !== 1) {
            $this->error('A sample worker is already running.');

            return self::FAILURE;
        }
        $ticks = max(0, (int) $this->option('ticks'));
        try {
            $fleet->recover();
            $this->info('Sample worker started. No physical robot commands will be sent.');
            for ($i = 0; $ticks === 0 || $i < $ticks; $i++) {
                $owner = DB::selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [$lock]);
                if ((int) $owner->owned !== 1) {
                    throw new \RuntimeException('Sample worker lock lost.');
                }
                $fleet->tick();
                sleep(1);
            }
        } catch (Throwable $error) {
            $this->error('Sample worker stopped: '.class_basename($error));

            return self::FAILURE;
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        return self::SUCCESS;
    }
}
