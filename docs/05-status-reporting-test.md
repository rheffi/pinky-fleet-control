# 05. 관제서버 상황 보고 테스트 시나리오

갱신: 2026-09-16

## 1. 목표와 범위

로봇 실행기(`scripts/ros-fleet-agent.py`)가 보낸 위치·연결·주행 상태가 관제 API, DB, Vue 화면까지 **사실과 다르게 바뀌지 않고** 전달되는지 확인한다.

- 확인 대상: 수신 시각, 연결 신선도(online/stale/offline), 현재 위치, 동작 상태, 작업 결과(도착·정지·실패), 이벤트 기록, 화면 표시.
- 제외: Nav2 주행 성능(03 문서), CBS 알고리즘, 다중 로봇 동시 주행 조정.
- 원칙: HTTP 접수, 취소 요청, 보고 중단을 도착이나 정지 확인으로 기록하지 않는다.

이 문서는 시나리오 작성 단계다. **아래 시나리오는 아직 실행하지 않았다.** 결과는 7절 표에 시험 수준을 구분해 기록한다.

| 수준 | 방법 | ROS·로봇 필요 |
|---|---|---|
| A. 자동 테스트 | `php artisan test` (SQLite 메모리 DB) | 아니오 |
| B. 가짜 실행기 | curl로 실행기 API를 직접 호출 | 아니오 |
| C. 실제 실행기 | `run-ros-fleet-agent.sh` + 실물 로봇 | 예 |

## 2. 현재 보고 경로와 판정 규칙 (코드 정적 검토)

```text
로봇 /amcl_pose, /navigate_to_pose
   │ ROS_DOMAIN_ID (62b2=40, 648d=30, eed0=35)
   ▼
ros-fleet-agent.py  ── 1초마다 POST telemetry → GET command (HTTP 제한 2초)
   │ X-Fleet-Agent-Token
   ▼
Laravel FleetService::telemetry → robots / fleet_runs / run_robots / run_events
   ▼
GET /api/v1/snapshot  ← Vue 1초 polling
```

| 항목 | 현재 동작 | 근거 |
|---|---|---|
| 실행기 `connected` | Nav2 action server 준비됨 **또는** 2.5초 안에 받은 `/amcl_pose`가 있음 | `ros-fleet-agent.py` `report()` |
| 실행기 `pose` | 2.5초 안에 받은 위치만 보내고, 아니면 `null` | 같은 함수 |
| `connected=true` 수신 | `received_at` 갱신, `pose`가 있으면 위치 갱신 | `FleetService::telemetry` |
| `connected=false` 수신 | `received_at`과 위치는 유지하고 `motion_state`만 갱신 | 같은 함수 |
| 신선도 | 마지막 수신 후 3초 이하 online, 3~10초 stale, 10초 초과 또는 없음 offline | `config/fleet.php`, `freshness()` |
| 화면 자체 지연 | snapshot 성공 후 3.5초가 지나거나 네트워크 오류가 나면 "관제 갱신 확인 필요" 표시, 시작 버튼 차단 | `FleetDashboard.vue` `staleView` |
| 이동 시작 | `run_id`가 활성 작업과 같고 `moving`이며 대상이 `pending`일 때만 running | `applyTelemetryToRun` |
| 도착 | `arrived` + `succeeded` + 위치가 모두 있어야 completed, 아니면 422 `ARRIVAL_NOT_VERIFIED` | 같은 함수 |
| 정지 | 작업이 `stopping`이고 `stopped` + `canceled`일 때만 cancelled | 같은 함수 |
| 실패 | `failed` + `aborted/rejected/failed`일 때만 failed | 같은 함수 |
| 다른 `run_id` | 로봇 상태는 갱신하고 작업 상태에는 반영하지 않음 | `telemetry()` |

### 2.1 정적 검토에서 나온 확인 필요 사항

코드만 보고 추정한 위험이다. 시험 결과로 실제 발생을 확인한 뒤 수정 여부를 정한다.

