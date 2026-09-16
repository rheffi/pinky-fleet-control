# 02. 샘플 관제 — 데이터 규약과 구현 계획

작성·갱신: 2026-09-16 / 상태: F1~F6 구현·검증 완료 (실물 통합은 후속 단계)

상위 문서: [프로젝트 메인](../README.md)

## 1. 개발 계획

목표는 로봇 3대의 상태를 보고 고정 목표를 선택한 뒤, 샘플 작업의 실행·대기·도착·정지 흐름을 한 화면에서 확인하는 것이다. 기존 Docker·Laravel·Vue·MySQL을 이용한다.

- 로봇·지도·목표·작업·이벤트의 관제 내부 데이터 규약을 정한다.
- MySQL에 작업과 이력을 저장하고 Laravel API로 조회·명령 접수를 제공한다.
- 별도 샘플 실행 프로세스가 상태를 변경한다. 브라우저를 닫아도 작업은 유지된다.
- Vue에 지도, 로봇 카드, 목표 선택, 작업 상태, 이벤트를 표시한다.
- 정상 완료, 정지, 중복 요청, 통신 지연, 실행 프로세스 재시작을 검증한다.

이번 단계는 샘플 관제 개발이다. 실물 연결, 실제 SLAM 지도 등록 도구, CBS 실행, 경로 충돌 검증, 자동 목표 배정, 실제 속도 제어는 후속 단계다. 샘플 화면이나 테스트 통과를 실물 충돌 회피의 증거로 사용하지 않는다.

## 2. 구현 전 결정 사항

| 항목 | 현재 상황 | 이번 단계에서 정할 내용 |
|---|---|---|
| 로봇 식별 | eed0=35, 648d=30, 62b2=40 확정 | ID와 ROS Domain을 별도 필드로 유지 |
| 지도 | 실물 공통 지도 미수령 | 샘플 지도와 실제 지도 구분, 좌표 규약 |
| 상태 갱신 | 현재 수동 환경 확인만 있음 | 폴링 간격과 오래된 상태 처리 |
| 작업 범위 | 고정 목적지 우선 | 3대에 각각 목표를 지정하는 작업 한 개 |
| 동시 실행 | 2일 프로젝트의 최소 범위 | 관제 전체에 활성 작업 최대 한 개 |
| 샘플 실행 | 실제 로봇과 알고리즘 미연결 | 결정된 경로·대기를 재생하는 서버 프로세스 |
| 연동 경계 | 팀원 알고리즘·로봇 API 변경 가능 | 관제 내부 규약과 외부 변환부 분리 |

실물 IP·포트, 공통 지도 버전, 위치·도착·정지 응답, 알고리즘 입출력은 담당자 확인이 필요하다. 이 정보가 없어도 샘플 구현은 가능하다. 아래 규약은 **관제 내부 v1 제안**이며 외부 담당자와의 합의 완료를 뜻하지 않는다.

## 3. 권장 결정값과 데이터 규약

### 3.1 기본값

| 항목 | 권장값 |
|---|---|
| 모드 | 서버 설정으로 sample 고정. 응답과 화면에 항상 표시 |
| 화면 주소 | `/` 관제 화면, `/environment` 기존 환경 확인 화면 |
| 상태 갱신 | 1초 폴링, 이전 요청이 끝난 후 다음 요청. 페이지 이탈 시 정리 |
| 신선도 | 서버 수신 시각 기준 3초 초과 stale, 10초 초과 offline. 설정으로 변경 가능 |
| 샘플 실행 | PHP CLI 프로세스 1개, 약 1초 간격. 샘플용 Compose profile로 추가 |
| 동시 작업 | queued/running/stopping/interrupted를 포함해 미해결 작업 최대 1개 |
| 표시 방식 | Vue + SVG, 기존 CSS 활용. 별도 대형 UI·지도 라이브러리 없이 시작 |
| 시간 | API ISO 8601 UTC, 화면 한국 시간. 지속 시간은 초 |
| 좌표 | `x_m`, `y_m`, `yaw_rad`; 거리 m, 각도 rad. 화면 픽셀과 분리 |
| 갱신 순서 | snapshot_revision 단조 증가, 오래된 응답은 화면에 적용하지 않음 |

