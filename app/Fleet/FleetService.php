<?php

namespace App\Fleet;

use App\Models\FleetCommand;
use App\Models\FleetRun;
use App\Models\FleetState;
use App\Models\Robot;
use App\Models\RunEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FleetService
{
    public function __construct(private SampleFixture $fixture) {}

    public function state(): FleetState
    {
        $state = FleetState::whereKey(1)->lockForUpdate()->first();
        if (! $state) {
            throw new FleetError('NOT_INITIALIZED', '샘플 데이터를 먼저 준비해 주세요.', 503);
        }
        if (config('fleet.mode') !== 'sample') {
            throw new FleetError('MODE_DISABLED', '현재 구현은 샘플 모드만 지원합니다.', 409);
        }

        return $state;
    }

    public function freshness($at): string
    {
        if (! $at) {
            return 'unknown';
        }
        $age = max(0, now()->getTimestamp() - $at->getTimestamp());
        if ($age > config('fleet.offline_seconds')) {
            return 'offline';
        }
        if ($age > config('fleet.stale_seconds')) {
            return 'stale';
        }

        return 'online';
    }

    public function meta(FleetState $state): array
    {
        return ['schema_version' => '1', 'mode' => 'sample', 'server_time' => now()->toISOString(),
            'snapshot_revision' => $state->revision];
    }

    public function snapshot(): array
    {
        return DB::transaction(function () {
            $state = $this->state();
            $robots = Robot::orderBy('id')->get()->map(fn ($r) => [
                ...$r->toArray(), 'connection_state' => $this->freshness($r->received_at),
                'active_run_id' => $state->active_run_id,
            ]);

            return [...$this->meta($state), 'robots' => $robots,
                'executor' => ['connection_state' => $this->freshness($state->heartbeat_at), 'received_at' => $state->heartbeat_at],
                'active_run' => $state->active_run_id ? FleetRun::with('robots')->findOrFail($state->active_run_id) : null,
            ];
        });
    }

    public function event(string $run, string $type, string $message, ?string $robot = null): void
    {
        RunEvent::create(['run_id' => $run, 'robot_id' => $robot, 'type' => $type,
            'message' => $message, 'mode' => 'sample', 'occurred_at' => now()]);
    }

    private function replay(string $requestId, string $hash): ?FleetCommand
    {
        $command = FleetCommand::where('request_id', $requestId)->first();
        if ($command && ! hash_equals($command->payload_hash, $hash)) {
            throw new FleetError('REQUEST_ID_CONFLICT', '같은 요청 ID에 다른 명령을 사용할 수 없습니다.');
        }

        return $command;
    }

    private function reply(FleetCommand $command, bool $replayed = false): array
    {
        return [...$this->meta(FleetState::findOrFail(1)), 'command' => $command,
            'run' => FleetRun::with('robots')->findOrFail($command->run_id), 'replayed' => $replayed];
    }

    public function create(array $data): array
    {
        $data['assignments'] = array_map(fn ($a) => ['robot_id' => $a['robot_id'], 'goal_id' => $a['goal_id']], $data['assignments']);
        usort($data['assignments'], fn ($a, $b) => strcmp($a['robot_id'], $b['robot_id']));
        $hash = hash('sha256', json_encode(['start', $data['map_id'], $data['map_version'], $data['assignments']]));

        return DB::transaction(function () use ($data, $hash) {
            $state = $this->state();
            if ($command = $this->replay($data['request_id'], $hash)) {
                return $this->reply($command, true);
            }
            if ($state->active_run_id) {
                throw new FleetError('ACTIVE_RUN', '먼저 현재 작업을 종료해 주세요.');
            }
            if ($this->freshness($state->heartbeat_at) !== 'online') {
                throw new FleetError('EXECUTOR_UNAVAILABLE', '샘플 실행기의 상태가 최신이 아닙니다.');
            }
            if ($data['map_id'] !== 'sample-map' || $data['map_version'] !== '1') {
                throw new FleetError('MAP_MISMATCH', '지도 버전이 맞지 않습니다.', 422);
            }
            $goals = collect($this->fixture->goals())->keyBy('id');
            $robots = Robot::all()->keyBy('id');
            $prepared = [];
            $sides = [];
            foreach ($data['assignments'] as $assignment) {
                $id = $assignment['robot_id'];
                $goal = $goals->get($assignment['goal_id']);
                $robot = $robots->get($id);
                if (! $robot || ! $goal || $goal['robot_id'] !== $id) {
                    throw new FleetError('INVALID_ASSIGNMENT', '로봇과 고정 목표의 조합을 확인해 주세요.', 422);
                }
                if ($this->freshness($robot->received_at) !== 'online' || ! $robot->pose) {
                    throw new FleetError('ROBOT_UNAVAILABLE', "$id 상태와 위치를 확인해 주세요.");
                }
                $sides[] = str_ends_with($goal['id'], '-right') ? 'right' : 'left';
                $prepared[] = ['robot_id' => $id, 'goal_id' => $goal['id'], 'goal_pose' => $goal['pose'],
                    'planned_path' => $this->fixture->path($id, $robot->pose, $goal['pose']),
                    'state' => 'pending', 'path_source' => 'fixture'];
            }
            if (count(array_unique($sides)) !== 1) {
                throw new FleetError('SCENARIO_UNAVAILABLE', '샘플은 세 로봇 모두 동쪽 또는 모두 서쪽인 조합을 지원합니다.', 422);
            }
            $run = FleetRun::create(['id' => (string) Str::uuid(), 'mode' => 'sample',
                'map_id' => $data['map_id'], 'map_version' => $data['map_version'], 'status' => 'queued']);
            $run->robots()->createMany($prepared);
            $command = FleetCommand::create(['id' => (string) Str::uuid(), 'request_id' => $data['request_id'],
                'run_id' => $run->id, 'type' => 'start', 'payload_hash' => $hash, 'status' => 'accepted', 'created_at' => now()]);
            $state->active_run_id = $run->id;
            $state->revision++;
            $state->save();
            $this->event($run->id, 'queued', '샘플 작업 접수 · 아직 출발하지 않음');

            return $this->reply($command);
        }, 3);
    }

    public function stop(string $runId, string $requestId): array
    {
        $hash = hash('sha256', json_encode(['stop', $runId]));

        return DB::transaction(function () use ($runId, $requestId, $hash) {
            $state = $this->state();
            if ($command = $this->replay($requestId, $hash)) {
                return $this->reply($command, true);
            }
            $run = FleetRun::findOrFail($runId);
            if ($state->active_run_id !== $runId) {
                throw new FleetError('RUN_FINISHED', '이미 종료된 작업입니다.');
            }
            $command = FleetCommand::create(['id' => (string) Str::uuid(), 'request_id' => $requestId,
                'run_id' => $runId, 'type' => 'stop', 'payload_hash' => $hash, 'status' => 'accepted', 'created_at' => now()]);
            if ($run->status !== 'stopping') {
                $run->update(['status' => 'stopping']);
                $this->event($runId, 'stop_requested', '전체 정지 요청 접수 · 실행기 확인 대기');
            }
            $state->revision++;
            $state->save();

            return $this->reply($command);
        }, 3);
    }

    public function recover(): void
    {
        DB::transaction(function () {
            $state = $this->state();
            if ($state->active_run_id) {
                $run = FleetRun::findOrFail($state->active_run_id);
                if ($run->status !== 'interrupted') {
                    $run->update(['status' => 'interrupted', 'error' => ['code' => 'EXECUTOR_RESTART', 'message' => '실행기가 재시작되었습니다. 정지 요청으로 작업을 종료해 주세요.']]);
                    $this->event($run->id, 'interrupted', '실행기 재시작 · 자동 재개하지 않음');
                }
                Robot::whereNotIn('motion_state', ['arrived', 'stopped'])->update(['motion_state' => 'unknown']);
            }
            $state->heartbeat_at = now();
            $state->revision++;
            $state->save();
        });
    }

    public function tick(): void
    {
        DB::transaction(function () {
            $state = $this->state();
            // A long pause is not permission to jump ahead or silently resume.
            if ($state->active_run_id && $state->heartbeat_at && $this->freshness($state->heartbeat_at) !== 'online') {
                $run = FleetRun::findOrFail($state->active_run_id);
                if (in_array($run->status, ['queued', 'running'])) {
                    $run->update(['status' => 'interrupted', 'error' => ['code' => 'EXECUTOR_DELAY', 'message' => '실행기 갱신 지연. 정지 요청으로 종료해 주세요.']]);
                    Robot::whereNotIn('motion_state', ['arrived', 'stopped'])->update(['motion_state' => 'unknown']);
                    $this->event($run->id, 'interrupted', '실행 지연 · 자동 진행 보류');
                }
            }
            $state->heartbeat_at = now();
            Robot::query()->update(['received_at' => now()]);
            if ($state->active_run_id) {
                $this->advance(FleetRun::with('robots')->findOrFail($state->active_run_id), $state);
            }
            $state->revision++;
            $state->save();
        }, 3);
    }

    private function advance(FleetRun $run, FleetState $state): void
    {
        if ($run->status === 'interrupted') {
            return;
        }
        if ($run->status === 'stopping') {
            foreach ($run->robots as $entry) {
                if ($entry->state === 'arrived') {
                    continue;
                }
                $entry->update(['state' => 'stopped', 'stop_ack_at' => now(),
                    'result' => ['source' => 'sample', 'outcome' => 'stopped']]);
                Robot::whereKey($entry->robot_id)->update(['motion_state' => 'stopped']);
                $this->event($run->id, 'stopped', '샘플 정지 확인', $entry->robot_id);
            }
            $this->finish($run, $state, 'cancelled');

            return;
        }
        if ($run->status === 'queued') {
            $run->status = 'running';
            $run->started_at = now();
            FleetCommand::where('run_id', $run->id)->where('type', 'start')->update(['status' => 'acknowledged']);
            $this->event($run->id, 'running', '샘플 경로 재생 시작');
        }
        $allArrived = true;
        foreach ($run->robots as $entry) {
            $path = $entry->planned_path;
            $index = min($run->tick, count($path) - 1);
            $point = $path[$index];
            $next = $index === count($path) - 1 ? 'arrived' : ($point['wait_s'] > 0 ? 'waiting' : 'moving');
            if ($next !== 'arrived') {
                $allArrived = false;
            }
            if ($entry->state !== $next) {
                $this->event($run->id, $next, ['moving' => '샘플 이동', 'waiting' => '시나리오에 지정된 대기', 'arrived' => '샘플 목표 도착 확인'][$next], $entry->robot_id);
            }
            $entry->state = $next;
            if ($next === 'arrived') {
                $entry->result = ['source' => 'sample', 'outcome' => 'arrived'];
            }
            $entry->save();
            $pose = array_intersect_key($point, array_flip(['map_id', 'map_version', 'frame_id', 'x_m', 'y_m', 'yaw_rad']));
            Robot::whereKey($entry->robot_id)->firstOrFail()->update(['pose' => $pose, 'motion_state' => $next]);
        }
        $run->tick++;
        $run->save();
        if ($allArrived) {
            $this->finish($run, $state, 'completed');
        }
    }

    private function finish(FleetRun $run, FleetState $state, string $status): void
    {
        $run->update(['status' => $status, 'finished_at' => now()]);
        FleetCommand::where('run_id', $run->id)->where('status', 'accepted')->update(['status' => 'acknowledged']);
        $state->active_run_id = null;
        $this->event($run->id, $status, $status === 'completed' ? '세 로봇 샘플 도착 완료' : '전체 샘플 정지 확인 · 작업 종료');
    }
}
