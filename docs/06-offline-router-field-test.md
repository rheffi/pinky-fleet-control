# 06. 오프라인 공유기 현장 통합 시험 시나리오

갱신: 2026-09-16

## 1. 목표와 범위

전원만 연결한 공유기 하나에 **관제 노트북과 PinkyPro 3대(62b2·648d·eed0)**를 연결하고, 인터넷이 없는 상태에서 노트북의 `http://localhost:8080` 관제 화면으로 명령을 내려 실제 주행·정지·상태 보고를 검증한다.

- 인터넷은 **처음부터 없다고 가정**한다. 시험 중 어떤 단계도 외부 다운로드·클라우드·NTP에 의존하지 않는다.
- 이 문서는 시나리오 작성 단계다. **아직 실행하지 않았다.** 결과는 8절에 기록한다.
- 상황 보고 세부 판정은 [05 문서](05-status-reporting-test.md), 단일 로봇 주행 안정화는 [03 문서](03-single-robot-navigation-test.md)를 따른다.

### 1.1 현재 관제 기능으로 가능한 범위 (코드 기준)

| 항목 | 현재 동작 | 시험 영향 |
|---|---|---|
| 주행 방식 | 로봇별 고정 목표 1개(`FLEET_ROBOT_<ID>_GOAL_*`)로 Nav2 `NavigateToPose` 실행 | 목표 좌표를 현장에서 실측해 env에 넣어야 시작 가능 |
| 동시 주행 | 활성 작업이 있으면 다른 로봇 시작 거부(`ACTIVE_RUN`) | **한 번에 한 대씩 순차 주행**만 시험. 공용 통로 대기·통과 조정은 범위 밖 |
| 경로 | Nav2 자체 계획(`path_source: nav2`). CBS 알고리즘 미연결 | 알고리즘 경로 대조는 범위 밖 |
| 정지 | 화면 정지 → 실행기가 Nav2 목표 취소 → 취소 결과 수신 시 "정지 완료" | **물리 비상정지가 아님.** 사람이 로봇을 잡을 준비 필수 |
| 초기 위치 | 관제·실행기가 보내지 않음(`set_initial_pose: false`) | 로봇마다 RViz `2D Pose Estimate`로 지정 |
| 실행기 | 노트북에서 로봇별 프로세스 1개, 서비스 등록 없음 | 노트북 재부팅 후 수동 재실행 필요 |

## 2. 네트워크 구성

```text
                 [공유기]  WAN 미연결 · DHCP 주소 예약
        ┌───────────┬──────────┬──────────┬──────────┐
  관제 노트북      62b2       648d       eed0     (다른 기기 연결 금지)
  (유선 권장)     Domain 40  Domain 30  Domain 35
  ├ Docker: web :8080 / app / mysql
  ├ ros-fleet-agent × 3  (ROS_DOMAIN_ID 40/30/35 각각)
  └ RViz (초기 위치 지정용, 도메인별로 실행)
```

- 관제 화면은 노트북에서 `http://localhost:8080`으로 연다. 실행기도 노트북에서 `http://127.0.0.1:8080`으로 보고한다.
- 로봇 ↔ 노트북 통신은 ROS 2 DDS(UDP 멀티캐스트 탐색 + 유니캐스트)만 사용한다. 로봇이 관제 HTTP API를 직접 호출하지 않는다.
- 노트북 유선 포트 `enp130s0`이 있으므로 공유기 LAN 포트에 **유선 연결을 권장**한다. Wi-Fi 대역폭을 로봇에 남기고 노트북 쪽 지연을 줄인다.

### 2.1 주소표 (현장에서 작성)

비밀번호·관리자 계정은 이 표나 Git에 적지 않는다.

| 장비 | 연결 | MAC | 예약 IP | ROS_DOMAIN_ID | 확인 |
|---|---|---|---|---|---|
| 공유기 | - | - |  | - |  |
| 관제 노트북 | 유선 / Wi-Fi |  |  | 40·30·35 (실행기별) |  |
| 62b2 | Wi-Fi |  |  | 40 |  |
| 648d | Wi-Fi |  |  | 30 |  |
| eed0 | Wi-Fi |  |  | 35 |  |

