#!/usr/bin/env python3

"""Bridge one ROS domain to the Pinky Fleet Control HTTP API."""

from __future__ import annotations

import argparse
import json
import math
import os
from pathlib import Path
import sys
import time
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


ROBOT_DOMAINS = {"62b2": 40, "648d": 30, "eed0": 35}
TERMINAL_STATES = {"arrived", "failed", "stopped"}


def read_environment(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key] = value.strip().strip('"').strip("'")
    return values


def yaw_from_quaternion(x: float, y: float, z: float, w: float) -> float:
    return math.atan2(2.0 * (w * z + x * y), 1.0 - 2.0 * (y * y + z * z))


class ApiClient:
    def __init__(self, server: str, token: str, robot: str) -> None:
        self.base = f"{server.rstrip('/')}/api/agent/v1/robots/{robot}"
        self.token = token

    def request(self, method: str, endpoint: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        body = json.dumps(payload).encode() if payload is not None else None
        request = Request(
            self.base + endpoint,
            data=body,
            method=method,
            headers={
                "Accept": "application/json",
                "Content-Type": "application/json",
                "X-Fleet-Agent-Token": self.token,
            },
        )
        try:
            with urlopen(request, timeout=2.0) as response:
                return json.load(response)
        except HTTPError as error:
            message = error.reason
            try:
                message = json.load(error).get("error", {}).get("message", message)
            except (json.JSONDecodeError, AttributeError):
                pass
            raise RuntimeError(f"HTTP {error.code}: {message}") from error
        except (URLError, TimeoutError) as error:
            raise RuntimeError(f"server unavailable: {error.reason if isinstance(error, URLError) else error}") from error


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one PinkyPro ROS-to-control-server agent")
    parser.add_argument("--robot", required=True, choices=sorted(ROBOT_DOMAINS))
    parser.add_argument("--server", default="http://127.0.0.1:8080")
    parser.add_argument("--env-file", type=Path, default=Path("deploy/production.env"))
    args = parser.parse_args()

    env = read_environment(args.env_file)
    token = env.get("FLEET_AGENT_TOKEN", "")
    if not token:
        print("FLEET_AGENT_TOKEN is missing from the environment file.", file=sys.stderr)
        return 2

    prefix = f"FLEET_ROBOT_{args.robot.upper()}"
    domain = int(env.get(f"{prefix}_DOMAIN_ID", ROBOT_DOMAINS[args.robot]))
    map_id = env.get("FLEET_MAP_ID", "cbs-map")
    map_version = env.get("FLEET_MAP_VERSION", "")
    map_frame = env.get("FLEET_MAP_FRAME", "map")
    if not map_version:
        print("FLEET_MAP_VERSION is missing from the environment file.", file=sys.stderr)
        return 2

    os.environ["ROS_DOMAIN_ID"] = str(domain)
    os.environ.setdefault("ROS_AUTOMATIC_DISCOVERY_RANGE", "SUBNET")
    os.environ.pop("ROS_LOCALHOST_ONLY", None)

    import rclpy
    from action_msgs.msg import GoalStatus
    from geometry_msgs.msg import PoseWithCovarianceStamped
    from nav2_msgs.action import NavigateToPose
    from rclpy.action import ActionClient
    from rclpy.node import Node

    class FleetAgent(Node):
        def __init__(self) -> None:
            super().__init__(f"pinky_fleet_agent_{args.robot}")
            self.api = ApiClient(args.server, token, args.robot)
            self.pose: dict[str, Any] | None = None
            self.last_pose_at = 0.0
            self.motion_state = "unknown"
            self.action_result: str | None = None
            self.message: str | None = None
            self.current_run: str | None = None
            self.goal_handle = None
            self.cancel_requested = False
            self.last_error_at = 0.0
            self.action = ActionClient(self, NavigateToPose, "/navigate_to_pose")
            self.create_subscription(PoseWithCovarianceStamped, "/amcl_pose", self.on_pose, 10)
            self.create_timer(1.0, self.tick)
            self.get_logger().info(f"agent ready: robot={args.robot} domain={domain} server={args.server}")

        def on_pose(self, message: PoseWithCovarianceStamped) -> None:
            position = message.pose.pose.position
            orientation = message.pose.pose.orientation
            self.pose = {
                "map_id": map_id,
                "map_version": map_version,
                "frame_id": map_frame,
                "x_m": position.x,
                "y_m": position.y,
                "yaw_rad": yaw_from_quaternion(orientation.x, orientation.y, orientation.z, orientation.w),
            }
            self.last_pose_at = time.monotonic()
            if self.motion_state == "unknown":
                self.motion_state = "idle"

        def tick(self) -> None:
            self.report()
            try:
                command = self.api.request("GET", "/command")
            except RuntimeError as error:
                self.log_error(error)
                return

            action = command.get("action")
            run_id = command.get("run_id")
            if action == "start" and run_id != self.current_run:
                self.start_goal(run_id, command["goal_pose"])
            elif action == "stop" and run_id == self.current_run:
                self.stop_goal()

        def report(self) -> None:
            pose_is_recent = time.monotonic() - self.last_pose_at <= 2.5
            connected = self.action.server_is_ready() or pose_is_recent
            payload: dict[str, Any] = {
                "run_id": self.current_run,
                "connected": connected,
                "motion_state": self.motion_state if connected else "unknown",
                "action_result": self.action_result,
                "message": self.message,
                "pose": self.pose if pose_is_recent else None,
            }
            try:
                self.api.request("POST", "/telemetry", payload)
            except RuntimeError as error:
                self.log_error(error)
                return

            if self.motion_state in TERMINAL_STATES and self.action_result:
                self.current_run = None
                self.action_result = None

        def start_goal(self, run_id: str, pose: dict[str, Any]) -> None:
            if not self.action.wait_for_server(timeout_sec=1.0):
                self.current_run = run_id
                self.motion_state = "failed"
                self.action_result = "failed"
                self.message = "navigate_to_pose action server unavailable"
                self.report()
                return

            goal = NavigateToPose.Goal()
            goal.pose.header.frame_id = pose["frame_id"]
            goal.pose.header.stamp = self.get_clock().now().to_msg()
            goal.pose.pose.position.x = float(pose["x_m"])
            goal.pose.pose.position.y = float(pose["y_m"])
            yaw = float(pose["yaw_rad"])
            goal.pose.pose.orientation.z = math.sin(yaw / 2.0)
            goal.pose.pose.orientation.w = math.cos(yaw / 2.0)

            self.current_run = run_id
            self.motion_state = "idle"
            self.action_result = None
            self.message = "Nav2 goal request pending"
            future = self.action.send_goal_async(goal)
            future.add_done_callback(self.on_goal_response)

        def on_goal_response(self, future) -> None:
            try:
                self.goal_handle = future.result()
            except Exception as error:  # rclpy surfaces transport failures here.
                self.motion_state = "failed"
                self.action_result = "failed"
                self.message = f"goal request failed: {type(error).__name__}"
                self.report()
                return

            if not self.goal_handle.accepted:
                self.motion_state = "failed"
                self.action_result = "rejected"
                self.message = "Nav2 rejected the goal"
                self.report()
                return

            self.motion_state = "moving"
            self.message = "Nav2 goal accepted"
            result = self.goal_handle.get_result_async()
            result.add_done_callback(self.on_result)
            if self.cancel_requested:
                self.stop_goal()
            self.report()

        def stop_goal(self) -> None:
            self.cancel_requested = True
            if self.goal_handle is not None:
                self.goal_handle.cancel_goal_async()

        def on_result(self, future) -> None:
            status = future.result().status
            self.cancel_requested = False
            self.goal_handle = None
            if status == GoalStatus.STATUS_SUCCEEDED:
                self.motion_state = "arrived"
                self.action_result = "succeeded"
                self.message = "Nav2 reported SUCCEEDED"
            elif status == GoalStatus.STATUS_CANCELED:
                self.motion_state = "stopped"
                self.action_result = "canceled"
                self.message = "Nav2 goal cancellation confirmed"
            else:
                self.motion_state = "failed"
                self.action_result = "aborted"
                self.message = f"Nav2 finished with status {status}"
            self.report()

        def log_error(self, error: Exception) -> None:
            now = time.monotonic()
            if now - self.last_error_at >= 10.0:
                self.get_logger().error(str(error))
                self.last_error_at = now

    rclpy.init()
    node = FleetAgent()
    try:
        rclpy.spin(node)
    except KeyboardInterrupt:
        pass
    finally:
        node.destroy_node()
        rclpy.shutdown()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