| ID | 추정 위험 | 확인 시나리오 |
|---|---|---|
| R1 | AMCL 위치가 끊겨도 Nav2 action server가 살아 있으면 `connected=true, pose=null`로 보고된다. 이때 `received_at`은 계속 갱신되므로 **예전 위치가 online으로 표시되고 주행 시작도 허용된다.** | S16 |
| R2 | stale/offline 로봇의 마지막 위치가 지도에 계속 표시된다. 표시 스타일만 다르다. | S4, S15 |
| R3 | 주행 중 실행기가 재시작하면 `current_run`이 비어 있어 같은 `run_id`의 Nav2 목표를 다시 보낸다. | S17 |
| R4 | 작업이 `running/stopping`인 상태에서 보고가 끊기면 제한 시간이 없어 작업이 계속 활성으로 남는다. 다른 로봇도 시작할 수 없다. | S12 |
| R5 | 서버가 도착 보고를 422로 거부하면 실행기는 같은 보고를 1초마다 반복한다. 오류 로그는 10초마다 남는다. | S8 |
| R6 | 화면의 `worldToPixel`은 지도 ID·버전·좌표계만 검사하고 지도 범위는 검사하지 않는다. 지도 밖 위치도 "좌표 불일치" 없이 시작 가능으로 보이고, 서버도 범위를 검사하지 않는다. | S6 |
| R7 | 관제 갱신 지연(`staleView`) 중 시작 버튼은 막히지만 정지 버튼은 막히지 않는다. 정지 요청이 실패할 수 있음을 화면에서 알 수 있는지 확인이 필요하다. | S7 |

## 3. 시험 전 준비

### 3.1 공통

- 시험 대상 관제서버와 주소를 기록한다. Ubuntu 운영이면 `compose.prod.yaml`, 개발이면 `compose.yaml` 환경이다.
- `GET /api/health`가 200인지 확인한다.
- 진행 중인 작업이 없는지 확인한다(`active_run: null`).
- 화면(`/`)을 열어 두고 확인이 필요한 시점마다 화면을 캡처한다.
- 시각 비교를 위해 관제 PC와 로봇의 시계 차이를 기록한다. 판정은 서버 `server_time`과 `received_at` 기준으로 한다.

### 3.2 가짜 실행기(B 수준) 주의

- **실제 실행기가 같은 로봇 ID로 보고 중일 때 B 시험을 하지 않는다.** 두 보고가 섞여 판정이 무의미해진다.
- 가짜 위치 보고는 해당 로봇을 "주행 시작 가능"으로 만든다. 실물 로봇이 켜져 있고 Nav2가 동작 중이면 화면에서 시작 버튼을 누르지 않는다.
- 가능하면 개발 환경에서 수행한다. 운영 DB에서 수행했다면 시험 후 남은 위치·작업 기록을 7절에 적는다.
- 토큰은 화면·로그·문서에 출력하지 않는다.

```bash
cd ~/project/pinky-fleet-control
ENV_FILE=deploy/production.env        # 개발 환경이면 .env
export SERVER=${FLEET_SERVER_URL:-http://127.0.0.1:8080}
export FLEET_AGENT_TOKEN="$(sed -n 's/^FLEET_AGENT_TOKEN=//p' "$ENV_FILE" | tr -d "\"'")"
export MAP_VERSION="$(sed -n 's/^FLEET_MAP_VERSION=//p' "$ENV_FILE" | tr -d "\"'")"

# report ROBOT 'JSON' : HTTP 상태 코드와 응답 본문 출력
report() {
  curl -sS -w '\nHTTP %{http_code}\n' -X POST "$SERVER/api/agent/v1/robots/$1/telemetry" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -H "X-Fleet-Agent-Token: $FLEET_AGENT_TOKEN" -d "$2"
}
# agent_cmd ROBOT : 실행기가 받는 명령 조회
agent_cmd() {
  curl -sS -H 'Accept: application/json' -H "X-Fleet-Agent-Token: $FLEET_AGENT_TOKEN" \
    "$SERVER/api/agent/v1/robots/$1/command"; echo
}
# pose X Y YAW [MAP_VERSION]
pose() {
  printf '{"map_id":"cbs-map","map_version":"%s","frame_id":"map","x_m":%s,"y_m":%s,"yaw_rad":%s}' \
    "${4:-$MAP_VERSION}" "$1" "$2" "$3"
}
# snap : 판정에 필요한 필드만 요약
snap() {
  curl -sS "$SERVER/api/v1/snapshot" | jq -c '{t: .server_time, rev: .snapshot_revision,
    run: (.active_run | if . then {id, status, robot: .robots[0].state} else null end),
    robots: [.robots[] | {id, c: .connection_state, m: .motion_state, at: .received_at, x: .pose.x_m, y: .pose.y_m}]}'
}
```

