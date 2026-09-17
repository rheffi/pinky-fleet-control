export function worldToPixel(pose, map) {
    if (!pose || !map || pose.map_id !== map.id || pose.map_version !== map.version
        || pose.frame_id !== map.frame_id || !(map.resolution_m_per_pixel > 0)) return null;
    const { x_m: ox, y_m: oy, yaw_rad: rotation } = map.origin;
    if (![pose.x_m, pose.y_m, pose.yaw_rad, ox, oy, rotation].every(Number.isFinite)) return null;
    const dx = pose.x_m - ox, dy = pose.y_m - oy;
    const c = Math.cos(rotation), s = Math.sin(rotation), resolution = map.resolution_m_per_pixel;
    return { x: (c * dx + s * dy) / resolution, y: map.height_px - (-s * dx + c * dy) / resolution,
        heading: -(pose.yaw_rad - rotation) * 180 / Math.PI };
}