신선도 값은 관제 표시용 초기 제안이다. 실물 제동 시간이나 충돌 방지 기준이 아니다. 로봇 상태 수신 실패와 브라우저의 관제 API 접속 실패도 별도 표시한다.

### 3.2 객체별 필드

| 객체 | 주요 필드 | 의미 |
|---|---|---|
| 응답 메타 | schema_version, mode, server_time, snapshot_revision | 규약·출처·기준 시각 |
| 로봇 | id, label, ros_domain_id, connection_state, motion_state, pose, received_at, active_run_id | 연결 상태와 움직임 분리 |
| 위치 pose | map_id, map_version, frame_id, x_m, y_m, yaw_rad | 알 수 없으면 null, 임의의 0 좌표로 대체하지 않음 |
| 지도 | id, version, source, frame_id, resolution_m_per_pixel, origin, width_px, height_px, image_url | origin은 x_m/y_m/yaw_rad. sample 지도를 별도로 제공 |
| 목표 | id, label, map_id, map_version, pose | 미리 등록한 고정 위치. 요청에서는 goal_id로 선택 |
| 작업 run | id, mode, map_id, map_version, status, created_at, started_at, finished_at, error | 3대의 목표를 묶는 단위 |
| 로봇별 작업 | run_id, robot_id, goal_id, goal_pose, state, planned_path, path_source, stop_ack_at, result | 생성 당시 목표·경로를 보존 |
| 경로 지점 | index, x_m, y_m, yaw_rad, wait_s | 샘플 재생용. 알고리즘의 step 번호를 초로 해석하지 않음 |
| 명령 | id, request_id, type, run_id, payload_hash, status, created_at | 요청 재시도와 중복 실행 방지 |
| 이벤트 | id, run_id, robot_id(nullable), type, message, occurred_at, mode | 상태 전환·명령·오류 기록. 폴링마다 기록하지 않음 |

ROS Domain은 로봇 IP나 포트가 아니다. 샘플 설정에 임의의 실물 IP를 넣지 않는다. 배터리·속도처럼 수신되지 않은 값은 만들지 않는다.

지도 데이터는 fixture 파일로 시작한다. 이전 25cm 격자나 폼 크기를 실측 지도 값으로 쓰지 않는다. 서버 좌표를 SVG로 바꿀 때 지도 origin의 회전·이동, 해상도, 이미지 Y축 반전을 한 변환 함수에서 처리한다. 지도 버전이 다른 위치·목표·경로는 같은 지도에 겹쳐 표시하지 않는다.

샘플 경로는 `path_source=fixture`로 표시한다. 경로의 대기는 미리 작성한 시나리오이며, CBS가 계산한 충돌 없는 경로라고 표시하지 않는다. 실제 알고리즘의 격자 좌표·단계·대기 의미는 후속 변환부에서 합의한다.

### 3.3 상태 규칙

**연결:** `unknown / online / stale / offline`.

**로봇 움직임:** `unknown / idle / moving / waiting / arrived / stopping / stopped / error`.

**작업 전체:**

```text
queued → running → completed
queued/running → stopping → cancelled
queued/running/stopping → interrupted
queued/running → failed   (샘플 실행기가 다른 로봇도 정지 처리한 후)
```

