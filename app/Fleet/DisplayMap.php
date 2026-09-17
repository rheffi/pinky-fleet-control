<?php

namespace App\Fleet;

use RuntimeException;

class DisplayMap
{
    public function metadata(): array
    {
        $yamlPath = base_path('map/cbs_map.yaml');
        $pgmPath = base_path('map/cbs_map.pgm');
        $pngPath = public_path('maps/cbs_map.png');
        $yaml = file_get_contents($yamlPath);

        if ($yaml === false
            || ! preg_match('/^resolution:\s*([0-9.]+)\s*$/m', $yaml, $resolution)
            || ! preg_match('/^origin:\s*\[\s*([^,]+),\s*([^,]+),\s*([^\]]+)\]\s*$/m', $yaml, $origin)) {
            throw new RuntimeException('The display map YAML metadata is invalid.');
        }

        $size = getimagesize($pngPath);
        if ($size === false) {
            throw new RuntimeException('The display map PNG is missing or invalid.');
        }

        return [
            'id' => 'cbs-map',
            'version' => substr(hash_file('sha256', $pgmPath), 0, 12),
            'source' => 'real',
            'frame_id' => 'map',
            'resolution_m_per_pixel' => (float) $resolution[1],
            'origin' => [
                'x_m' => (float) trim($origin[1]),
                'y_m' => (float) trim($origin[2]),
                'yaw_rad' => (float) trim($origin[3]),
            ],
            'width_px' => $size[0],
            'height_px' => $size[1],
            'image_url' => '/maps/cbs_map.png',
        ];
    }
}