## 3. 사전 준비 (인터넷이 있을 때 끝낼 것)

현장에서는 설치·다운로드를 할 수 없다. 아래를 모두 확인한 뒤 현장으로 이동한다.

| ID | 항목 | 확인 방법 | 완료 |
|---|---|---|---|
| P1 | 운영 Docker 이미지 3개가 노트북에 있음 | `docker image ls \| grep -E 'pinky-fleet-control-(app\|web)\|mysql'` | |
| P2 | 이미지 백업 tar와 SHA-256 생성 | 04 문서 U4 명령 | 2026-09-16 새 지도(`b82b34d23d60`) 기준 재생성 완료 |
| P3 | ROS 2 Jazzy·Nav2·PinkyPro 워크스페이스 빌드 | `ros2 pkg prefix pinky_navigation` | |
| P4 | Pinky Studio 설치, Bluetooth 동작 | 앱 실행·로봇 검색 | |
| P5 | `jq`·`curl` 설치 (05 문서 스크립트용) | `which jq curl` | |
| P6 | 세 로봇의 지도·Nav2 파라미터가 프로젝트 원본과 같음 | 각 로봇에서 `sha256sum` 후 `map/cbs_map.pgm`과 비교. 사전에 못 했으면 현장 C6-0에서 복사 | |
| P7 | `deploy/production.env`의 `FLEET_MAP_VERSION`이 현재 지도 해시와 일치 | 해시 앞 12자리 비교 | `b82b34d23d60` 반영·운영 이미지 재빌드 완료 |
| P8 | 코드·문서 최신본 push 완료 (현장에서 Git 원격 사용 불가) | `git status -sb`에 ahead 없음 | |
| P9 | 공유기 관리자 페이지 접속 방법·초기화 방법 확보 | 제조사 설명서 오프라인 사본 | |
| P10 | 노트북 외부 Wi-Fi 자동 연결 해제 계획 | 시험 중 인터넷이 몰래 연결되면 "오프라인 검증" 무효 | |

## 4. 현장 구성 절차

### C1. 공유기 설정

1. WAN 케이블을 연결하지 않고 전원만 연결한다.
2. 관리자 페이지에서 다음을 확인·설정한다.
   - DHCP 사용, 노트북·로봇 3대 **주소 예약**.
   - **AP 격리(클라이언트 격리, 게스트 네트워크) 해제.** 켜져 있으면 DDS 탐색이 실패한다.
   - 멀티캐스트/IGMP snooping으로 멀티캐스트가 차단되지 않는지 확인.
   - 로봇 무선 모듈이 지원하는 대역(2.4GHz/5GHz)의 SSID 사용. 대역을 확인하지 못했다면 2.4GHz로 시작.
3. 다른 휴대폰·노트북이 이 공유기에 붙지 않게 한다.

**통과 기준**: 공유기에 노트북 외 알 수 없는 기기가 없다.

### C2. 노트북 연결과 오프라인 확인

```bash
nmcli device status                 # 유선 또는 공유기 SSID만 connected
ip route                            # 기본 경로가 공유기 방향인지
ping -c 2 <공유기 IP>               # 성공해야 함
ping -c 2 -W 2 8.8.8.8              # 실패해야 함 (인터넷 없음)
getent hosts github.com || echo "DNS 없음"
```

- 기존 인터넷 Wi-Fi 프로필의 자동 연결을 끈다: `nmcli connection modify "<기존 SSID>" connection.autoconnect no`
- 노트북은 NTP 동기화가 불가능해진다. 시각은 현재 값을 기준으로 삼고 `timedatectl` 결과를 기록한다.

**통과 기준**: 공유기 ping 성공, 외부 IP ping 실패.

### C3. 관제서버 기동 (로컬 이미지만 사용)

