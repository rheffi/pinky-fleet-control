<?php

namespace App\Http\Controllers;

use App\Fleet\DisplayMap;
use App\Fleet\FleetService;
use App\Models\FleetRun;
use App\Models\RunEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FleetController extends Controller
{
    public function __construct(
        private snapshot $fleet,
        private DisplayMap $displayMap,
    ) {}

    public function bootstrap()
    {
        return DB::transaction(function () {
            $state = $this->fleet->state();

            return [
                ...$this->fleet->meta($state),
                'robots' => $this->fleet->configuredRobots(),
                'map' => $this->displayMap->metadata(),
                'settings' => [
                    'poll_ms' => 1000,
                    'stale_seconds' => config('fleet.stale_seconds'),
                    'offline_seconds' => config('fleet.offline_seconds'),
                ],
            ];
        });
    }

    public function snapshot()
    {
        return $this->fleet->snapshot();
    }

    public function start(Request $request, string $robot)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid']]);
        $result = $this->fleet->start($robot, $data['request_id']);

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function stop(Request $request, string $id)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid']]);
        $result = $this->fleet->stop($id, $data['request_id']);

        return response()->json($result, $result['replayed'] ? 200 : 202);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,100']]);

        return DB::transaction(function () use ($data) {
            $state = $this->fleet->state();

            return [
                ...$this->fleet->meta($state),
                'runs' => FleetRun::with('robots')->latest()->orderByDesc('id')->limit($data['limit'] ?? 20)->get(),
            ];
        });
    }

    public function show(string $id)
    {
        return DB::transaction(function () use ($id) {
            $state = $this->fleet->state();

            return [...$this->fleet->meta($state), 'run' => FleetRun::with('robots')->findOrFail($id)];
        });
    }

    public function events(Request $request, string $id)
    {
        $data = $request->validate(['after_id' => ['sometimes', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);

        return DB::transaction(function () use ($data, $id) {
            $state = $this->fleet->state();
            FleetRun::findOrFail($id);
            $events = RunEvent::where('run_id', $id)->where('id', '>', $data['after_id'] ?? 0)->orderBy('id')->limit($data['limit'] ?? 100)->get();

            return [...$this->fleet->meta($state), 'events' => $events, 'next_cursor' => $events->last()?->id ?? ($data['after_id'] ?? 0)];
        });
    }
}