- 작업 생성 HTTP 201은 DB에 접수했다는 뜻이다. 실제 출발·도착을 뜻하지 않는다.
- 3대 모두 해당 작업에서 도착 확인된 경우만 completed가 된다.
- 정지 HTTP 202는 요청 접수다. 미완료 로봇 모두 정지 확인된 후 cancelled가 된다. 이미 도착한 로봇의 도착 결과는 유지한다.
- 샘플에서는 실행 프로세스가 도착·정지를 확인한다. 결과에 sample 출처를 남긴다.
- stale/offline은 마지막 주행 상태를 덮어쓰지 않는다. 예: `이동 중 · 상태 수신 지연`.
- 샘플 프로세스 heartbeat가 끊기면 화면은 경고하고 새 작업을 막는다. 조회 API는 DB 상태를 변경하지 않는다.
- 실행 프로세스 재시작 시 이전 미완료 작업을 자동 재개하지 않고 interrupted로 보존한다. 샘플 정지 요청을 받아 모든 로봇을 stopped로 확인한 뒤 cancelled로 종료할 수 있다. 이때까지 다음 작업은 막는다.
- 실물의 정지 확인 조건과 통신 단절 대응은 로봇 담당자가 검증한 별도 정책이 필요하다.

### 3.4 API 초안

기존 `/api/health`는 유지하고 관제에는 `/api/v1`을 사용한다.

| 메서드·주소 | 목적 | 결과 |
|---|---|---|
| GET `/api/v1/bootstrap` | 모드, 로봇 식별 정보, 지도, 목표, 초기 설정 | 200 |
| GET `/api/v1/snapshot` | 3대 상태, 활성 작업, 실행기 신선도 | 일관된 DB 스냅샷, 200 |
| POST `/api/v1/runs` | 3대 목표를 지정해 작업 생성 | 201, queued |
| GET `/api/v1/runs?limit=20` | 최근 작업 결과 | 200, 제한된 개수 |
| GET `/api/v1/runs/{id}` | 작업·로봇별 결과·계획 경로 | 200 |
| POST `/api/v1/runs/{id}/stop` | 활성 작업에 참여한 3대 전체 정지 요청 | 202, stopping |
| GET `/api/v1/runs/{id}/events?after_id=0&limit=100` | 작업 이벤트 증분 조회 | 200, 다음 커서 |

화면의 전체 정지는 현재 활성 작업의 세 로봇에 대한 요청이다. 물리 비상정지 장치로 표현하지 않는다. 작업 외 수동 주행은 이번 범위에 없다.

생성 요청 예시(모든 ID·지도는 샘플 데이터):

```json
{
  "request_id": "66794df7-49fc-4b26-984d-f2180f40aa92",
  "map_id": "sample-map",
  "map_version": "1",
  "assignments": [
    { "robot_id": "eed0", "goal_id": "goal-a" },
    { "robot_id": "648d", "goal_id": "goal-b" },
    { "robot_id": "62b2", "goal_id": "goal-c" }
  ]
}
```

- 시작 가능 조건: 정확히 3대, 중복 로봇·중복 목표 없음, 유효한 지도 버전·목표, 신선한 상태와 위치, 활성 작업 없음, 선택 조합의 샘플 시나리오 존재. 화면은 지원하는 고정 조합을 안내한다.
- 지원하지 않는 임의 목표 조합에서 직선 경로를 만들어 성공 처리하지 않는다. 샘플 시나리오 없음으로 거절한다.
- 동일 request_id와 동일 payload 재요청은 기존 명령·작업을 반환한다. 다른 payload 재사용은 409다. 응답을 못 받은 요청의 재시도에는 같은 request_id를 쓴다.
- 활성 작업 경쟁은 MySQL 트랜잭션과 단일 fleet_state 행 잠금으로 막고, request_id에 unique 제약을 둔다. 버튼 비활성화만으로 중복을 막지 않는다.
- 잘못된 입력 422, 상태 충돌 409, 미존재 404, DB 불가 503. 오류 형식은 `error.code/message/fields`로 통일하며 내부 예외를 노출하지 않는다.
- sample 모드만 허용하고 실제 로봇 명령을 보내는 경로는 만들지 않는다. 기존 loopback 공개 범위를 유지하며, 변경 요청은 동일 출처 세션·CSRF 검증을 적용한다. 현장 공개와 사용자 인증은 배포 단계에서 정한다.