```bash
cd ~/project/pinky-fleet-control
docker compose --env-file deploy/production.env -f compose.prod.yaml \
  up --detach --pull never --no-build --wait
docker compose --env-file deploy/production.env -f compose.prod.yaml ps
curl -s http://localhost:8080/api/health | jq .
```

브라우저에서 `http://localhost:8080`을 열고 개발자 도구 Network 탭에서 외부 도메인 요청이 없는지 확인한다.

**통과 기준**: 세 서비스 healthy, health `status: ok`, 화면에 지도와 로봇 3대 카드(모두 "연결 끊김") 표시, 외부 요청 실패로 인한 빈 화면·긴 로딩 없음.

### C4. 로봇 네트워크·Domain 설정

로봇 한 대씩 수행한다. Pinky Studio는 BLE로 동작하므로 인터넷이 필요 없다.

1. 로봇 전원 → Pinky Studio **Pinky 검색**에서 `pinky_<ID>` 선택.
2. **WiFi 설정**에서 현장 공유기 SSID 선택·연결. (기존 문서의 "인터넷 가능 Wi-Fi" 조건은 인터넷 없는 SSID에서도 설정되는지 이 단계에서 확인해 결과를 기록한다.)
3. 표시된 IP가 주소 예약과 같은지 확인.
4. **ROS2 Domain 설정**: 62b2=40, 648d=30, eed0=35.
5. 노트북에서 `ping -c 3 <로봇 IP>`.

**통과 기준**: 세 로봇 IP가 주소표와 일치하고 노트북에서 ping 응답. Domain 값이 겹치지 않음.

### C5. 로봇 시각 맞추기

인터넷이 없으면 로봇 시계가 틀어져 있을 수 있다. 관제 판정은 서버 시각을 쓰지만, 노트북 RViz의 TF·센서 표시는 시각 차이에 영향을 받는다.

```bash
date -u +%s                          # 노트북
ssh pinky@<로봇 IP> 'date -u +%s'    # 로봇, 차이를 기록
# 차이가 1초를 넘으면 (로봇 담당자 동의 후)
ssh -t pinky@<로봇 IP> "sudo date -u -s @$(date -u +%s)"
```

**통과 기준**: 노트북과 각 로봇의 시각 차이 1초 이하. 맞추지 못했다면 차이를 기록하고 RViz TF 경고를 감안한다.

### C6-0. 로봇·노트북 지도 동기화

로봇 IP는 **관제서버 설정에 입력하지 않는다.** 관제서버와 실행기는 IP가 아니라 `ROS_DOMAIN_ID`로 로봇을 찾는다. IP는 이 단계의 지도 복사(scp)·ssh·ping에만 사용한다. 같은 공유기 내부 통신이므로 인터넷 없이 동작한다.

노트북에서 로봇마다 실행한다(C4에서 확인한 IP 사용, ssh 비밀번호는 직접 입력).

```bash
cd ~/project/pinky-fleet-control
sha256sum map/cbs_map.pgm | cut -c1-12                         # 기준값 (현재 b82b34d23d60)
scp map/cbs_map.pgm map/cbs_map.yaml pinky@<로봇 IP>:/home/pinky/
ssh pinky@<로봇 IP> 'sha256sum /home/pinky/cbs_map.pgm | cut -c1-12; head -1 /home/pinky/cbs_map.yaml'
```

노트북 RViz(C8)가 워크스페이스 지도를 쓴다면 노트북 쪽도 맞춘다.

```bash
cp map/cbs_map.pgm map/cbs_map.yaml ~/pinky_pro/src/pinky_pro/pinky_navigation/map/
cd ~/pinky_pro && colcon build --packages-select pinky_navigation
```

이미 Nav2가 떠 있는 로봇은 C6에서 **반드시 재시작**해야 새 지도를 읽는다.

**통과 기준**: 세 로봇과 노트북 지도 해시가 `map/cbs_map.pgm`과 같고, yaml 첫 줄이 `image: cbs_map.pgm`이다.

### C6. 로봇 노드 실행

