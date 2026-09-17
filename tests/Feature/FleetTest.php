<?php

namespace Tests\Feature;

use App\Models\FleetCommand;
use App\Models\FleetRun;
use App\Models\FleetState;
use App\Models\Robot;
use App\Models\RunEvent;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use RefreshDatabase;

    private string $agentToken = 'test-agent-token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fleet.mode', 'real');
        config()->set('fleet.agent_token', $this->agentToken);
        config()->set('fleet.map', [
            'id' => 'cbs-map',
            'version' => substr(hash_file('sha256', base_path('map/cbs_map.pgm')), 0, 12),
            'frame_id' => 'map',
        ]);
        foreach (['62b2' => 40, '648d' => 30, 'eed0' => 35] as $id => $domain) {
            config()->set("fleet.robots.{$id}", [
                'domain_id' => $domain,
                'initial_pose' => ['x_m' => null, 'y_m' => null, 'yaw_rad' => null],
                'goal_pose' => ['x_m' => 1.0 + $domain / 100, 'y_m' => -0.5, 'yaw_rad' => 0.0],
            ]);
        }
        $this->seed(FleetSeeder::class);
    }

    private function pose(string $version = ''): array
    {
        return [
            'map_id' => 'cbs-map',
            'map_version' => $version ?: config('fleet.map.version'),
            'frame_id' => 'map',
            'x_m' => 1.39,
            'y_m' => -0.49,
            'yaw_rad' => 0.02,
        ];
    }

    private function agent(string $robot, array $body)
    {
        $body = ['connected' => true, ...$body];

        return $this->postJson(
            "/api/agent/v1/robots/{$robot}/telemetry",
            $body,
            ['X-Fleet-Agent-Token' => $this->agentToken],
        );
    }

    private function command(string $path, array $body)
    {
        return $this->withSession(['_token' => 'test-csrf'])
            ->postJson('/api/v1/'.$path, $body, ['X-CSRF-TOKEN' => 'test-csrf']);
    }

    private function makeReady(string $robot = '62b2'): void
    {
        $this->agent($robot, ['motion_state' => 'idle', 'pose' => $this->pose()])
            ->assertOk()
            ->assertJsonPath('accepted', true);
    }

    private function start(string $robot = '62b2', ?string $requestId = null)
    {
        return $this->command("robots/{$robot}/start", [
            'request_id' => $requestId ?: (string) Str::uuid(),
        ]);
    }

    public function test_database_is_isolated_from_docker_mysql_environment(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', $_SERVER['DB_CONNECTION']);
        $this->assertSame('testing', app()->environment());
    }

    public function test_bootstrap_uses_real_map_fixed_goals_and_no_synthetic_positions(): void
    {
        $response = $this->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('mode', 'real')
            ->assertJsonPath('map.id', 'cbs-map')
            ->assertJsonPath('map.frame_id', 'map')
            ->assertJsonPath('map.width_px', 147)
            ->assertJsonPath('map.height_px', 207)
            ->assertJsonPath('robots.0.goal_configured', true);

        $this->assertSame(
            ['62b2' => 40, '648d' => 30, 'eed0' => 35],
            collect($response->json('robots'))->pluck('ros_domain_id', 'id')->all(),
        );
        $this->getJson('/api/v1/snapshot')
            ->assertOk()
            ->assertJsonPath('mode', 'real')
            ->assertJsonCount(3, 'robots')
            ->assertJsonPath('robots.0.connection_state', 'offline')
            ->assertJsonPath('robots.0.pose', null)
            ->assertJsonPath('active_run', null);
    }

    public function test_agent_requires_token_and_reports_actual_pose_and_time(): void
    {
        $this->postJson('/api/agent/v1/robots/62b2/telemetry', [
            'connected' => true,
            'motion_state' => 'idle',
            'pose' => $this->pose(),
        ])->assertUnauthorized()->assertJsonPath('error.code', 'AGENT_UNAUTHORIZED');

        $this->makeReady();
        $this->getJson('/api/v1/snapshot')
            ->assertJsonPath('robots.0.id', '62b2')
            ->assertJsonPath('robots.0.motion_state', 'idle')
            ->assertJsonPath('robots.0.pose.x_m', 1.39)
            ->assertJsonPath('robots.0.connection_state', 'online');
        $this->assertNotNull(Robot::findOrFail('62b2')->received_at);
    }

    public function test_demo_mode_populates_three_robots_and_generates_a_complete_run_timeline(): void
    {
        config()->set('fleet.mode', 'demo');
        $startedAt = Carbon::parse('2026-09-17 12:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $this->getJson('/api/v1/bootstrap')
                ->assertOk()
                ->assertJsonPath('mode', 'demo')
                ->assertJsonCount(3, 'robots')
                ->assertJsonPath('robots.0.goal_configured', true)
                ->assertJsonPath('robots.0.planned_path.0.map_id', 'cbs-map');

            $this->getJson('/api/v1/snapshot')
                ->assertOk()
                ->assertJsonPath('mode', 'demo')
                ->assertJsonPath('robots.0.connection_state', 'online')
                ->assertJsonPath('robots.1.connection_state', 'online')
                ->assertJsonPath('robots.2.connection_state', 'online');

            $runId = $this->start('62b2')
                ->assertCreated()
                ->assertJsonPath('run.mode', 'demo')
                ->assertJsonPath('run.robots.0.path_source', 'demo')
                ->json('run.id');

            Carbon::setTestNow($startedAt->copy()->addSeconds(2));
            $this->getJson('/api/v1/snapshot')
                ->assertJsonPath('active_run.status', 'running')
                ->assertJsonPath('robots.0.motion_state', 'moving');

            Carbon::setTestNow($startedAt->copy()->addSeconds(6));
            $this->getJson('/api/v1/snapshot')
                ->assertJsonPath('robots.0.motion_state', 'waiting');

            Carbon::setTestNow($startedAt->copy()->addSeconds(8));
            $this->getJson('/api/v1/snapshot')
                ->assertJsonPath('robots.0.motion_state', 'moving');

            Carbon::setTestNow($startedAt->copy()->addSeconds(13));
            $this->getJson('/api/v1/snapshot')
                ->assertJsonPath('active_run', null)
                ->assertJsonPath('robots.0.motion_state', 'arrived');

            $this->getJson('/api/v1/runs/'.$runId)
                ->assertJsonPath('run.status', 'completed')
                ->assertJsonPath('run.robots.0.result.source', 'demo')
                ->assertJsonPath('run.robots.0.result.outcome', 'succeeded');
            $this->assertEqualsCanonicalizing(
                ['arrived', 'moving', 'queued', 'resumed', 'waiting'],
                RunEvent::where('run_id', $runId)->pluck('type')->all(),
            );

            $this->command('demo/reset', [])
                ->assertOk()
                ->assertJsonPath('reset', true);
            $this->assertSame(0, FleetRun::where('mode', 'demo')->count());
            $this->assertSame(['idle'], Robot::pluck('motion_state')->unique()->values()->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_one_robot_run_finishes_only_after_nav2_success_with_arrival_pose(): void
    {
        $this->makeReady();
        $runId = $this->start()->assertCreated()
            ->assertJsonPath('run.mode', 'real')
            ->assertJsonPath('run.robots.0.robot_id', '62b2')
            ->assertJsonPath('run.robots.0.path_source', 'nav2')
            ->json('run.id');

        $this->withHeader('X-Fleet-Agent-Token', $this->agentToken)
            ->getJson('/api/agent/v1/robots/62b2/command')
            ->assertOk()
            ->assertJsonPath('action', 'start')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('goal_pose.frame_id', 'map');

        $this->agent('62b2', ['run_id' => $runId, 'motion_state' => 'moving', 'pose' => $this->pose()])
            ->assertOk();
        $this->assertSame('running', FleetRun::findOrFail($runId)->status);

        $this->agent('62b2', ['run_id' => $runId, 'motion_state' => 'idle', 'pose' => $this->pose()])
            ->assertOk();
        $this->assertSame('running', FleetRun::findOrFail($runId)->status);

        $this->agent('62b2', [
            'run_id' => $runId,
            'motion_state' => 'arrived',
            'action_result' => 'succeeded',
            'message' => 'Nav2 reported SUCCEEDED',
            'pose' => $this->pose(),
        ])->assertOk();

        $this->getJson('/api/v1/runs/'.$runId)
            ->assertOk()
            ->assertJsonPath('run.status', 'completed')
            ->assertJsonPath('run.robots.0.state', 'arrived')
            ->assertJsonPath('run.robots.0.result.source', 'nav2')
            ->assertJsonPath('run.robots.0.result.outcome', 'succeeded')
            ->assertJsonPath('run.robots.0.result.position_error_m', 0.0141);
        $this->assertNull(FleetState::findOrFail(1)->active_run_id);
        $this->assertSame(1, RunEvent::where('type', 'arrived')->count());
    }

    public function test_arrival_without_nav2_success_is_rejected_and_run_stays_active(): void
    {
        $this->makeReady();
        $runId = $this->start()->json('run.id');

        $this->agent('62b2', [
            'run_id' => $runId,
            'motion_state' => 'arrived',
            'pose' => $this->pose(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'ARRIVAL_NOT_VERIFIED');

        $this->assertSame($runId, FleetState::findOrFail(1)->active_run_id);
        $this->assertSame('queued', FleetRun::findOrFail($runId)->status);
    }

    public function test_only_one_robot_can_run_and_request_retry_is_idempotent(): void
    {
        $this->makeReady('62b2');
        $this->makeReady('648d');
        $requestId = (string) Str::uuid();
        $runId = $this->start('62b2', $requestId)->assertCreated()->json('run.id');
        $this->start('62b2', $requestId)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('run.id', $runId);
        $this->start('648d')->assertConflict()->assertJsonPath('error.code', 'ACTIVE_RUN');
        $this->assertSame(1, FleetRun::count());
        $this->assertSame(1, FleetCommand::count());
    }

    public function test_stop_acceptance_waits_for_nav2_cancellation_result(): void
    {
        $this->makeReady();
        $runId = $this->start()->json('run.id');
        $this->agent('62b2', ['run_id' => $runId, 'motion_state' => 'moving', 'pose' => $this->pose()]);

        $this->command('runs/'.$runId.'/stop', ['request_id' => (string) Str::uuid()])
            ->assertStatus(202)
            ->assertJsonPath('run.status', 'stopping')
            ->assertJsonPath('run.robots.0.stop_ack_at', null);
        $this->assertSame($runId, FleetState::findOrFail(1)->active_run_id);

        $this->withHeader('X-Fleet-Agent-Token', $this->agentToken)
            ->getJson('/api/agent/v1/robots/62b2/command')
            ->assertJsonPath('action', 'stop');

        $this->agent('62b2', [
            'run_id' => $runId,
            'motion_state' => 'stopped',
            'action_result' => 'canceled',
            'pose' => $this->pose(),
        ])->assertOk();
        $this->assertSame('cancelled', FleetRun::findOrFail($runId)->status);
        $this->assertNull(FleetState::findOrFail(1)->active_run_id);
    }

    public function test_unconfigured_goal_stale_robot_and_wrong_map_block_start(): void
    {
        config()->set('fleet.robots.62b2.goal_pose', ['x_m' => null, 'y_m' => null, 'yaw_rad' => null]);
        $this->start()->assertUnprocessable()->assertJsonPath('error.code', 'GOAL_NOT_CONFIGURED');

        config()->set('fleet.robots.62b2.goal_pose', ['x_m' => 1.4, 'y_m' => -0.5, 'yaw_rad' => 0.0]);
        $this->start()->assertConflict()->assertJsonPath('error.code', 'ROBOT_UNAVAILABLE');

        $this->agent('62b2', ['motion_state' => 'idle', 'pose' => $this->pose('old-map-version')]);
        $this->start()->assertConflict()->assertJsonPath('error.code', 'POSE_MISMATCH');

        Robot::findOrFail('62b2')->update(['received_at' => now()->subSeconds(12)]);
        $this->start()->assertConflict()->assertJsonPath('error.code', 'ROBOT_UNAVAILABLE');
    }

    public function test_browser_mutations_require_csrf_and_reject_other_origins(): void
    {
        $body = ['request_id' => (string) Str::uuid()];
        $this->postJson('/api/v1/robots/62b2/start', $body)
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'CSRF_MISMATCH');
        $this->withSession(['_token' => 'test-csrf'])->postJson('/api/v1/robots/62b2/start', $body, [
            'X-CSRF-TOKEN' => 'test-csrf',
            'Origin' => 'https://unrelated.example',
        ])->assertForbidden()->assertJsonPath('error.code', 'ORIGIN_MISMATCH');
    }

    public function test_unknown_robot_run_and_excessive_limits_return_bounded_errors(): void
    {
        $this->withHeader('X-Fleet-Agent-Token', $this->agentToken)
            ->getJson('/api/agent/v1/robots/not-a-robot/command')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ROBOT_NOT_FOUND');
        $this->getJson('/api/v1/runs/'.Str::uuid())->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/runs?limit=100000')->assertUnprocessable();
    }
}