### 3.5 저장과 실행 책임

MySQL 테이블은 `robots`, `fleet_state`, `runs`, `run_robots`, `commands`, `run_events`를 제안한다. 지도·목표·샘플 경로는 버전이 있는 fixture로 관리하고, 작업 생성 시 필요한 값을 복사해 과거 결과를 보존한다.

```text
Vue ──조회·요청── Laravel API ──저장── MySQL
                                      ↑
                      별도 샘플 실행기(상태·위치·이벤트 갱신)
```

샘플 실행기는 DB에 기록된 작업을 읽고 한 번에 한 단계씩 반영한다. GET 요청이나 Vue 타이머로 작업 상태를 진행시키지 않는다. CLI 중복 실행 방지 잠금과 heartbeat를 둔다. 이 프로세스는 로봇 모터 제어기가 아니다.

샘플 생성은 명시적 seed 명령으로 수행하고, 재실행 시 과거 작업·로봇 현재 상태를 덮어쓰지 않는다. 작업 초기화가 필요하면 별도 명시적 명령으로 처리한다.

외부 연결은 후속 단계에서 동일한 관제 서비스에 로봇 상태·명령 응답을 변환하는 어댑터를 붙인다. 현재 Pinky API에 위 필드가 존재한다고 가정하지 않는다.

## 4. 권장값을 반영한 구현 순서

### 4.1 화면 구성

```text
┌ 프로젝트 / SAMPLE 배지 / 서버 연결 / 마지막 갱신 ┐
├─────────────────────┬──────────────────────┤
│ 샘플 지도            │ eed0 · Domain 35     │
│ 로봇 3대 위치·방향   │ 648d · Domain 30     │
│ 목표와 계획 경로     │ 62b2 · Domain 40     │
│ 실측 지도 아님 표시  │ 상태·좌표·목표 선택  │
├─────────────────────┴──────────────────────┤
│ 작업 시작 / 전체 정지 요청 / 진행·결과       │
├────────────────────────────────────────────┤
│ 최근 작업 / 선택 작업 이벤트                │
└────────────────────────────────────────────┘
```

색뿐 아니라 로봇 ID와 상태 글자를 함께 표시한다. API 실패 시 마지막 값과 갱신 시각을 남기고 오래된 정보임을 표시한다. 요청 중 중복 클릭을 막고 서버 오류를 표시한다. 시작은 신선한 상태에서만 허용하며, 정지는 상태 지연 중에도 요청할 수 있도록 한다.

### 4.2 작업 목록

| ID | 작업 | 완료 기준 |
|---|---|---|
| F1 | 규약을 모델·migration·fixture·seed로 구현 | 로봇 ID/Domain 정확, 샘플 지도·목표 로딩, 기존 환경 검사 보존 |
| F2 | 조회 API와 생성·정지 API 구현 | 입력·중복·상태 경쟁 검증, DB에 작업·명령·이벤트 저장 |
| F3 | 샘플 CLI 실행기와 Compose profile 추가 | 브라우저 없이 진행, 대기·도착·정지 확인, 재시작 자동 재개 없음 |
| F4 | Vue 관제 화면과 지도 좌표 변환 구현 | 3대·목표·경로·작업 표시, 갱신·오류·정지 흐름 동작 |
| F5 | 실패 상황·DB 보존·브라우저 통합 검증 | 아래 완료 기준 충족, 테스트·빌드 성공 |
| F6 | 이 문서와 README 결과 갱신 | 명령·실행 결과·제약·팀원 인계 내용 기록 |

F1~F6은 이번 구현 묶음이다. 구현 중 실제 지도나 외부 API 규약이 도착하면 영향을 확인하고 이 문서를 갱신한다. 별도 후속 계획 문서를 미리 여러 개 만들지 않는다.

### 4.3 검증 기준