각 로봇에서 터미널 2개를 연다(`<D>`는 로봇 Domain). 명령은 03 문서 4.1·4.2와 같다.

```bash
# 터미널 1
source /opt/ros/jazzy/setup.bash && source ~/pinky_pro/install/setup.bash
export ROS_DOMAIN_ID=<D>
ros2 launch pinky_bringup bringup_robot.launch.xml

# 터미널 2
source /opt/ros/jazzy/setup.bash && source ~/pinky_pro/install/setup.bash
export ROS_DOMAIN_ID=<D>
ros2 launch pinky_navigation bringup_launch.xml map:=/home/pinky/cbs_map.yaml
```

### C7. 노트북에서 ROS 탐색 확인

로봇마다 새 터미널에서 확인한다.

```bash
source /opt/ros/jazzy/setup.bash && source ~/pinky_pro/install/setup.bash
export ROS_DOMAIN_ID=<D>
export ROS_AUTOMATIC_DISCOVERY_RANGE=SUBNET
unset ROS_LOCALHOST_ONLY ROS_STATIC_PEERS

ros2 node list --no-daemon
ros2 topic list --no-daemon | grep -E 'amcl_pose|scan|odom'
ros2 action list -t | grep navigate_to_pose
ros2 topic hz /scan --window 20       # 10초 관찰
```

**통과 기준**: 세 Domain 모두 `/amcl_pose`, `/scan`, `/navigate_to_pose`가 보이고, 한 Domain에서 다른 로봇의 노드가 보이지 않는다. `/scan` 주기가 로봇 단독 측정값과 크게 다르지 않다.

### C8. 초기 위치 지정과 AMCL 수렴

로봇마다 해당 Domain으로 RViz를 열어 수행한다.

```bash
export ROS_DOMAIN_ID=<D> ROS_AUTOMATIC_DISCOVERY_RANGE=SUBNET
ros2 launch pinky_navigation nav2_view.launch.xml
```

1. 로봇을 표시해 둔 시작 위치에 놓는다. 대기 중인 로봇은 다른 로봇 주행 경로 밖에 둔다.
2. `2D Pose Estimate`로 위치·방향 지정 → 03 문서 T2 기준(X/Y 공분산 < 0.0025, Yaw < 0.03)까지 수렴 확인.
3. RViz를 닫는다(동시에 여러 개 띄워 Domain을 혼동하지 않도록).

**통과 기준**: 세 로봇 모두 T2 기준 만족.

### C9. 목표 좌표 실측과 관제 설정

목표 좌표가 비어 있으면 화면 시작 버튼이 "목표 좌표 미설정"으로 막힌다.

1. 로봇을 목표 위치·방향에 손으로 놓고(또는 RViz `Nav2 Goal`로 이동 후) 해당 Domain에서 기록한다.

```bash
ros2 topic echo /amcl_pose --once --field pose.pose
```

2. 쿼터니언을 yaw로 바꿔 `deploy/production.env`의 `FLEET_ROBOT_<ID>_GOAL_X/Y/YAW`에 적는다(yaw = `atan2(2(w·z + x·y), 1 − 2(y² + z²))`). 필요하면 `INITIAL_*`도 같은 방식으로 적는다.
3. 로봇을 다시 시작 위치로 옮기고 C8을 반복한다.
4. app을 로컬 이미지로 재생성해 env를 반영한다.

```bash
docker compose --env-file deploy/production.env -f compose.prod.yaml \
  up --detach --pull never --no-build --force-recreate --wait app web
```

**통과 기준**: 화면 지도에 세 로봇의 목표 표시가 실제 목표 위치에 나타난다. 목표가 좁은 통로·다른 로봇 대기 위치를 막지 않는다.

### C10. 실행기 3개 실행

노트북에서 터미널 3개.

```bash
cd ~/project/pinky-fleet-control
scripts/run-ros-fleet-agent.sh 62b2
scripts/run-ros-fleet-agent.sh 648d
scripts/run-ros-fleet-agent.sh eed0
```

