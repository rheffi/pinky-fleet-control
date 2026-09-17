#!/usr/bin/env bash

set -eo pipefail

if [[ $# -ne 1 || ! "$1" =~ ^(62b2|648d|eed0)$ ]]; then
    echo "Usage: $0 {62b2|648d|eed0}" >&2
    exit 2
fi

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd -- "$script_dir/.." && pwd)"
workspace="${PINKY_WORKSPACE:-$HOME/pinky_pro}"

source /opt/ros/jazzy/setup.bash
source "$workspace/install/setup.bash"

exec python3 "$script_dir/ros-fleet-agent.py" \
    --robot "$1" \
    --server "${FLEET_SERVER_URL:-http://127.0.0.1:8080}" \
    --env-file "$project_root/deploy/production.env"