지도 범위는 현재 `cbs_map.yaml` 기준 x `-0.338~1.132`, y `-1.761~0.309`(m)이다. 아래 예시는 지도 안의 좌표 `(0.30, -0.80)`을 사용한다. 실제 통로 위치인지는 판정 대상이 아니다.

### 3.3 주행 작업 시나리오 전제 (S8~S12)

- 대상 로봇의 `FLEET_ROBOT_<ID>_GOAL_X/Y/YAW`가 설정돼 있어야 한다. 비어 있으면 시작이 `GOAL_NOT_CONFIGURED`로 거부된다.
- 작업 시작과 정지는 CSRF 보호를 받으므로 **화면 버튼으로** 요청한다. 로봇 보고만 curl로 흉내 낸다.
- B 수준에서는 실물 로봇 실행기를 끈 상태여야 한다. 그래야 화면의 시작 요청이 실제 주행으로 이어지지 않는다.

### 3.4 실제 실행기(C 수준)

```bash
source /opt/ros/jazzy/setup.bash
source ~/pinky_pro/install/setup.bash
export ROS_DOMAIN_ID=40
ros2 topic echo /amcl_pose --once            # 위치 발행 확인
ros2 action list -t | grep navigate_to_pose  # Nav2 action 확인

FLEET_SERVER_URL=http://127.0.0.1:8080 scripts/run-ros-fleet-agent.sh 62b2
```

03 문서 T2(AMCL 수렴)를 통과한 상태에서 시작한다. 목표를 보내는 C 시나리오는 넓은 위치에서만 실행하고, 사람이 즉시 로봇을 잡을 수 있게 준비한다.

## 4. 자동 테스트 (A)

### S0. 기존 자동 테스트 통과

```bash
# 개발 환경
docker compose exec app php artisan test --filter=FleetTest
```

**합격 기준**: `FleetTest` 전체 통과. 현재 포함 범위는 토큰 인증, 위치·수신 시각 반영, Nav2 성공과 위치가 있어야 도착, 성공 없는 도착 거부, 정지 확인 대기, stale 로봇(12초)·지도 버전 불일치 시작 차단이다.

**자동 테스트 미포함(B·C로 확인)**: stale 3~10초 구간, `connected=false` 보고, 다른 `run_id` 보고 무시, 실패 보고, 보고 중단 중인 작업(R4), 화면 표시.

## 5. 가짜 실행기 시나리오 (B)

### S1. 인증 실패

**절차**

1. `snap`으로 `rev`를 기록한다.
2. 토큰 없이, 그리고 틀린 토큰으로 telemetry를 보낸다.

```bash
curl -sS -w '\nHTTP %{http_code}\n' -X POST "$SERVER/api/agent/v1/robots/62b2/telemetry" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"connected":true,"motion_state":"idle"}'
FLEET_AGENT_TOKEN=wrong report 62b2 '{"connected":true,"motion_state":"idle"}'
snap
```

**합격 기준**

- 두 요청 모두 401 `AGENT_UNAUTHORIZED`.
- `rev`, `received_at`, 위치가 바뀌지 않는다.
- 응답에 토큰 값이나 예외 상세가 없다.

### S2. 잘못된 보고 거부

**절차**: 아래를 각각 보낸다.

```bash
report 62b2 '{"connected":true,"motion_state":"flying"}'
report 62b2 '{"connected":true,"motion_state":"idle","pose":{"x_m":0.3}}'
report 62b2 "{\"connected\":true,\"motion_state\":\"idle\",\"pose\":$(pose 0.30 -0.80 0 | sed 's/}$/,"z_m":0}/')}"
report 62b2 '{"connected":true,"motion_state":"idle","run_id":"not-a-uuid"}'
report 62b2 '{"motion_state":"idle"}'
report not-a-robot '{"connected":true,"motion_state":"idle"}'
```

**합격 기준**

- 앞의 다섯 요청은 422, 마지막 요청은 404 `ROBOT_NOT_FOUND`.
- 거부된 요청 뒤에도 `snap`의 로봇 상태가 바뀌지 않는다.

### S3. 정상 수신과 로봇별 분리

**절차**

```bash
report 62b2 "{\"connected\":true,\"motion_state\":\"idle\",\"pose\":$(pose 0.30 -0.80 0)}"
snap
```

