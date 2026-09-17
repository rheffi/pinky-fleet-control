<?php

namespace App\Fleet;

use App\Models\FleetCommand;
use App\Models\FleetRun;
use App\Models\FleetState;
use App\Models\Robot;
use App\Models\RunEvent;
use App\Models\RunRobot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemoFleet
{
    public function __construct(private DisplayMap $displayMap) {}

    public function enabled(): bool
    {
        return config('fleet.mode') === 'demo';
    }

    public function initialPose(string $robotId): ?array
    {
        return $this->route($robotId)->first();
    }

    public function goalPose(string $robotId): ?array
    {
        return $this->route($robotId)->last();
    }

    public function plannedPath(string $robotId): array
    {
        return $this->route($robotId)->values()->all();
    }

    public function prepare(FleetState $state): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach (config('fleet.robots') as $id => $settings) {
            $robot = Robot::firstOrNew(['id' => $id]);
            $robot->label = 'Pinky '.$id;
            $robot->ros_domain_id = $settings['domain_id'];
            if (! $robot->pose || ! $this->poseMatchesMap($robot->pose)) {
                $robot->pose = $this->initialPose($id);
            }
            if (! $robot->exists || $robot->motion_state === 'unknown') {
                $robot->motion_state = 'idle';
            }
            $robot->received_at = now();
            $robot->save();
        }

        $state->heartbeat_at = now();
        $state->save();
    }

    public function advance(FleetState $state): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->prepare($state);
        if (! $state->active_run_id) {
            return;
        }

        $run = FleetRun::with('robots')->whereKey($state->active_run_id)->lockForUpdate()->first();
        if (! $run || $run->mode !== 'demo') {
            return;
        }

        $entry = $run->robots->first();
        $robot = $entry ? Robot::whereKey($entry->robot_id)->lockForUpdate()->first() : null;
        if (! $entry || ! $robot) {
            return;
        }

        if ($run->status === 'stopping') {
            $this->finishStopped($run, $entry, $robot, $state);

            return;
        }

        $queuedFor = now()->getTimestamp() - $run->created_at->getTimestamp();
        if ($run->status === 'queued' && $queuedFor < config('fleet.demo.queue_seconds')) {
            return;
        }

        if ($run->status === 'queued') {
            $run->update(['status' => 'running', 'started_at' => now()]);
            $entry->update(['state' => 'moving']);
            $robot->motion_state = 'moving';
            FleetCommand::where('run_id', $run->id)->where('type', 'start')->update(['status' => 'dispatched']);
            $this->event($run, 'moving', '샘플 실행기 목표 수락 · 이동 시작', $robot->id);
            $state->revision++;
        }

        $run->refresh();
        $elapsed = max(0, now()->getTimestamp() - $run->started_at->getTimestamp());
        $travelSeconds = max(1, (int) config('fleet.demo.travel_seconds'));
        $waitStart = (int) config('fleet.demo.wait_start_seconds');
        $waitEnd = (int) config('fleet.demo.wait_end_seconds');

        if ($elapsed >= $travelSeconds) {
            $this->finishArrived($run, $entry, $robot, $state);

            return;
        }

        $waiting = $elapsed >= $waitStart && $elapsed < $waitEnd;
        if ($waiting) {
            if (! RunEvent::where('run_id', $run->id)->where('type', 'waiting')->exists()) {
                $this->event($run, 'waiting', '공용 통로 진입 대기 · 다른 로봇 통과 확인', $robot->id);
                $state->revision++;
            }
            $entry->state = 'waiting';
            $robot->motion_state = 'waiting';
        } else {
            if ($elapsed >= $waitEnd && ! RunEvent::where('run_id', $run->id)->where('type', 'resumed')->exists()) {
                $this->event($run, 'resumed', '진행 허가 · 공용 통로 통과', $robot->id);
                $state->revision++;
            }
            $entry->state = 'moving';
            $robot->motion_state = 'moving';
        }

        $progress = $this->routeProgress($elapsed, $travelSeconds, $waitStart, $waitEnd);
        $pose = $this->poseAlongPath($entry->planned_path, $progress);
        $entry->save();
        $robot->pose = $pose;
        $robot->received_at = now();
        $robot->save();
        $run->update(['tick' => (int) round($progress * 100)]);
        $state->heartbeat_at = now();
        $state->save();
    }

    public function reset(): array
    {
        if (! $this->enabled()) {
            throw new FleetError('DEMO_DISABLED', '발표용 데모 모드에서만 초기화할 수 있습니다.', 404);
        }

        return DB::transaction(function () {
            $state = FleetState::whereKey(1)->lockForUpdate()->firstOrFail();
            $runIds = FleetRun::where('mode', 'demo')->pluck('id');
            FleetCommand::whereIn('run_id', $runIds)->delete();
            RunEvent::whereIn('run_id', $runIds)->delete();
            RunRobot::whereIn('run_id', $runIds)->delete();
            FleetRun::whereIn('id', $runIds)->delete();

            foreach (array_keys(config('fleet.robots')) as $id) {
                Robot::whereKey($id)->update([
                    'motion_state' => 'idle',
                    'pose' => $this->initialPose($id),
                    'received_at' => now(),
                ]);
            }

            $state->active_run_id = null;
            $state->heartbeat_at = now();
            $state->revision++;
            $state->save();

            return ['reset' => true, 'snapshot_revision' => $state->revision];
        }, 3);
    }

    private function route(string $robotId): Collection
    {
        return collect(config("fleet.demo.routes_px.{$robotId}", []))
            ->map(fn (array $point) => $this->pixelToPose($point));
    }

    private function pixelToPose(array $point): array
    {
        $map = $this->displayMap->metadata();
        [$px, $py, $heading] = $point;
        $resolution = $map['resolution_m_per_pixel'];
        $u = $px * $resolution;
        $v = ($map['height_px'] - $py) * $resolution;
        $rotation = $map['origin']['yaw_rad'];
        $c = cos($rotation);
        $s = sin($rotation);

        return [
            'map_id' => $map['id'],
            'map_version' => $map['version'],
            'frame_id' => $map['frame_id'],
            'x_m' => round($map['origin']['x_m'] + $c * $u - $s * $v, 4),
            'y_m' => round($map['origin']['y_m'] + $s * $u + $c * $v, 4),
            'yaw_rad' => $heading + $rotation,
        ];
    }

    private function routeProgress(int $elapsed, int $total, int $waitStart, int $waitEnd): float
    {
        $waitProgress = $waitStart / max(1, $total - ($waitEnd - $waitStart));
        if ($elapsed < $waitStart) {
            return min($waitProgress, $elapsed / max(1, $total - ($waitEnd - $waitStart)));
        }
        if ($elapsed < $waitEnd) {
            return $waitProgress;
        }

        return min(1, ($elapsed - ($waitEnd - $waitStart)) / max(1, $total - ($waitEnd - $waitStart)));
    }

    private function poseAlongPath(array $path, float $progress): array
    {
        if (count($path) < 2) {
            return $path[0] ?? [];
        }

        $segments = [];
        $total = 0.0;
        for ($i = 1; $i < count($path); $i++) {
            $length = hypot($path[$i]['x_m'] - $path[$i - 1]['x_m'], $path[$i]['y_m'] - $path[$i - 1]['y_m']);
            $segments[] = $length;
            $total += $length;
        }

        $remaining = $total * max(0, min(1, $progress));
        foreach ($segments as $index => $length) {
            if ($remaining <= $length || $index === array_key_last($segments)) {
                $ratio = $length > 0 ? min(1, $remaining / $length) : 1;
                $from = $path[$index];
                $to = $path[$index + 1];

                return [
                    ...$from,
                    'x_m' => round($from['x_m'] + ($to['x_m'] - $from['x_m']) * $ratio, 4),
                    'y_m' => round($from['y_m'] + ($to['y_m'] - $from['y_m']) * $ratio, 4),
                    'yaw_rad' => $from['yaw_rad'] + ($to['yaw_rad'] - $from['yaw_rad']) * $ratio,
                ];
            }
            $remaining -= $length;
        }

        return end($path);
    }

    private function finishArrived(FleetRun $run, RunRobot $entry, Robot $robot, FleetState $state): void
    {
        $pose = $entry->goal_pose;
        $robot->update(['motion_state' => 'arrived', 'pose' => $pose, 'received_at' => now()]);
        $entry->update([
            'state' => 'arrived',
            'result' => [
                'source' => 'demo',
                'outcome' => 'succeeded',
                'pose' => $pose,
                'position_error_m' => 0.0,
                'yaw_error_rad' => 0.0,
                'message' => '발표용 샘플 주행 완료',
                'received_at' => now()->toISOString(),
            ],
        ]);
        $run->update(['status' => 'completed', 'tick' => 100, 'finished_at' => now()]);
        FleetCommand::where('run_id', $run->id)->update(['status' => 'completed']);
        $state->active_run_id = null;
        $state->revision++;
        $state->heartbeat_at = now();
        $state->save();
        $this->event($run, 'arrived', '고정 목표 도착 · 샘플 결과 저장 완료', $robot->id);
    }

    private function finishStopped(FleetRun $run, RunRobot $entry, Robot $robot, FleetState $state): void
    {
        $robot->update(['motion_state' => 'stopped', 'received_at' => now()]);
        $entry->update([
            'state' => 'stopped',
            'stop_ack_at' => now(),
            'result' => [
                'source' => 'demo',
                'outcome' => 'canceled',
                'pose' => $robot->pose,
                'message' => '발표용 샘플 정지 완료',
                'received_at' => now()->toISOString(),
            ],
        ]);
        $run->update(['status' => 'cancelled', 'finished_at' => now()]);
        FleetCommand::where('run_id', $run->id)->update(['status' => 'completed']);
        $state->active_run_id = null;
        $state->revision++;
        $state->heartbeat_at = now();
        $state->save();
        $this->event($run, 'stopped', '정지 요청 확인 · 샘플 실행 종료', $robot->id);
    }

    private function event(FleetRun $run, string $type, string $message, ?string $robotId): void
    {
        RunEvent::create([
            'run_id' => $run->id,
            'robot_id' => $robotId,
            'type' => $type,
            'message' => $message,
            'mode' => 'demo',
            'occurred_at' => now(),
        ]);
    }

    private function poseMatchesMap(array $pose): bool
    {
        $map = $this->displayMap->metadata();

        return ($pose['map_id'] ?? null) === $map['id']
            && ($pose['map_version'] ?? null) === $map['version']
            && ($pose['frame_id'] ?? null) === $map['frame_id'];
    }
}
