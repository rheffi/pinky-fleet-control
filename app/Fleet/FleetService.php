<?php

namespace App\Fleet;

use App\Models\FleetCommand;
use App\Models\FleetRun;
use App\Models\FleetState;
use App\Models\Robot;
use App\Models\RunEvent;
use App\Models\RunRobot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FleetService
{
    public function __construct(private DemoFleet $demo) {}

    public function state(): FleetState
    {
        $state = FleetState::whereKey(1)->lockForUpdate()->first();
        if (! $state) {
            throw new FleetError('NOT_INITIALIZED', '관제 데이터를 먼저 준비해 주세요.', 503);
        }

        return $state;
    }

    public function freshness($at): string
    {
        if (! $at) {
            return 'offline';
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
        return [
            'schema_version' => '2',
            'mode' => config('fleet.mode'),
            'server_time' => now()->toISOString(),
            'snapshot_revision' => $state->revision,
        ];
    }

    public function goalPose(string $robotId): ?array
    {
        if ($this->demo->enabled()) {
            return $this->demo->goalPose($robotId);
        }

        $goal = config("fleet.robots.{$robotId}.goal_pose");
        if (! is_array($goal) || in_array(null, $goal, true)) {
            return null;
        }

        return [
            'map_id' => config('fleet.map.id'),
            'map_version' => config('fleet.map.version'),
            'frame_id' => config('fleet.map.frame_id'),
            'x_m' => $goal['x_m'],
            'y_m' => $goal['y_m'],
            'yaw_rad' => $goal['yaw_rad'],
        ];
    }

    public function initialPose(string $robotId): ?array
    {
        if ($this->demo->enabled()) {
            return $this->demo->initialPose($robotId);
        }

        $pose = config("fleet.robots.{$robotId}.initial_pose");
        if (! is_array($pose) || in_array(null, $pose, true)) {
            return null;
        }

        return [
            'map_id' => config('fleet.map.id'),
            'map_version' => config('fleet.map.version'),
            'frame_id' => config('fleet.map.frame_id'),
            ...$pose,
        ];
    }

    public function configuredRobots(): array
    {
        return collect(config('fleet.robots'))->map(function (array $settings, string $id) {
            $robot = Robot::find($id);

            return [
                'id' => $id,
                'label' => $robot?->label ?? 'Pinky '.$id,
                'ros_domain_id' => $settings['domain_id'],
                'initial_pose' => $this->initialPose($id),
                'goal_pose' => $this->goalPose($id),
                'goal_configured' => $this->goalPose($id) !== null,
                'planned_path' => $this->demo->enabled() ? $this->demo->plannedPath($id) : [],
            ];
        })->values()->all();
    }

    public function snapshot(): array
    {
        return DB::transaction(function () {
            $state = $this->state();
            $this->demo->advance($state);
            $state->refresh();
            $robots = Robot::orderBy('id')->get()->map(fn (Robot $robot) => [
                ...$robot->toArray(),
                'connection_state' => $this->freshness($robot->received_at),
                'active_run_id' => $state->active_run_id,
                'goal_pose' => $this->goalPose($robot->id),
                'goal_configured' => $this->goalPose($robot->id) !== null,
            ]);

            return [
                ...$this->meta($state),
                'robots' => $robots,
                'active_run' => $state->active_run_id
                    ? FleetRun::with('robots')->findOrFail($state->active_run_id)
                    : null,
            ];
        });
    }

    public function resetDemo(): array
    {
        $result = $this->demo->reset();

        return [...$this->meta(FleetState::findOrFail(1)), ...$result];
    }

    public function start(string $robotId, string $requestId): array
    {
        if (! array_key_exists($robotId, config('fleet.robots'))) {
            throw new FleetError('ROBOT_NOT_FOUND', '등록되지 않은 로봇입니다.', 404);
        }
        $goal = $this->goalPose($robotId);
        if (! $goal) {
            throw new FleetError('GOAL_NOT_CONFIGURED', "{$robotId} 목표 좌표를 먼저 설정해 주세요.", 422);
        }

        $hash = hash('sha256', json_encode(['start', $robotId, $goal], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($robotId, $requestId, $goal, $hash) {
            $state = $this->state();
            if ($command = $this->replay($requestId, $hash)) {
                return $this->reply($command, true);
            }
            if ($state->active_run_id) {
                throw new FleetError('ACTIVE_RUN', '다른 로봇의 주행이 끝난 뒤 시작해 주세요.');
            }

            $robot = Robot::whereKey($robotId)->lockForUpdate()->first();
            if (! $robot || $this->freshness($robot->received_at) !== 'online' || ! $robot->pose) {
                throw new FleetError('ROBOT_UNAVAILABLE', "{$robotId} 연결과 현재 위치를 확인해 주세요.");
            }
            if (! $this->poseMatchesMap($robot->pose)) {
                throw new FleetError('POSE_MISMATCH', "{$robotId} 위치와 관제 지도의 버전 또는 좌표계가 다릅니다.");
            }

            $runId = (string) Str::uuid();
            $run = FleetRun::create([
                'id' => $runId,
                'mode' => config('fleet.mode'),
                'map_id' => config('fleet.map.id'),
                'map_version' => config('fleet.map.version'),
                'status' => 'queued',
            ]);
            $run->robots()->create([
                'robot_id' => $robotId,
                'goal_id' => $robotId.'-fixed',
                'goal_pose' => $goal,
                'planned_path' => $this->demo->enabled() ? $this->demo->plannedPath($robotId) : [],
                'path_source' => $this->demo->enabled() ? 'demo' : 'nav2',
                'state' => 'pending',
            ]);
            $command = FleetCommand::create([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'run_id' => $runId,
                'type' => 'start',
                'payload_hash' => $hash,
                'status' => 'accepted',
                'created_at' => now(),
            ]);
            $state->active_run_id = $runId;
            $state->revision++;
            $state->save();
            $this->event($runId, 'queued', '주행 명령 접수 · 로봇 실행기 전달 대기', $robotId);

            return $this->reply($command);
        }, 3);
    }

    public function stop(string $runId, string $requestId): array
    {
        $hash = hash('sha256', json_encode(['stop', $runId], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($runId, $requestId, $hash) {
            $state = $this->state();
            if ($command = $this->replay($requestId, $hash)) {
                return $this->reply($command, true);
            }

            $run = FleetRun::with('robots')->findOrFail($runId);
            if ($state->active_run_id !== $runId || ! in_array($run->status, ['queued', 'running'], true)) {
                throw new FleetError('RUN_FINISHED', '이미 종료된 작업입니다.');
            }

            $command = FleetCommand::create([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'run_id' => $runId,
                'type' => 'stop',
                'payload_hash' => $hash,
                'status' => 'accepted',
                'created_at' => now(),
            ]);
            $run->update(['status' => 'stopping']);
            $state->revision++;
            $state->save();
            $this->event($runId, 'stop_requested', '정지 요청 접수 · Nav2 취소 결과 대기', $run->robots->first()?->robot_id);

            return $this->reply($command);
        }, 3);
    }

    public function commandFor(string $robotId): array
    {
        $this->assertRobotId($robotId);

        if ($this->demo->enabled()) {
            return ['action' => 'none', 'mode' => 'demo'];
        }

        return DB::transaction(function () use ($robotId) {
            $state = FleetState::findOrFail(1);
            if (! $state->active_run_id) {
                return ['action' => 'none'];
            }

            $run = FleetRun::with('robots')->findOrFail($state->active_run_id);
            $entry = $run->robots->firstWhere('robot_id', $robotId);
            if (! $entry || in_array($entry->state, ['arrived', 'failed', 'stopped'], true)) {
                return ['action' => 'none'];
            }
            if ($run->status === 'stopping') {
                return ['action' => 'stop', 'run_id' => $run->id];
            }
            if (in_array($run->status, ['queued', 'running'], true)) {
                return ['action' => 'start', 'run_id' => $run->id, 'goal_pose' => $entry->goal_pose];
            }

            return ['action' => 'none'];
        });
    }

    public function telemetry(string $robotId, array $data): array
    {
        $this->assertRobotId($robotId);

        if ($this->demo->enabled()) {
            return [
                ...$this->meta(FleetState::findOrFail(1)),
                'accepted' => false,
                'ignored' => 'demo_mode',
            ];
        }

        return DB::transaction(function () use ($robotId, $data) {
            $state = $this->state();
            $robot = Robot::whereKey($robotId)->lockForUpdate()->firstOrFail();
            $pose = $data['pose'] ?? null;
            $motionState = $data['motion_state'];

            $robot->motion_state = $motionState;
            if ($data['connected']) {
                $robot->received_at = now();
            }
            if ($data['connected'] && $pose !== null) {
                $robot->pose = $pose;
            }
            $robot->save();

            $state->heartbeat_at = now();
            $state->revision++;

            $runId = $data['run_id'] ?? null;
            if ($runId && $state->active_run_id === $runId) {
                $run = FleetRun::with('robots')->whereKey($runId)->lockForUpdate()->firstOrFail();
                $entry = $run->robots->firstWhere('robot_id', $robotId);
                if ($entry) {
                    $this->applyTelemetryToRun(
                        $run,
                        $entry,
                        $state,
                        $motionState,
                        $data['action_result'] ?? null,
                        $pose,
                        $data['message'] ?? null,
                    );
                }
            }

            $state->save();

            return [...$this->meta($state), 'accepted' => true];
        }, 3);
    }

    private function applyTelemetryToRun(
        FleetRun $run,
        RunRobot $entry,
        FleetState $state,
        string $motionState,
        ?string $actionResult,
        ?array $pose,
        ?string $message,
    ): void {
        if ($motionState === 'moving' && $entry->state === 'pending') {
            $entry->update(['state' => 'moving']);
            $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
            FleetCommand::where('run_id', $run->id)->where('type', 'start')->update(['status' => 'dispatched']);
            $this->event($run->id, 'moving', 'Nav2 목표 수락 · 이동 시작', $entry->robot_id);

            return;
        }

        if ($motionState === 'arrived') {
            if ($actionResult !== 'succeeded' || ! $pose) {
                throw new FleetError('ARRIVAL_NOT_VERIFIED', 'Nav2 성공 결과와 실제 도착 위치가 모두 필요합니다.', 422);
            }
            if ($entry->state === 'arrived') {
                return;
            }
            $result = [
                'source' => 'nav2',
                'outcome' => 'succeeded',
                'pose' => $pose,
                'position_error_m' => round(hypot($pose['x_m'] - $entry->goal_pose['x_m'], $pose['y_m'] - $entry->goal_pose['y_m']), 4),
                'yaw_error_rad' => round(abs($this->normalizeAngle($pose['yaw_rad'] - $entry->goal_pose['yaw_rad'])), 4),
                'message' => $message,
                'received_at' => now()->toISOString(),
            ];
            $entry->update(['state' => 'arrived', 'result' => $result]);
            $run->update(['status' => 'completed', 'finished_at' => now()]);
            FleetCommand::where('run_id', $run->id)->update(['status' => 'completed']);
            $state->active_run_id = null;
            $this->event($run->id, 'arrived', 'Nav2 성공 및 실제 도착 위치 확인', $entry->robot_id);

            return;
        }

        if ($motionState === 'stopped' && $actionResult === 'canceled' && $run->status === 'stopping') {
            if ($entry->state !== 'stopped') {
                $entry->update([
                    'state' => 'stopped',
                    'stop_ack_at' => now(),
                    'result' => [
                        'source' => 'nav2',
                        'outcome' => 'canceled',
                        'pose' => $pose,
                        'message' => $message,
                        'received_at' => now()->toISOString(),
                    ],
                ]);
                $run->update(['status' => 'cancelled', 'finished_at' => now()]);
                FleetCommand::where('run_id', $run->id)->update(['status' => 'completed']);
                $state->active_run_id = null;
                $this->event($run->id, 'stopped', 'Nav2 목표 취소 확인', $entry->robot_id);
            }

            return;
        }

        if ($motionState === 'failed' && in_array($actionResult, ['aborted', 'rejected', 'failed'], true)) {
            if ($entry->state !== 'failed') {
                $result = [
                    'source' => 'nav2',
                    'outcome' => $actionResult,
                    'pose' => $pose,
                    'message' => $message,
                    'received_at' => now()->toISOString(),
                ];
                $entry->update(['state' => 'failed', 'result' => $result]);
                $run->update([
                    'status' => 'failed',
                    'error' => ['code' => 'NAV2_FAILED', 'message' => $message ?: 'Nav2 주행에 실패했습니다.'],
                    'finished_at' => now(),
                ]);
                FleetCommand::where('run_id', $run->id)->update(['status' => 'failed']);
                $state->active_run_id = null;
                $this->event($run->id, 'failed', $message ?: 'Nav2 주행 실패', $entry->robot_id);
            }
        }
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
        return [
            ...$this->meta(FleetState::findOrFail(1)),
            'command' => $command,
            'run' => FleetRun::with('robots')->findOrFail($command->run_id),
            'replayed' => $replayed,
        ];
    }

    private function event(string $run, string $type, string $message, ?string $robot = null): void
    {
        RunEvent::create([
            'run_id' => $run,
            'robot_id' => $robot,
            'type' => $type,
            'message' => $message,
            'mode' => config('fleet.mode'),
            'occurred_at' => now(),
        ]);
    }

    private function poseMatchesMap(array $pose): bool
    {
        return ($pose['map_id'] ?? null) === config('fleet.map.id')
            && ($pose['map_version'] ?? null) === config('fleet.map.version')
            && ($pose['frame_id'] ?? null) === config('fleet.map.frame_id');
    }

    private function assertRobotId(string $robotId): void
    {
        if (! array_key_exists($robotId, config('fleet.robots'))) {
            throw new FleetError('ROBOT_NOT_FOUND', '등록되지 않은 로봇입니다.', 404);
        }
    }

    private function normalizeAngle(float $angle): float
    {
        return atan2(sin($angle), cos($angle));
    }
}
