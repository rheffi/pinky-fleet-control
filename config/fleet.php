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
];