- 정상 시나리오: 3대 목표 선택 → 접수 → 이동·대기 → 모두 도착 → completed, 이벤트·결과 조회.
- 정지 시나리오: 이동 중 요청 → stopping 표시 → 샘플 실행기 확인 → cancelled. 접수 즉시 정지 완료로 표시하지 않음.
- 중복·동시성: 같은 요청 재시도는 한 작업, 서로 다른 동시 시작도 활성 작업 한 개. MySQL에서 실제 경쟁 요청을 확인.
- 검증 오류: 로봇 누락·중복, 존재하지 않는 목표, 지도 버전 불일치, 지원하지 않는 목표 조합 거절.
- 갱신 실패: API 실패 및 샘플 실행기 중지를 각각 시험. 신선도 경고, 새 작업 차단, 무단 성공 처리 없음.
- 복구: 브라우저 새로고침·종료로 작업이 초기화되지 않음. 실행기 재시작 시 interrupted 보존 후 정지 처리 가능.
- 좌표: 원점·Y축·회전을 포함한 알려진 기준점으로 변환을 검증하고 다른 지도 버전 표시 거절.
- 기록: 전체 서비스 재시작 뒤 완료 작업·이벤트 보존. seed 재실행으로 이력 소실 없음.
- PHPUnit은 상태 전이·멱등성·오류·권한 경계를 검사. Pint와 Vue 빌드, 실제 브라우저 핵심 흐름 확인. 샘플 시험과 실물 시험을 구분해 기록.

### 4.4 팀원에게 받을 항목

| 담당 | 받을 항목 | 필요한 시점 |
|---|---|---|
| 지도 | 지도 이미지·YAML, 최종 버전, 시작·목표 후보, 좌표 기준 | 실제 지도 표시·실물 목표 등록 전 |
| 로봇 | IP·포트, API 요청/응답 샘플, 위치 시각·frame, 목표 결과·취소·정지 확인 방법 | 실물 한 대 연결 전 |
| 알고리즘 | 3대 입력·출력 JSON, 좌표와 격자 변환, 단계·대기 의미, 경로 없음 응답 | 알고리즘 연결 전 |
| 실행·Nav2 | 계획 경로·대기 준수 방식, 속도 제한, 지연·통신 단절 시 정책 | 두 대 이상 주행 전 |

## 5. 실행 상황

| 항목 | 상태 | 근거 |
|---|---|---|
| 현재 환경·코드 확인 | 완료 | 기존 Docker 4서비스 구성, health API, 환경 확인 Vue 코드 검토 |
| 이번 계획·규약 | 초안 작성 | 본 문서, 권장값 기준. 외부 담당자 합의 전 |
| F1 데이터·fixture | 구현 | 6개 테이블 migration 실행, FleetSeeder 실행. 샘플 로봇 ID/Domain 확인 |
| F2 API | 구현·HTTP 일부 검증 | 조회·생성·정지·이벤트. 동시 요청·재시도·오류 코드 확인 |
| F3 샘플 실행기 | 완료 | sample profile, 중복 실행 차단, stale/offline, 재시작 interrupted·위치 보존·정지 종료 확인 |
| F4 Vue | 구현·브라우저 확인 | 3대·지도·목표·경로·이벤트·작업 이력 표시. 정상 완료·정지 버튼 확인 |
| F5 자동 테스트·빌드 | 완료 | PHP 전체 17개(94 assertions), JS 4개, Pint 74파일, 배포 빌드·실행기·전체 서비스 재시작 검증 |
| F6 결과 기록 | 완료 | 실행 명령·근거·테스트 격리 오류와 수정·한계 기록 |
| 실물·CBS·Ubuntu | 미수행 | 이번 샘플 검증과 구분 |

### 5.1 구현 위치와 구체화한 규약