**통과 기준**: 각 터미널에 `agent ready: robot=<ID> domain=<D>` 로그. 화면에서 세 로봇이 3초 안에 "정상 수신"으로 바뀌고 위치가 RViz·실제 배치와 일치한다.

## 5. 시험 시나리오

모든 주행 시험 공통 안전 규칙:

- 주행 로봇 옆에 사람 1명이 대기해 즉시 들어 올릴 수 있게 한다. 화면 정지는 보조 수단이다.
- 한 번에 한 대만 주행한다(관제도 강제한다). 정지한 로봇은 주행 경로 밖에 둔다.
- 매 주행 전 화면에서 대상 로봇이 "정상 수신"이고 위치가 실제와 맞는지 확인한다.

### F1. 오프라인 상태에서 3대 상태 표시

**절차**: C10 이후 5분간 로봇을 움직이지 않고 화면을 관찰한다. 1분마다 `curl -s localhost:8080/api/v1/snapshot | jq -c '[.robots[]|{id,connection_state,motion_state,x:.pose.x_m,y:.pose.y_m}]'`를 기록한다.

**합격 기준**: 5분 내내 세 로봇 online, 위치 변동 2cm 이하, 실행기 로그에 HTTP 오류 없음.

### F2. 단일 로봇 목표 주행 — 62b2

**절차**

1. 화면에서 62b2 **시작**.
2. 화면 상태 변화를 기록: 전달 대기 → 이동 중 → 도착 완료.
3. 도착 후 실제 로봇 위치를 자로 재고, 화면 결과의 `position_error_m`·`yaw_error_rad`와 비교한다.
4. 로봇을 시작 위치로 되돌리고 C8 초기 위치 확인 후 3회 반복한다.

**합격 기준**

- 3회 모두 벽 접촉·수동 개입 없이 "도착 완료".
- "도착 완료"는 로봇이 실제로 멈춘 뒤에만 표시된다.
- 화면 위치 오차와 실측 오차 차이 5cm 이하. 이벤트가 `queued → moving → arrived` 순서로 한 번씩 기록된다.

### F3. 단일 로봇 목표 주행 — 648d, eed0

F2를 648d, eed0 각각 3회 수행한다.

**합격 기준**: F2와 같음. 다른 로봇의 Domain·위치 표시가 섞이지 않는다.

### F4. 동시 시작 차단

**절차**: 62b2 주행 중 화면에서 648d **시작**을 누른다.

**합격 기준**: 648d 시작 버튼이 비활성("62b2 주행 중")이거나 요청이 `ACTIVE_RUN`으로 거부된다. 648d가 움직이지 않는다. 62b2 주행은 영향을 받지 않는다.

### F5. 주행 중 정지

**절차**

1. 62b2 주행 시작 → "이동 중" 확인 후 약 1초 뒤 **현재 로봇 정지 요청**.
2. 요청 시각, 화면 "정지 확인 대기" 표시 시각, 로봇이 실제로 멈춘 시각, "정지 완료" 표시 시각을 기록한다(휴대폰 동영상 권장).
3. 648d, eed0도 1회씩 수행한다.

**합격 기준**

- 요청 직후 화면은 "정지 확인 대기"이고, Nav2 취소 결과를 받은 뒤에만 "정지 완료".
- 요청부터 실제 정지까지 2초 이하(기록값으로 판단, 초과 시 확인 필요).
- 정지 후 같은 로봇을 다시 시작할 수 있다.

### F6. 3대 순차 시연

**절차**: 62b2 → 648d → eed0 순서로 각 로봇 도착 완료를 확인한 뒤 다음 로봇을 시작한다. 시작 전 모든 로봇을 시작 위치에 놓고 C8을 확인한다. 전체를 3회 반복한다.

**합격 기준**

- 3회 연속 9번 주행 모두 도착 완료, 벽·로봇 간 접촉 없음.
- 주행 중인 로봇이 정지해 있는 다른 로봇을 장애물로 인식해 피하거나, 막히면 실패로 보고되고 "도착 완료"로 잘못 기록되지 않는다.
- 작업 이력에 9건의 소요 시간·결과가 남는다.

