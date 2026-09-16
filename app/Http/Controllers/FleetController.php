<?php

namespace App\Http\Controllers;

use App\Fleet\FleetService;
use App\Fleet\SampleFixture;
use App\Models\FleetRun;
use App\Models\Robot;
use App\Models\RunEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FleetController extends Controller
{
    public function __construct(private FleetService $fleet, private SampleFixture $fixture) {}

    public function bootstrap()
    {
        return DB::transaction(function () {
            $state = $this->fleet->state();

            return [...$this->fleet->meta($state), 'robots' => Robot::orderBy('id')->get(['id', 'label', 'ros_domain_id']),
                'map' => $this->fixture->map(), 'goals' => $this->fixture->goals(),
                'settings' => ['poll_ms' => 1000, 'stale_seconds' => config('fleet.stale_seconds'), 'offline_seconds' => config('fleet.offline_seconds')],
                'scenarios' => [['id' => 'right', 'label' => '동쪽 목표로'], ['id' => 'left', 'label' => '서쪽 목표로']]];
        });
    }

    public function snapshot()
    {
        return $this->fleet->snapshot();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'map_id' => ['required', 'string', 'max:100'],
            'map_version' => ['required', 'string', 'max:40'], 'assignments' => ['required', 'array', 'size:3'],
            'assignments.*' => ['required', 'array:robot_id,goal_id'],
            'assignments.*.robot_id' => ['required', 'distinct:strict', Rule::in(array_keys(SampleFixture::ROBOTS))],
            'assignments.*.goal_id' => ['required', 'string', 'max:100', 'distinct:strict'],
        ]);
        $result = $this->fleet->create($data);

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

            return [...$this->fleet->meta($state), 'runs' => FleetRun::latest()->orderByDesc('id')->limit($data['limit'] ?? 20)->get()];
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

        return DB::transaction(function () use ($id, $data) {
            $state = $this->fleet->state();
            FleetRun::findOrFail($id);
            $events = RunEvent::where('run_id', $id)->where('id', '>', $data['after_id'] ?? 0)->orderBy('id')->limit($data['limit'] ?? 100)->get();

            return [...$this->fleet->meta($state), 'events' => $events, 'next_cursor' => $events->last()?->id ?? ($data['after_id'] ?? 0)];
        });
    }
}