화면에서 62b2 카드와 지도 표시를 확인한다. 이어서 `648d`에 다른 좌표 `(0.60, -1.20, 1.57)`를 보낸다.

**합격 기준**

- 62b2가 `online`, `idle`, x/y가 보낸 값과 일치하고 `received_at`이 `server_time`과 1초 이내다.
- 화면의 "마지막 수신" 시각과 좌표가 snapshot과 같다. 지도 표시 위치와 방향이 보낸 yaw와 맞는다.
- 648d 보고가 62b2의 위치·수신 시각을 바꾸지 않는다. `eed0`은 `offline`으로 남는다.

### S4. 보고 중단 시 신선도 전이

**절차**

1. S3 보고 직후 1초 간격으로 15초 동안 상태를 기록한다.

```bash
report 62b2 "{\"connected\":true,\"motion_state\":\"idle\",\"pose\":$(pose 0.30 -0.80 0)}"
for i in $(seq 1 15); do sleep 1; echo "+${i}s $(snap)"; done
```

2. 같은 시간 동안 화면 카드 문구, 시작 버튼 상태, 지도 표시를 확인한다.
3. 다시 한 번 보고하고 복귀를 확인한다.

**합격 기준**

- 약 3초까지 `online`, 약 4~10초 `stale`("수신 지연"), 약 11초 이후 `offline`("연결 끊김")으로 바뀐다. 경계에서 ±1초 차이는 polling 간격으로 허용한다.
- stale/offline 동안 시작 버튼이 비활성이고 이유가 표시된다.
- `motion_state`나 작업 결과가 `arrived/stopped`로 바뀌지 않는다.
- 다시 보고하면 1~2초 안에 `online`으로 복귀한다.
- R2 확인: offline 동안 지도에 마지막 위치가 남는지, 남는다면 현재 위치와 구분되는 표시인지 캡처와 함께 기록한다.

### S5. `connected=false` 보고

**절차**

```bash
report 62b2 "{\"connected\":true,\"motion_state\":\"idle\",\"pose\":$(pose 0.30 -0.80 0)}"
snap
for i in $(seq 1 12); do
  report 62b2 "{\"connected\":false,\"motion_state\":\"unknown\",\"pose\":$(pose 0.90 -0.20 0)}" >/dev/null
  sleep 1; echo "+${i}s $(snap)"
done
```

**합격 기준**

- 응답은 200이지만 `received_at`과 위치(0.30, -0.80)가 바뀌지 않는다. 연결이 끊긴 보고에 들어 있는 위치는 반영하지 않는다.
- `motion_state`가 `unknown`이 되고, 신선도는 S4와 같이 stale → offline으로 바뀐다. `connected=false` 보고가 계속 와도 online으로 유지되지 않는다.

### S6. 지도 불일치 위치

**절차**

1. 다른 지도 버전으로 위치를 보낸 뒤 화면의 62b2 시작 버튼을 확인한다.

```bash
report 62b2 "{\"connected\":true,\"motion_state\":\"idle\",\"pose\":$(pose 0.30 -0.80 0 old-map-version)}"
```

2. 올바른 버전이지만 지도 밖 좌표 `(5.0, 5.0)`를 보낸다.

**합격 기준**

- 1: 로봇이 지도에 그려지지 않고 시작 버튼이 비활성, 이유는 "지도 좌표 불일치"다. 작업이 만들어지지 않는다. 서버 측 `POSE_MISMATCH` 거부는 S0 자동 테스트로 확인한다.
- 2(R6): 지도 밖 위치가 시작 가능으로 보이는지 기록한다. 현재 코드상 시작 버튼이 활성일 것으로 예상하며, 그렇다면 불합격으로 기록하고 범위 검사 추가를 검토한다. **버튼은 누르지 않는다.**

### S7. 관제서버 자체 끊김

**절차**

1. 화면을 연 상태로 app 컨테이너를 멈춘다(운영: `docker compose --env-file deploy/production.env -f compose.prod.yaml stop app`).
2. 10초간 화면을 관찰한 뒤 다시 시작한다.

**합격 기준**