- [migration](../database/migrations/2026_09_16_010000_create_fleet_tables.php), [FleetSeeder](../database/seeders/FleetSeeder.php): 6개 테이블과 3대 식별 데이터. seed는 기존 데이터를 덮어쓰지 않는다.
- [SampleFixture](../app/Fleet/SampleFixture.php): 실측과 무관한 6m × 4m 샘플 도면, 로봇별 동·서 목표 6개, 세 개 평행 통로의 지정 경로. 동쪽 또는 서쪽으로 모두 이동하는 두 조합만 지원한다.
- 샘플 목표 ID는 예시 문서의 goal-a/b/c 대신 `eed0-right`, `648d-right`, `62b2-right` 및 각 `-left`로 구체화했다.
- [FleetService](../app/Fleet/FleetService.php): fleet_state 행 잠금, 요청 ID 중복 방지, 작업 전이, heartbeat, 도착·정지 확인, 재시작 복구 로직.
- [FleetController](../app/Http/Controllers/FleetController.php), [웹 라우트](../routes/web.php): 세션·CSRF를 사용하는 7개 /api/v1 API. 기존 /api/health는 유지.
- [FleetMutation](../app/Http/Middleware/FleetMutation.php): 변경 요청의 CSRF 토큰과 Origin 검증.
- [SimulateFleet](../app/Console/Commands/SimulateFleet.php): MySQL 연결 잠금으로 중복 실행 제한, 1초마다 상태 갱신. 외부 로봇 명령 없음.
- [FleetDashboard](../resources/js/FleetDashboard.vue), [지도 좌표 변환](../resources/js/fleet/map.js): 관제 화면, 샘플 표시, 요청 재시도 ID 보존, 마지막 수신 시각, 이력 선택.
- 기존 환경 화면은 [EnvironmentView](../resources/js/EnvironmentView.vue)로 보존하고 /environment에서 제공한다.

### 5.2 실제 검증과 수정 기록

| 검사 | 결과 |
|---|---|
| migration·seed | Docker 권한 변경 전 실제 실행 성공 |
| 브라우저 정상 주행 | 샘플 작업 61d079c4, 세 로봇 도착·대기 이벤트·completed 확인 |
| 브라우저 정지 | 샘플 작업 b610221c, 전체 정지 요청 후 cancelled 및 정지 결과 확인 |
| MySQL 동시 시작 | 첫 검사에서 201/500 발견. 업무 예외를 로그 보고 대상에서 제외한 후 재검사 201/409 통과 |
| 같은 요청 ID 재시도 | 기존 작업 ID와 replayed=true, HTTP 200 |
| 정지 접수 | HTTP 202와 stopping. 정지 확인 전에 cancelled를 반환하지 않음 |
| 입력·요청 보호 | 혼합 목표 422, CSRF 누락 419, 다른 Origin 403, 없는 작업 404 |
| Vue 콘솔 | 정상 완료 확인 탭에서 error 로그 없음 |
| 화면 폭 | 브라우저 폭 794px, 문서 폭 779px, 로봇 카드 3개 확인 |
| 스크린샷 | 브라우저 도구 캡처 실패. 픽셀 기반 시각 검증은 미완료 |
| PHPUnit·JS·Pint·배포 빌드 | PHP 17개(94 assertions), JS 4개, Pint 74파일, Vite 빌드 성공 |
| 실행기 중지·재시작, 전체 서비스 재시작 보존 | 실제 5개 컨테이너 stop/start 후 새 작업·이벤트·환경 검증값 보존 확인 |

초기 동시 요청 오류는 정상적인 업무 예외를 보고하는 경로에서 발생했다. FleetError는 명시적인 오류 응답만 반환하도록 제외했으며, 앱 로그를 컨테이너 stderr로 바꿔 파일 쓰기 권한 의존을 줄였다. 정확한 컨테이너 로그는 Docker 접근 복구 후 필요 시 확인한다.

실행 명령에서 Pint의 --dirty는 Git 초기화 전이라 사용할 수 없었다. 재개 시 일반 Pint 명령을 사용한다. 화면 코드 검토에서 이력 상세 로딩 전 robots 접근 오류와 재접속 시 활성 목표 표시도 수정했다.

