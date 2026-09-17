import test from 'node:test';
import assert from 'node:assert/strict';
import { worldToPixel } from '../../resources/js/fleet/map.js';

const map = { id: 'test', version: '2', frame_id: 'map', width_px: 600, height_px: 400,
    resolution_m_per_pixel: 0.01, origin: { x_m: 1, y_m: 2, yaw_rad: Math.PI / 2 } };
const pose = { map_id: 'test', map_version: '2', frame_id: 'map', x_m: 1, y_m: 3, yaw_rad: Math.PI / 2 };

test('translated rotated map uses inverse rotation and flips image Y', () => {
    const point = worldToPixel(pose, map);
    assert.ok(Math.abs(point.x - 100) < 1e-8);
    assert.ok(Math.abs(point.y - 400) < 1e-8);
    assert.ok(Math.abs(point.heading) < 1e-8);
    const corner = worldToPixel({ ...pose, x_m: 0, y_m: 2 }, map);
    assert.ok(Math.abs(corner.y - 300) < 1e-8);
});
test('unknown, nonfinite, or mismatched positions are hidden', () => {
    assert.equal(worldToPixel(null, map), null);
    assert.equal(worldToPixel({ ...pose, map_version: '1' }, map), null);
    assert.equal(worldToPixel({ ...pose, x_m: NaN }, map), null);
    assert.equal(worldToPixel({ ...pose, frame_id: 'odom' }, map), null);
});