- 약 3.5초 안에 "관제 갱신 확인 필요"가 표시되고 시작 버튼이 차단된다.
- R7: 활성 작업이 있는 상태로 한 번 더 수행해, 정지 버튼을 눌렀을 때 실패가 화면에 명확히 표시되고 "정지 확인 대기/정지 완료"로 바뀌지 않는지 기록한다.
- 서버가 끊긴 동안 로봇 카드가 마지막 값을 새 값처럼 표시하지 않는다.
- 복구 후 자동으로 갱신이 재개되고 새로고침 없이 경고가 사라진다.

### S8. 주행 보고 순서와 도착 확인

전제: 3.3절. 62b2 목표 좌표를 `(GX, GY, GYAW)`로 기록한다.

**절차**

1. `report 62b2`로 idle 위치를 보내 online으로 만든 뒤 1초마다 반복 보고한다. 별도 터미널에서 실행한다.

```bash
while true; do report 62b2 "{\"connected\":true,\"motion_state\":\"idle\",\"pose\":$(pose 0.30 -0.80 0)}" >/dev/null; sleep 1; done
```

2. 화면에서 62b2 시작 → `agent_cmd 62b2`로 `action: start`, `run_id`, `goal_pose`를 확인하고 `RUN=<run_id>`로 저장한다.
3. 반복 보고를 멈추고 아래 보고를 순서대로 보내며 매번 `snap`과 화면을 확인한다.

```bash
report 62b2 "{\"run_id\":\"$RUN\",\"connected\":true,\"motion_state\":\"moving\",\"pose\":$(pose 0.35 -0.80 0)}"
report 62b2 "{\"run_id\":\"$RUN\",\"connected\":true,\"motion_state\":\"idle\",\"pose\":$(pose 0.40 -0.80 0)}"
report 62b2 "{\"run_id\":\"$RUN\",\"connected\":true,\"motion_state\":\"arrived\",\"pose\":$(pose GX GY GYAW)}"
report 62b2 "{\"run_id\":\"$RUN\",\"connected\":true,\"motion_state\":\"arrived\",\"action_result\":\"succeeded\"}"
report 62b2 "{\"run_id\":\"$RUN\",\"connected\":true,\"motion_state\":\"arrived\",\"action_result\":\"succeeded\",\"message\":\"Nav2 reported SUCCEEDED\",\"pose\":$(pose GX GY GYAW)}"
report 62b2 "{\"run_id\":\"$RUN\",\"connected\":true,\"motion_state\":\"arrived\",\"action_result\":\"succeeded\",\"pose\":$(pose GX GY GYAW)}"
curl -sS "$SERVER/api/v1/runs/$RUN/events" | jq -c '.events[] | {type, message}'
```

**합격 기준**

- 시작 직후 작업 `queued`, 이벤트 "로봇 실행기 전달 대기". 화면에 "도착"이나 "이동 중"이 표시되지 않는다.
- `moving` 보고 후 `running`, `started_at` 기록.
- 주행 중 `idle` 보고는 작업을 끝내지 않는다.
- 성공 결과가 없거나 위치가 없는 도착 보고는 422 `ARRIVAL_NOT_VERIFIED`이고 작업은 `running`으로 남는다(R5: 실제 실행기는 이 보고를 반복한다).
- 올바른 도착 보고 후 `completed`, `active_run: null`, 결과에 `position_error_m`·`yaw_error_rad`·`received_at`이 있다.
- 중복 도착 보고가 이벤트나 결과를 중복 생성하지 않는다. 이벤트는 `queued → moving → arrived` 한 번씩이다.
- 화면에 소요 시간과 결과 수신 시각이 표시된다.

### S9. 다른 작업의 보고 무시

**절차**

1. S8 완료 뒤 62b2 idle 위치를 한 번 보내 online으로 만들고, 새 작업을 시작해 `RUN2`로 저장한다. 이후 시나리오에서도 시작 전에 같은 방식으로 online을 만든다.
2. 이전 `RUN`과 임의의 UUID로 도착·실패 보고를 보낸다.

```bash
report 62b2 "{\"run_id\":\"$RUN\",\"connected\":true,\"motion_state\":\"arrived\",\"action_result\":\"succeeded\",\"pose\":$(pose GX GY GYAW)}"
report 62b2 "{\"run_id\":\"$(cat /proc/sys/kernel/random/uuid)\",\"connected\":true,\"motion_state\":\"failed\",\"action_result\":\"aborted\"}"
```

**합격 기준**: `RUN2`가 `queued`로 유지되고 이전 작업의 결과·이벤트가 바뀌지 않는다. 로봇 카드의 `motion_state`는 마지막 보고값으로 바뀌므로 이를 기록만 한다.