중간에 세션 제한 모드로 Docker 설정·named pipe 접근이 거부되어 HTTP·브라우저로 먼저 검증했다. 이후 사용자가 전체 접근을 복구하여 나머지 컨테이너 검증을 완료했다. 접근 제한을 Docker 고장으로 판단하지 않는다.

증거: `work/fleet-race-result.json`, `work/fleet-http-guards.json` (Git 제외). HTTP 동시성 검사는 [PowerShell 스크립트](../tests/integration/fleet-race.ps1)로 재현 가능하다. 실행하면 샘플 작업 한 개를 생성하고 정지 요청하므로 활성 작업이 없을 때 실행한다.

### 5.3 실행·검증 명령

프로젝트 폴더에서 Docker 접근 권한이 있는 터미널로 실행한다.

```powershell
# 최초 구성 또는 업데이트
docker compose up -d --wait
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --class=FleetSeeder --force
docker compose --profile sample up -d simulator

# PHP 코드를 바꾼 뒤 실행기는 재시작해야 변경 내용이 반영된다.
# 미완료 작업이 있으면 interrupted로 남고 자동 재개하지 않는다.
docker compose --profile sample restart simulator

# 자동 검증 (PHP 테스트는 tests/bootstrap.php와 DB 차단 장치를 유지할 것)
docker compose exec -T app php artisan test --compact
docker compose exec -T app vendor/bin/pint
docker compose exec -T app vendor/bin/pint --test
docker compose exec -T node node --test tests/js/map.test.js tests/js/api.test.js

# 실제 MySQL에 HTTP 동시 요청: 활성 작업 없을 때
./tests/integration/fleet-race.ps1
# 같은 목적의 컨테이너용 Node 스크립트도 제공
# docker compose exec -T node node tests/integration/fleet-race.mjs

# 샘플 작업 생성·실행기 중지/재시작·전체 서비스 재시작을 실제 수행한다.
# 활성 작업이 없을 때 실행. 실행 중 관제 연결은 잠시 끊긴다.
./tests/integration/fleet-recovery.ps1

# 개발 서버 없는 빌드 확인 후 개발 모드 복귀
docker compose stop node
docker compose run --rm --no-deps node npm run build
docker compose up -d node

# 샘플 실행기를 포함한 상태·로그·정지
docker compose --profile sample ps
docker compose --profile sample logs --tail=30 simulator
docker compose --profile sample stop
```

복구 검증은 실행기 중지 → stale/offline → 재시작 → interrupted·자동 재개 없음 → 정지 요청으로 종료 순서로 통과했다. 새 종료 작업·이벤트를 기록한 뒤 전체 서비스 stop/start 후 동일함을 비교했다.

### 5.4 테스트 DB 격리 오류와 재발 방지

최초 PHPUnit 실행에서는 XML의 env force=true만으로 Docker에서 주입한 DB_CONNECTION·DB_DATABASE가 모두 대체되지 않았다. PHP의 $_SERVER에 남아 있던 MySQL 설정을 Laravel이 읽어 RefreshDatabase가 개발 DB를 초기화했다. 기존 샘플 작업 이력과 environment_checks 값이 사라진 것을 확인했다. 이전 이력의 복구본은 없어 복원하지 못했다. 이 최초 통과 결과를 안전한 테스트 격리의 증거로 사용하지 않는다.

수정:

1. [tests/bootstrap.php](../tests/bootstrap.php)에서 getenv·$_ENV·$_SERVER를 모두 testing / sqlite / :memory:로 지정한 후 autoload한다.
2. [Tests/TestCase](../tests/TestCase.php)의 createApplication에서 실제 설정을 검사한다. 메모리 SQLite가 아니거나 DB_URL이 설정되어 있으면 RefreshDatabase 실행 전에 예외로 차단한다.
3. 실제 테스트에서 DB 연결·DB 이름·$_SERVER·APP_ENV를 검사하는 항목을 추가했다.
4. FleetSeeder로 샘플 로봇을 다시 준비하고 새 환경 검증값을 저장했다. 수정 후 PHP 테스트 실행과 전체 서비스 재시작 뒤에도 동일 검증값이 유지됨을 확인했다.

