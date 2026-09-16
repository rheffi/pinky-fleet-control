<?php

namespace App\Fleet;

class SampleFixture
{
    public const ROBOTS = ['eed0' => 35, '648d' => 30, '62b2' => 40];

    public const LANES = ['eed0' => 0.7, '648d' => 2.0, '62b2' => 3.3];

    public function map(): array
    {
        return [
            'id' => 'sample-map', 'version' => '1', 'source' => 'sample',
            'frame_id' => 'sample_map', 'resolution_m_per_pixel' => 0.01,
            'origin' => ['x_m' => 0, 'y_m' => 0, 'yaw_rad' => 0],
            'width_px' => 600, 'height_px' => 400,
            'image_url' => '/maps/sample-map.svg',
        ];
    }

    public function pose(float $x, float $y, float $yaw = 0): array
    {
        return ['map_id' => 'sample-map', 'map_version' => '1', 'frame_id' => 'sample_map',
            'x_m' => $x, 'y_m' => $y, 'yaw_rad' => $yaw];
    }

    public function goals(): array
    {
        $goals = [];
        foreach (self::LANES as $robot => $y) {
            foreach (['left' => 0.6, 'right' => 5.4] as $side => $x) {
                $goals[] = ['id' => "$robot-$side", 'label' => ($side === 'left' ? '서쪽' : '동쪽')." · $robot",
                    'robot_id' => $robot, 'map_id' => 'sample-map', 'map_version' => '1',
                    'pose' => $this->pose($x, $y, $side === 'left' ? M_PI : 0)];
            }
        }

        return $goals;
    }

    // A scripted lane replay only; this does not compute a collision-free path.
    public function path(string $robot, array $start, array $goal): array
    {
        $y = self::LANES[$robot];
        if (($start['map_id'] ?? null) !== 'sample-map' || ($start['map_version'] ?? null) !== '1'
            || ($start['frame_id'] ?? null) !== 'sample_map'
            || abs(($start['y_m'] ?? -100) - $y) > 0.01
            || ! isset($start['x_m']) || $start['x_m'] < 0.5 || $start['x_m'] > 5.5) {
            throw new FleetError('POSE_MISMATCH', '샘플 지도와 현재 위치가 맞지 않습니다.');
        }
        $yaw = $goal['x_m'] < $start['x_m'] ? M_PI : 0;
        $frames = [];
        $waitAt = ['eed0' => 7, '648d' => 10, '62b2' => 13][$robot];
        for ($i = 0; $i <= 22; $i++) {
            $x = $start['x_m'] + ($goal['x_m'] - $start['x_m']) * $i / 22;
            $frames[] = ['index' => count($frames), ...$this->pose(round($x, 4), $y, $yaw), 'wait_s' => 0];
            if ($i === $waitAt) {
                for ($j = 0; $j < 3; $j++) {
                    $frames[] = ['index' => count($frames), ...$this->pose(round($x, 4), $y, $yaw), 'wait_s' => 3 - $j];
                }
            }
        }

        return $frames;
    }
}
