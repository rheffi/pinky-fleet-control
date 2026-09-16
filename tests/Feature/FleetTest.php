<?php

namespace Tests\Feature;

use App\Fleet\FleetService;
use App\Models\FleetCommand;
use App\Models\FleetRun;
use App\Models\FleetState;
use App\Models\Robot;
use App\Models\RunEvent;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use RefreshDatabase;

    private FleetService $fleet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FleetSeeder::class);
        $this->fleet = app(FleetService::class);
        $this->fleet->tick();
    }

    private function payload(string $side = 'right'): array
    {
        return ['request_id' => (string) Str::uuid(), 'map_id' => 'sample-map', 'map_version' => '1',
            'assignments' => array_map(fn ($id) => ['robot_id' => $id, 'goal_id' => "$id-$side"], ['eed0', '648d', '62b2'])];
    }

    public function test_database_is_isolated_from_docker_mysql_environment(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', $_SERVER['DB_CONNECTION']);
        $this->assertSame('testing', app()->environment());
    }

    private function command(string $path, array $body)
    {
        return $this->withSession(['_token' => 'test-csrf'])->postJson('/api/v1/'.$path, $body, ['X-CSRF-TOKEN' => 'test-csrf']);
    }

    public function test_bootstrap_and_snapshot_have_exact_robot_identity_and_no_physical_connection(): void
    {
        $response = $this->getJson('/api/v1/bootstrap')->assertOk()->assertJsonPath('mode', 'sample');
        $this->assertSame(['62b2' => 40, '648d' => 30, 'eed0' => 35], collect($response->json('robots'))->pluck('ros_domain_id', 'id')->all());
        $this->getJson('/api/v1/snapshot')->assertOk()->assertJsonCount(3, 'robots')
            ->assertJsonPath('executor.connection_state', 'online')->assertJsonPath('active_run', null);
    }

    public function test_run_completes_only_after_all_sample_arrivals_with_events(): void
    {
        $run = $this->command('runs', $this->payload())->assertCreated()->assertJsonPath('run.status', 'queued')->json('run.id');
        $this->assertSame(1, RunEvent::count());
        $this->fleet->tick();
        $this->assertSame('running', FleetRun::findOrFail($run)->status);
        for ($i = 0; $i < 30; $i++) {
            $this->fleet->tick();
        }
        $this->getJson('/api/v1/runs/'.$run)->assertOk()->assertJsonPath('run.status', 'completed')
            ->assertJsonPath('run.robots.0.result.source', 'sample');
        $this->assertNull(FleetState::find(1)->active_run_id);
        $this->assertSame(3, RunEvent::where('type', 'arrived')->count());
        $this->assertGreaterThan(0, RunEvent::where('type', 'waiting')->count());
        $this->assertCount(3, Robot::where('motion_state', 'arrived')->get());
        $first = $this->getJson('/api/v1/runs/'.$run.'/events?limit=2')->assertOk()->assertJsonCount(2, 'events');
        $next = $this->getJson('/api/v1/runs/'.$run.'/events?after_id='.$first->json('next_cursor'))->assertOk();
        $this->assertGreaterThan($first->json('next_cursor'), $next->json('events.0.id'));
    }

    public function test_stop_acceptance_is_not_stop_acknowledgement(): void
    {
        $run = $this->command('runs', $this->payload())->json('run.id');
        $this->fleet->tick();
        $stop = ['request_id' => (string) Str::uuid()];
        $this->command('runs/'.$run.'/stop', $stop)->assertStatus(202)->assertJsonPath('run.status', 'stopping')
            ->assertJsonPath('run.robots.0.stop_ack_at', null);
        $this->command('runs/'.$run.'/stop', $stop)->assertOk()->assertJsonPath('replayed', true);
        $this->fleet->tick();
        $this->getJson('/api/v1/runs/'.$run)->assertJsonPath('run.status', 'cancelled')
            ->assertJsonPath('run.robots.0.state', 'stopped');
        $this->assertSame(3, RunEvent::where('type', 'stopped')->count());
        $this->command('runs/'.$run.'/stop', ['request_id' => (string) Str::uuid()])->assertStatus(409);
    }

    public function test_request_retries_are_idempotent_and_active_run_is_exclusive(): void
    {
        $body = $this->payload();
        $id = $this->command('runs', $body)->assertCreated()->json('run.id');
        $body['assignments'] = array_reverse($body['assignments']);
        $this->command('runs', $body)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('run.id', $id);
        $this->command('runs', $this->payload())->assertStatus(409)->assertJsonPath('error.code', 'ACTIVE_RUN');
        $body['map_version'] = 'different';
        $this->command('runs', $body)->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_ID_CONFLICT');
        $this->assertSame(1, FleetRun::count());
        $this->assertSame(1, FleetCommand::count());
    }

    public function test_invalid_assignments_and_map_are_rejected_without_writes(): void
    {
        $body = $this->payload();
        $body['assignments'][1]['robot_id'] = 'eed0';
        $this->command('runs', $body)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $body = $this->payload();
        array_pop($body['assignments']);
        $this->command('runs', $body)->assertUnprocessable();
        $body = $this->payload();
        $body['map_version'] = '2';
        $this->command('runs', $body)->assertUnprocessable()->assertJsonPath('error.code', 'MAP_MISMATCH');
        $body = $this->payload();
        $body['assignments'][0]['goal_id'] = 'missing';
        $this->command('runs', $body)->assertUnprocessable();
        $body = $this->payload();
        $body['assignments'][0]['goal_id'] = 'eed0-left';
        $this->command('runs', $body)->assertUnprocessable()->assertJsonPath('error.code', 'SCENARIO_UNAVAILABLE');
        $this->assertSame(0, FleetRun::count());
    }

    public function test_stale_status_blocks_start_and_get_never_advances_or_changes_state(): void
    {
        FleetState::find(1)->update(['heartbeat_at' => now()->subSeconds(12)]);
        Robot::query()->update(['received_at' => now()->subSeconds(12), 'motion_state' => 'moving']);
        $before = FleetState::find(1)->toArray();
        $this->getJson('/api/v1/snapshot')->assertJsonPath('executor.connection_state', 'offline')
            ->assertJsonPath('robots.0.motion_state', 'moving')->assertJsonPath('robots.0.connection_state', 'offline');
        $this->assertSame($before, FleetState::find(1)->toArray());
        $this->command('runs', $this->payload())->assertStatus(409)->assertJsonPath('error.code', 'EXECUTOR_UNAVAILABLE');
    }

    public function test_restart_interrupts_without_moving_then_allows_stop_resolution(): void
    {
        $run = $this->command('runs', $this->payload())->json('run.id');
        $this->fleet->tick();
        $positions = Robot::orderBy('id')->get()->pluck('pose')->all();
        $this->fleet->recover();
        $this->fleet->tick();
        $this->getJson('/api/v1/runs/'.$run)->assertJsonPath('run.status', 'interrupted');
        $this->assertSame($positions, Robot::orderBy('id')->get()->pluck('pose')->all());
        $this->command('runs', $this->payload())->assertStatus(409);
        $this->command('runs/'.$run.'/stop', ['request_id' => (string) Str::uuid()])->assertStatus(202);
        $this->fleet->tick();
        $this->assertSame('cancelled', FleetRun::find($run)->status);
    }

    public function test_paused_executor_does_not_jump_ahead(): void
    {
        $run = $this->command('runs', $this->payload())->json('run.id');
        FleetState::find(1)->update(['heartbeat_at' => now()->subSeconds(5)]);
        $this->fleet->tick();
        $this->assertSame('interrupted', FleetRun::find($run)->status);
        $this->assertSame(0, FleetRun::find($run)->tick);
    }

    public function test_unknown_pose_or_different_frame_is_rejected(): void
    {
        Robot::find('eed0')->update(['pose' => null]);
        $this->command('runs', $this->payload())->assertStatus(409)->assertJsonPath('error.code', 'ROBOT_UNAVAILABLE');
        Robot::find('eed0')->update(['pose' => ['map_id' => 'sample-map', 'map_version' => '2', 'frame_id' => 'sample_map', 'x_m' => 0.6, 'y_m' => 0.7, 'yaw_rad' => 0]]);
        $this->command('runs', $this->payload())->assertStatus(409)->assertJsonPath('error.code', 'POSE_MISMATCH');
    }

    public function test_seed_preserves_existing_state_and_history(): void
    {
        $run = $this->command('runs', $this->payload())->json('run.id');
        $this->fleet->tick();
        $before = Robot::orderBy('id')->get()->toArray();
        $this->seed(FleetSeeder::class);
        $this->assertSame($before, Robot::orderBy('id')->get()->toArray());
        $this->assertSame($run, FleetState::find(1)->active_run_id);
        $this->assertSame(1, FleetRun::count());
    }

    public function test_mutations_require_csrf_and_reject_other_origins(): void
    {
        $this->postJson('/api/v1/runs', $this->payload())->assertStatus(419)->assertJsonPath('error.code', 'CSRF_MISMATCH');
        $this->withSession(['_token' => 'test-csrf'])->postJson('/api/v1/runs', $this->payload(), [
            'X-CSRF-TOKEN' => 'test-csrf', 'Origin' => 'https://unrelated.example',
        ])->assertForbidden()->assertJsonPath('error.code', 'ORIGIN_MISMATCH');
        $this->assertSame(0, FleetRun::count());
    }

    public function test_unknown_run_and_excessive_limits_return_bounded_errors(): void
    {
        $this->getJson('/api/v1/runs/'.Str::uuid())->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/runs?limit=100000')->assertUnprocessable();
    }
}