예방 설정을 삭제하거나 PHPUnit을 임의의 개발 DB 대상으로 실행하지 않는다. tests/Feature/FleetTest는 SQLite 메모리 테스트이고, tests/integration 스크립트는 명시적으로 현재 샘플 MySQL에 새 작업을 생성하는 검사다.

### 5.5 최종 검증 근거와 현재 상태

| 검증 | 결과 |
|---|---|
| PHP 자동 테스트 | 17 passed, 94 assertions, 수정 후 APP_ENV=testing |
| JS 테스트 | 좌표 변환 2개 + 통신/비JSON 오류 2개 통과 |
| Pint | 74파일 PASS |
| Vue 배포 빌드 | 15 modules, JS 80.63kB / CSS 14.40kB, Vite 8.3.0 |
| Vite 중지 상태 | HTML·JS·CSS 200, public/hot 없음, 5173 참조 없음, 실제 샘플 주행 완료 |
| API 중단 | app 컨테이너 중지 시 화면 경고·마지막 정보 유지·시작 버튼 차단. 복구 후 자동 조회 재개 |
| 중복 실행기 | 두 번째 fleet:simulate 실행이 오류 코드 1로 거부됨 |
| 실행기 복구 | 4초 후 stale, 11초 후 offline. 재시작 후 interrupted, 위치 변화 없음. 새 작업 차단 후 정지 종료 |
| 전체 재시작 | 새 작업 6e2afb8f 및 이벤트, 환경 검증값 유지 |
| 앱 주소 변경 | Nginx 설정 문법 통과. app IP 172.19.0.3 → 172.19.0.6 변경 후 web 재시작 없이 API·DB 정상 |
| 실제 DB 동시성 재검사 | Node HTTP 검사에서 409/201, 같은 요청 재조회 200, 정지 접수 202 |
| 빌드 화면 정상 주행 | 새 샘플 작업 cd7919f8가 completed로 종료 |

근거 파일: work/fleet-recovery-result.json, work/fleet-race-result.json, work/fleet-http-guards.json, work/nginx-dynamic-dns-result.json. 초기 브라우저 검사의 이전 작업 ID는 5.4 오류로 DB에 남아 있지 않으며, 새 검증 결과와 구분한다.

전체 재시작 검증 뒤 app 컨테이너를 다시 시작했을 때 Nginx가 시작 시 해석한 이전 컨테이너 IP를 계속 사용해 502를 반환하는 문제를 발견했다. [Nginx 설정](../docker/nginx/default.conf)에 Docker 내부 DNS `127.0.0.11` resolver와 변수 기반 FastCGI 대상을 적용했다. 검증에서는 app 컨테이너 IP를 강제로 바꾸고 web 컨테이너는 재시작하지 않은 채 `/api/health`의 `status=ok`, `database=connected`를 확인했다.

스크린샷 도구가 캡처에 실패해 픽셀 기반 디자인 검수는 하지 못했다. DOM 렌더링·버튼 동작·가로 넘침 확인과 실제 HTTP·상태 검증을 수행했다. 실물 충돌 회피, 실제 지도 좌표 일치, Ubuntu 배포, 인터넷 차단 시연은 아직 검증하지 않았다.

개발을 이어갈 수 있도록 최종적으로 app·mysql·web·node·simulator 5개 서비스를 실행했다. 마지막 회귀 검사에서도 PHP 17개(94 assertions), JS 4개, Pint 74파일, 환경 검증값 유지가 통과했다. 다음 작업은 Ubuntu 배포 구성과 실제 로봇 한 대의 상태 API·지도 규약 확인이다.