### F7. 로봇 한 대 통신 끊김

**절차**

1. **정지 상태**: 648d Wi-Fi를 끊는다(공유기에서 차단 또는 로봇 Wi-Fi off). 20초 관찰 후 복구.
2. **주행 상태**: 넓은 구간에서 62b2 주행 중 Wi-Fi를 끊고, 로봇이 Nav2로 계속 가는지·멈추는지 관찰한다. 필요하면 사람이 즉시 잡는다. 30초 뒤 복구.

**합격 기준**

- 끊긴 로봇이 약 3~4초 후 "수신 지연", 약 11초 후 "연결 끊김"으로 표시된다. 나머지 두 로봇은 online 유지.
- 끊긴 동안 "도착 완료"·"정지 완료"가 기록되지 않는다.
- 복구 후 위치·상태가 새 값으로 갱신된다.
- 주행 상태에서는 로봇 실제 동작, 복구 후 작업 결과(도착으로 끝나는지, 작업이 계속 활성으로 남는지 — 05 문서 R4)를 기록한다.

### F8. 실행기·관제 재시작 (정지 상태)

**절차**

1. 648d 실행기 `Ctrl+C` → 10초 뒤 재실행.
2. `docker compose ... restart app` → 화면 관찰.
3. 1·2 이후 62b2 주행 1회.

**합격 기준**: 1에서 648d만 offline → 재실행 후 online. 2에서 화면에 "관제 갱신 확인 필요"가 표시되고 복구 후 자동 갱신. 3의 주행이 정상 도착. 기존 작업 이력이 유지된다.

### F9. 노트북 재부팅 후 오프라인 복구

04 문서 G4의 "호스트 재부팅" 항목을 현장 구성으로 확인한다.

**절차**

1. 모든 주행이 끝난 상태에서 노트북을 재부팅한다. 공유기·로봇은 그대로 둔다.
2. 로그인 후 인터넷 없이 `docker compose ... ps`로 자동 복구를 확인한다.
3. C2 네트워크 확인 → C10 실행기 재실행 → F2 1회.

**합격 기준**: 수동 `up` 없이 세 컨테이너 healthy, 이전 작업 이력 유지, 실행기 재실행 후 3대 online, 주행 성공. 재부팅 후 필요한 수동 절차를 모두 기록한다.

### F10. 처음부터 끝까지 리허설

**절차**: 공유기·로봇·노트북을 모두 끈 상태에서 시작해 C1~C10 → F6 1회를 한 번에 진행하고 단계별 소요 시간을 기록한다.

**합격 기준**: 인터넷·외부 도움 없이 완료, 막힌 단계가 없음. 총 준비 시간이 시연 일정 안에 들어온다.

## 6. 실패 분류와 조치

| 현상 | 우선 확인 | 조치 |
|---|---|---|
| 노트북에서 로봇 ping 실패 | 같은 SSID·서브넷, AP 격리 | 공유기 AP 격리 해제, IP 예약 재확인 |
| ping은 되는데 `ros2 topic list`에 로봇 없음 | `ROS_DOMAIN_ID`, `ROS_LOCALHOST_ONLY`, 멀티캐스트 차단 | 변수 재설정, IGMP snooping/멀티캐스트 설정 확인, `ros2 multicast receive`/`send`로 시험 |
| 토픽은 보이는데 데이터가 안 옴·끊김 | Wi-Fi 신호, 대역 혼잡, `/scan` 주기 | 공유기 위치 변경, 노트북 유선 연결, 채널 변경 |
| 다른 로봇 토픽이 섞여 보임 | Domain 중복 | Pinky Studio에서 Domain 재설정 후 로봇 노드 재시작 |
| `docker compose up`이 이미지 받으려다 실패 | `--pull never --no-build` 누락, 이미지 없음 | 옵션 추가, 3절 P2 tar로 `docker image load` |
| 화면 로딩이 느리거나 일부 비어 있음 | 브라우저 Network 탭의 외부 요청 | 외부 자산 의존 제거 필요로 기록 |
| 시작 버튼 "목표 좌표 미설정" | env 목표값, app 재생성 여부 | C9 반복 |
| 시작 버튼 "지도 좌표 불일치" / `POSE_MISMATCH` | env `FLEET_MAP_VERSION`과 실행기 env가 같은 파일인지 | 동일 env 파일로 app·실행기 재시작 |
| 화면 "연결 끊김"인데 로봇 노드 정상 | 실행기 로그, `FLEET_SERVER_URL`, 토큰 | 실행기 재실행, 05 문서 8절 |
| 이동 중 표시 후 끝나지 않음 | Nav2 로그, 실행기 로그 | 로봇을 사람이 멈추고 기록, 05 문서 R4 |
| RViz TF·시간 관련 경고 | 노트북·로봇 시각 차이 | C5 |