### S10. 정지 요청과 정지 확인 분리

**절차**

1. `RUN2`에 `moving` 보고 → 화면에서 정지 → `agent_cmd 62b2`가 `action: stop`인지 확인.
2. 아래 순서로 보고한다.

```bash
report 62b2 "{\"run_id\":\"$RUN2\",\"connected\":true,\"motion_state\":\"idle\",\"pose\":$(pose 0.40 -0.80 0)}"
report 62b2 "{\"run_id\":\"$RUN2\",\"connected\":true,\"motion_state\":\"stopped\",\"pose\":$(pose 0.40 -0.80 0)}"
report 62b2 "{\"run_id\":\"$RUN2\",\"connected\":true,\"motion_state\":\"stopped\",\"action_result\":\"canceled\",\"message\":\"Nav2 goal cancellation confirmed\",\"pose\":$(pose 0.41 -0.80 0)}"
```

**합격 기준**

- 정지 요청 직후 202, 작업 `stopping`, `stop_ack_at: null`, 화면은 "정지 확인 대기"이고 "정지 완료"가 아니다.
- `idle`이나 `canceled` 없는 `stopped` 보고로는 종료되지 않는다.
- `canceled` 보고 후 `cancelled`("정지 완료"), `stop_ack_at`과 멈춘 위치가 기록된다.

### S11. 주행 실패 보고

**절차**: 새 작업 `RUN3` 시작 → `moving` → 아래 보고.

```bash
report 62b2 "{\"run_id\":\"$RUN3\",\"connected\":true,\"motion_state\":\"failed\",\"action_result\":\"aborted\",\"message\":\"Nav2 finished with status 6\",\"pose\":$(pose 0.50 -0.80 0)}"
```

**합격 기준**: 작업 `failed`, 오류 코드 `NAV2_FAILED`와 메시지가 화면에 표시된다. 활성 작업이 해제되고, 다음 시작이 가능하다. `action_result` 없는 `failed` 보고로는 실패 처리되지 않는다.

### S12. 주행 중 보고 끊김 (R4)

**절차**: 새 작업 `RUN4` 시작 → `moving` 보고 1회 → 보고를 멈추고 60초 관찰 → 다른 로봇(online 상태로 만든 648d) 시작 시도 → 화면에서 `RUN4` 정지 요청 후 30초 관찰.

**합격 기준(원칙)**

- 62b2가 stale → offline으로 표시되고 작업이 `completed/cancelled`로 바뀌지 않는다.
- 정지 요청 후에도 로봇 확인이 없으면 `stopping`으로 남는다. 화면에 "정지 완료"가 표시되지 않는다.

**기록할 관찰**: 작업이 제한 없이 활성으로 남아 648d 시작이 `ACTIVE_RUN`으로 막히는지, 운영자가 해제할 방법이 있는지. 막힌다면 R4를 미해결 문제로 README에 올리고 처리 방식(시간 제한, 수동 해제)을 합의한다. 시험 후 이 작업을 정리하려면 S10의 `canceled` 보고를 보낸다.

## 6. 실제 실행기 시나리오 (C)

### S13. 실행기 기동 검사

**절차**

1. 토큰이 빈 임시 env 파일, 지도 버전이 빈 임시 env 파일로 각각 `ros-fleet-agent.py --env-file`을 실행한다. 임시 파일은 시험 후 삭제한다.
2. 정상 env 파일로 `run-ros-fleet-agent.sh 62b2`를 실행한다.
3. 관제서버 주소를 틀리게(`FLEET_SERVER_URL=http://127.0.0.1:9`) 실행한다.

**합격 기준**

- 1: 종료 코드 2, 누락 항목 메시지만 출력하고 토큰 값은 출력하지 않는다.
- 2: `agent ready: robot=62b2 domain=40` 로그, 화면 62b2가 3초 안에 online.
- 3: 프로세스가 죽지 않고 "server unavailable" 오류가 약 10초에 한 번만 기록된다.

### S14. 실제 위치·수신 시각 일치

**절차**

1. 로봇을 정지 상태로 둔다. `ros2 topic echo /amcl_pose --once`와 `snap`을 같은 시점에 비교한다.
2. 로봇을 손으로 약 30cm 옮기거나 teleop으로 천천히 이동하고, RViz·화면·실제 위치를 비교한다.
3. 1분간 `snap`을 5초 간격으로 기록한다.

