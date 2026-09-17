<?php

$nullableFloat = static function (string $key): ?float {
    $value = env($key);

    if ($value === null || $value === '') {
        return null;
    }

    if (! is_numeric($value)) {
        throw new InvalidArgumentException("{$key} must be numeric when set.");
    }

    return (float) $value;
};

$robot = static fn (string $id, int $domain) => [
    'domain_id' => (int) env("FLEET_ROBOT_{$id}_DOMAIN_ID", $domain),
    'initial_pose' => [
        'x_m' => $nullableFloat("FLEET_ROBOT_{$id}_INITIAL_X"),
        'y_m' => $nullableFloat("FLEET_ROBOT_{$id}_INITIAL_Y"),
        'yaw_rad' => $nullableFloat("FLEET_ROBOT_{$id}_INITIAL_YAW"),
    ],
    'goal_pose' => [
        'x_m' => $nullableFloat("FLEET_ROBOT_{$id}_GOAL_X"),
        'y_m' => $nullableFloat("FLEET_ROBOT_{$id}_GOAL_Y"),
        'yaw_rad' => $nullableFloat("FLEET_ROBOT_{$id}_GOAL_YAW"),
    ],
];

return [
    'mode' => env('FLEET_MODE', 'real'),
    'agent_token' => env('FLEET_AGENT_TOKEN'),
    'stale_seconds' => 3,
    'offline_seconds' => 10,
    'map' => [
        'id' => env('FLEET_MAP_ID', 'cbs-map'),
        'version' => env('FLEET_MAP_VERSION'),
        'frame_id' => env('FLEET_MAP_FRAME', 'map'),
    ],
    'robots' => [
        '62b2' => $robot('62B2', 40),
        '648d' => $robot('648D', 30),
        'eed0' => $robot('EED0', 35),
    ],
    'demo' => [
        'queue_seconds' => 1,
        'travel_seconds' => 11,
        'wait_start_seconds' => 3,
        'wait_end_seconds' => 6,
        // Pixel routes are tied to the current authoritative SLAM map and are
        // converted to map-frame metres at runtime.
        'routes_px' => [
            '62b2' => [[30, 60, 0.0], [50, 75, -0.6], [45, 108, -1.4], [78, 122, -0.4], [110, 170, -1.0]],
            '648d' => [[112, 38, -1.57], [112, 72, -1.57], [57, 72, 3.14], [49, 101, -1.3], [25, 120, 2.5]],
            'eed0' => [[30, 175, 1.57], [30, 127, 1.57], [70, 120, 0.2], [110, 110, 0.2]],
        ],
    ],
];