## 7. 시험 순서와 진입 조건

```text
3절 사전 준비 완료
 → C1~C10 구성 (한 단계라도 실패하면 다음 단계로 가지 않음)
 → F1 → F2 → F3 → F4 → F5 → F6
 → F7 → F8 (장애 시험, 정지 상태부터)
 → F9 → F10 (리허설)
```

- 03 문서 T3~T5를 통과하지 않은 로봇은 F2 이후 주행 시험에서 제외하고 사유를 기록한다.
- F2가 실패한 로봇으로 F6을 진행하지 않는다.

## 8. 결과 기록표

결과는 `대기 / 합격 / 불합격 / 확인 필요`로 적는다. 모든 기록은 **실물 오프라인 시험**이다.

| 시각 | 단계 | 대상 | 결과 | 관찰값 (소요 시간·오차·전이 시각 등) | 증거 (캡처·영상·로그) | 조치 |
|---|---|---|---|---|---|---|
|  | C1 공유기 | - | 대기 |  |  |  |
|  | C2 오프라인 확인 | 노트북 | 대기 |  |  |  |
|  | C3 관제 기동 | 노트북 | 대기 |  |  |  |
|  | C4 로봇 네트워크 | 3대 | 대기 |  |  |  |
|  | C5 시각 | 3대 | 대기 |  |  |  |
|  | C7 ROS 탐색 | 3대 | 대기 |  |  |  |
|  | C8 AMCL | 3대 | 대기 |  |  |  |
|  | C9 목표 설정 | 3대 | 대기 |  |  |  |
|  | C10 실행기 | 3대 | 대기 |  |  |  |
|  | F1 상태 표시 | 3대 | 대기 |  |  |  |
|  | F2 주행 | 62b2 ×3 | 대기 |  |  |  |
|  | F3 주행 | 648d ×3 | 대기 |  |  |  |
|  | F3 주행 | eed0 ×3 | 대기 |  |  |  |
|  | F4 동시 시작 차단 | 62b2·648d | 대기 |  |  |  |
|  | F5 정지 | 3대 | 대기 |  |  |  |
|  | F6 순차 시연 | 3대 ×3 | 대기 |  |  |  |
|  | F7 통신 끊김 | 648d·62b2 | 대기 |  |  |  |
|  | F8 재시작 | 648d·app | 대기 |  |  |  |
|  | F9 노트북 재부팅 | 노트북 | 대기 |  |  |  |
|  | F10 리허설 | 전체 | 대기 |  |  |  |

## 9. 완료 기준

- C1~C10과 F1~F6 합격: 오프라인 현장에서 관제 화면으로 3대 순차 주행·정지·상태 확인 가능.
- F7~F9 결과 기록: 통신 끊김·재시작·재부팅 시 실제 동작과 남은 수동 절차 확인.
- F10 합격: 시연 당일 절차 재현 가능.
- 실측 목표 좌표(값), 주소표, 미해결 문제를 이 문서와 README에 반영. 비밀번호·토큰은 기록하지 않는다.
- 3대 동시 주행과 공용 통로 조정은 알고리즘 연결(README 5단계) 이후 별도 문서로 시험한다.