**합격 기준**

- 화면 좌표와 `/amcl_pose` x/y 차이 0.01m 이하, yaw 차이 0.02rad 이하(같은 메시지 기준).
- 이동 후 2초 안에 화면 위치가 따라간다. 화면에서 지도 위 위치가 실제 배치와 방향이 맞는다(좌우·상하 반전 없음).
- 1분 동안 online이 유지되고 `received_at` 간격이 약 1초다.

### S15. 로봇 통신 끊김

**절차**: 정지 상태에서 차례로 수행하고 각각 20초 관찰 후 복구한다.

1. 로봇 Wi-Fi를 끈다(또는 공유기에서 로봇 연결 차단).
2. 실행기 프로세스를 `Ctrl+C`로 종료한다.
3. 로봇 전원을 끈다(마지막 순서).

**합격 기준**

- 1: 실행기가 `connected=false`를 보내거나 보고가 끊기며, 화면이 약 3~4초 후 stale, 약 11초 후 offline.
- 2·3: 동일하게 stale → offline.
- 어느 경우에도 도착·정지 확인·이동 완료가 기록되지 않는다. R2 표시 방식을 캡처한다.
- 복구 후 새 위치로 online이 되고, 복구 전 마지막 위치가 새 위치로 대체된다.

### S16. AMCL 위치만 중단 (R1)

**절차**

1. Nav2 action server가 살아 있는 상태에서 `/amcl_pose` 발행만 멈추는 방법을 로봇 담당자와 정한다(예: localization lifecycle 비활성화). 방법과 명령을 결과에 기록한다.
2. 20초간 `snap`과 화면을 기록하고, 화면에서 시작 버튼 상태를 확인한다. **시작 버튼은 누르지 않는다.**

**합격 기준(원칙)**: 위치가 끊긴 로봇은 현재 위치가 확인된 로봇처럼 표시되거나 시작 가능해서는 안 된다.

**예상 현재 결과**: 실행기가 `connected=true, pose=null`을 계속 보내므로 online과 예전 위치가 유지되고 시작 버튼이 활성으로 보일 수 있다. 그렇다면 불합격으로 기록하고, 위치 신선도를 연결 신선도와 분리하는 수정안을 실행기·관제 담당이 합의한다.

### S17. 실행기 재시작 (R3)

**절차**

1. 정지 상태에서 실행기를 종료 → 5초 후 재시작 → online 복귀 시간 기록.
2. 넓은 위치에서 짧은 목표로 주행을 시작한다. `moving` 확인 직후 실행기만 종료하고 3초 뒤 재시작한다. 이때 로봇 동작, Nav2 로그, 화면 이벤트를 기록한다. 이상 동작이 보이면 즉시 화면 정지와 수동 개입을 한다.

**합격 기준**

- 1: 재시작 후 3초 안에 online, 작업 기록 변화 없음.
- 2: 실행기가 멈춘 동안 작업이 도착·실패로 바뀌지 않는다. 재시작 후 같은 목표가 다시 전송되는지, 주행이 끊기거나 새 목표로 바뀌는지를 기록한다. 결과가 `completed`로 끝나려면 Nav2 SUCCEEDED와 위치가 실제로 보고돼야 한다.

### S18. 주행 중 관제서버 중단 후 결과 보고

**절차**: 짧은 목표 주행을 시작하고 `moving`을 확인한 뒤 app 컨테이너를 중지한다. 로봇이 실제로 도착한 것을 눈으로 확인하고 10초 뒤 app을 다시 시작한다.

**합격 기준**

- 서버 중단 동안 로봇이 Nav2 목표를 계속 수행한다(관제 요청과 모터 제어가 분리돼 있음 확인).
- 복구 후 실행기가 보관한 `arrived/succeeded`와 도착 위치를 보고해 작업이 `completed`가 된다. 결과 `received_at`은 복구 이후 시각이다.
- 복구 전까지 화면에는 도착이 표시되지 않는다.

### S19. 세 로봇 동시 보고

전제: 648d(Domain 30)와 eed0(Domain 35)이 준비되어 있어야 한다.

**절차**: 로봇별 실행기 3개를 각각 별도 터미널에서 실행하고 5분간 정지 상태를 유지한다. 그중 한 대의 실행기만 종료했다가 재시작한다.

