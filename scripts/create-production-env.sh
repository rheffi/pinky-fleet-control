#!/usr/bin/env bash

set -euo pipefail

output_path="${1:-deploy/production.env}"
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd -- "$script_dir/.." && pwd)"
map_path="$project_root/map/cbs_map.pgm"

if [[ -e "$output_path" ]]; then
    echo "Refusing to overwrite existing file: $output_path" >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    echo "openssl is required to generate production secrets." >&2
    exit 1
fi

if [[ ! -f "$map_path" ]]; then
    echo "Map file is required to generate its version: $map_path" >&2
    exit 1
fi

control_ip="${CONTROL_SERVER_IP:-}"
if [[ -z "$control_ip" ]] && command -v ip >/dev/null 2>&1; then
    control_ip="$(ip -o -4 route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')"
fi
control_ip="${control_ip:-127.0.0.1}"

app_key="base64:$(openssl rand -base64 32)"
db_password="$(openssl rand -hex 24)"
root_password="$(openssl rand -hex 24)"
agent_token="$(openssl rand -hex 32)"
map_version="$(sha256sum "$map_path" | awk '{print substr($1, 1, 12)}')"

mkdir -p "$(dirname "$output_path")"
umask 077
temporary_path="$(mktemp "${output_path}.tmp.XXXXXX")"
trap 'rm -f "$temporary_path"' EXIT

{
    printf 'APP_NAME="Pinky Fleet Control"\n'
    printf 'APP_IMAGE_TAG=local\n'
    printf 'APP_IMAGE_REPOSITORY=pinky-fleet-control-app\n'
    printf 'WEB_IMAGE_REPOSITORY=pinky-fleet-control-web\n\n'
    printf 'APP_KEY=%s\n' "$app_key"
    printf 'APP_URL=http://%s:8080\n' "$control_ip"
    printf 'LOG_LEVEL=warning\n\n'
    printf 'WEB_BIND_ADDRESS=0.0.0.0\n'
    printf 'WEB_PORT=8080\n\n'
    printf 'DB_DATABASE=pinky_fleet\n'
    printf 'DB_USERNAME=pinky_app\n'
    printf 'DB_PASSWORD=%s\n' "$db_password"
    printf 'MYSQL_ROOT_PASSWORD=%s\n\n' "$root_password"
    printf 'FLEET_MODE=real\n'
    printf 'FLEET_AGENT_TOKEN=%s\n' "$agent_token"
    printf 'FLEET_MAP_ID=cbs-map\n'
    printf 'FLEET_MAP_VERSION=%s\n' "$map_version"
    printf 'FLEET_MAP_FRAME=map\n\n'
    for robot in 62B2 648D EED0; do
        case "$robot" in
            62B2) domain=40 ;;
            648D) domain=30 ;;
            EED0) domain=35 ;;
        esac
        printf 'FLEET_ROBOT_%s_DOMAIN_ID=%s\n' "$robot" "$domain"
        printf 'FLEET_ROBOT_%s_INITIAL_X=\n' "$robot"
        printf 'FLEET_ROBOT_%s_INITIAL_Y=\n' "$robot"
        printf 'FLEET_ROBOT_%s_INITIAL_YAW=\n' "$robot"
        printf 'FLEET_ROBOT_%s_GOAL_X=\n' "$robot"
        printf 'FLEET_ROBOT_%s_GOAL_Y=\n' "$robot"
        printf 'FLEET_ROBOT_%s_GOAL_YAW=\n\n' "$robot"
    done
} > "$temporary_path"

mv "$temporary_path" "$output_path"
trap - EXIT

echo "Created $output_path with mode $(stat -c '%a' "$output_path") and APP_URL http://${control_ip}:8080"