**합격 기준**

- 세 로봇이 모두 online이고 위치가 각 로봇의 `/amcl_pose`와 일치한다(다른 Domain 위치가 섞이지 않음).
- 한 대의 끊김이 나머지 두 대의 `received_at`과 신선도에 영향을 주지 않는다.
- 5분 동안 서버 오류 응답(4xx/5xx)이 실행기 로그에 없다.

## 7. 결과 기록표

결과는 `대기 / 합격 / 불합격 / 확인 필요` 중 하나로 적는다. 수준은 A/B/C로 적는다.

| 시각 | 시험 | 수준 | 환경(개발/운영) | 대상 로봇 | 결과 | 관찰값(신선도 전이 시각, 좌표 차이 등) | 증거(캡처·로그 위치) | 조치 |
|---|---|---|---|---|---|---|---|---|
|  | S0 | A |  | - | 대기 |  |  |  |
|  | S1 | B |  | 62b2 | 대기 |  |  |  |
|  | S2 | B |  | 62b2 | 대기 |  |  |  |
|  | S3 | B |  | 62b2·648d | 대기 |  |  |  |
|  | S4 | B |  | 62b2 | 대기 |  |  |  |
|  | S5 | B |  | 62b2 | 대기 |  |  |  |
|  | S6 | B |  | 62b2 | 대기 |  |  |  |
|  | S7 | B |  | - | 대기 |  |  |  |
|  | S8 | B |  | 62b2 | 대기 |  |  |  |
|  | S9 | B |  | 62b2 | 대기 |  |  |  |
|  | S10 | B |  | 62b2 | 대기 |  |  |  |
|  | S11 | B |  | 62b2 | 대기 |  |  |  |
|  | S12 | B |  | 62b2·648d | 대기 |  |  |  |
|  | S13 | C |  | 62b2 | 대기 |  |  |  |
|  | S14 | C |  | 62b2 | 대기 |  |  |  |
|  | S15 | C |  | 62b2 | 대기 |  |  |  |
|  | S16 | C |  | 62b2 | 대기 |  |  |  |
|  | S17 | C |  | 62b2 | 대기 |  |  |  |
|  | S18 | C |  | 62b2 | 대기 |  |  |  |
|  | S19 | C |  | 3대 | 대기 |  |  |  |

## 8. 실패 분류와 조치

| 현상 | 우선 확인 | 다음 조치 |
|---|---|---|
| 실행기 401 | env 파일 토큰과 서버 컨테이너 환경변수 일치 여부(값은 출력하지 않고 해시로 비교) | 같은 env 파일로 app 재생성 |
| 실행기 422 반복 | 실행기 로그의 오류 메시지, `motion_state`/`action_result` 조합 | 2절 규칙과 실행기 보고 내용 대조 |
| 계속 offline | 실행기 로그의 server unavailable, `FLEET_SERVER_URL`, 방화벽·포트 | 관제 PC에서 `curl $SERVER/api/health` |
| online인데 위치 없음 | `/amcl_pose` 발행, `ROS_DOMAIN_ID`, 초기 위치 지정 | 03 문서 T1·T2 재확인 |
| 시작 시 `POSE_MISMATCH` | env의 `FLEET_MAP_VERSION`과 실제 `cbs_map.pgm` 해시 | 지도·env 파일 동기화 후 app·실행기 재시작 |
| 화면 위치가 실제와 반전·이동 | 지도 YAML 원점·해상도, `display_map` 응답 | 지도 PNG 재생성, `map.test.js` 확인 |
| 작업이 끝나지 않음 | 실행기 `current_run`, 마지막 보고의 `run_id` | S12 기록 후 R4 처리 방식 합의 |

## 9. 완료 기준

- S0~S11 합격(B 수준): 서버의 상황 보고 규칙이 설계대로 동작함.
- S13~S15, S18 합격(C 수준, 62b2): 04 문서 G5 게이트 "실제 상태와 마지막 수신 시각 표시, 끊김은 stale/offline 처리"의 증거로 사용.
- S12·S16·S17의 관찰 결과와 R1~R7 각각의 처리 결정(수정/수용)을 기록.
- S19는 두 번째·세 번째 로봇 준비 후 6단계 진입 조건으로 수행.
- 결과와 미해결 문제를 README 단계 상태와 실행 기록에 반영.
