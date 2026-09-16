# 03. 단일 로봇 Nav2 주행 안정화 테스트

갱신: 2026-09-16

## 1. 목표와 범위

Domain 40의 PinkyPro `62b2` 한 대에서 저장된 공통 지도와 Nav2를 사용해 단일 목표 및 고정 웨이포인트 주행을 안정화한다. 이 단계에서는 CBS, 다중 로봇 동시 주행, Laravel 관제 연동을 시험하지 않는다.

단일 `NavigateToPose`가 반복 성공하기 전에는 웨이포인트와 다중 로봇 단계로 넘어가지 않는다.

## 2. 현재 적용한 설정

대상 로봇의 시험용 설치 파일:

```text
/home/pinky/pinky_pro/install/pinky_navigation/share/pinky_navigation/params/nav2_params.yaml
```

2026-09-16에 설정 파일 변경과 YAML 구문 검증을 완료했다. 실행 중인 Nav2에는 재시작 전까지 이전 값이 유지되므로 실물 시험 전에 반드시 Nav2를 재시작한다.

### 차체와 local costmap

```yaml
footprint: '[[0.07, 0.06], [0.07, -0.06], [-0.07, -0.06], [-0.07, 0.06]]'

local_costmap:
  footprint_padding: 0.00
  inflation_radius: 0.08
  cost_scaling_factor: 8.0
```

### 벽과 떨어진 전역 경로

```yaml
global_costmap:
  footprint_padding: 0.01
  inflation_radius: 0.15
  cost_scaling_factor: 3.0

planner_server:
  GridBased:
    tolerance: 0.10
    use_astar: false
    allow_unknown: false
```

### 위치 추정

```yaml
amcl:
  update_min_d: 0.02
  update_min_a: 0.05
  set_initial_pose: false
```

`set_initial_pose: false`이므로 Nav2를 재시작한 뒤 RViz에서 `2D Pose Estimate`를 지정해야 한다.

### 좁은 코너 주행

```yaml
FollowPath:
  desired_linear_vel: 0.08
  lookahead_dist: 0.15
  min_lookahead_dist: 0.08
  max_lookahead_dist: 0.25
  lookahead_time: 1.0
  curvature_lookahead_dist: 0.15
  use_regulated_linear_velocity_scaling: true
  regulated_linear_scaling_min_radius: 0.25
  regulated_linear_scaling_min_speed: 0.03
  rotate_to_heading_angular_vel: 0.5
  max_angular_accel: 1.5
  cost_scaling_dist: 0.08
  inflation_cost_scaling_factor: 8.0
```

`inflation_cost_scaling_factor`는 local costmap의 `cost_scaling_factor`와 맞춘다.

### 생성한 원격 백업

```text
nav2_params.yaml.pre-drive-20260916-120117.bak
nav2_params.yaml.pre-followpath-20260916-122411.bak
nav2_params.yaml.pre-centered-path-20260916-124205.bak
```

## 3. 시험 전 준비

1. 로봇을 폼 벽에 닿지 않는 넓은 시작 구역에 놓는다.
2. 시작 구역에는 로봇 중심 위치와 전방 방향을 표시한다.
3. 실제 폼 벽과 정적 지도상의 검은 벽이 같은 위치인지 확인한다.
4. 20cm 병목 구간은 기본 시험 경로에서 제외한다. 일반 통로는 30cm, 회전 구역은 40cm 이상을 권장한다.
5. 사람이 즉시 로봇을 들거나 비상 정지할 수 있는 위치에서 시험한다.
6. 한 번에 `62b2` 한 대만 주행시킨다.

폼 벽을 이동했다면 기존 지도를 계속 사용하지 않고 공통 지도를 다시 생성해야 한다.

## 4. 실행과 설정 확인

### 4.1 로봇 기본 노드

로봇 터미널 1:

```bash
source /opt/ros/jazzy/setup.bash
source ~/pinky_pro/install/setup.bash
export ROS_DOMAIN_ID=40

ros2 launch pinky_bringup bringup_robot.launch.xml
```

### 4.2 Nav2 재시작

기존 Nav2 터미널에서 `Ctrl+C`로 종료한 뒤 로봇 터미널 2에서 실행한다.

```bash
source /opt/ros/jazzy/setup.bash
source ~/pinky_pro/install/setup.bash
export ROS_DOMAIN_ID=40

ros2 launch pinky_navigation bringup_launch.xml \
  map:=/home/pinky/cbs_map.yaml
```

### 4.3 노트북 RViz

```bash
source /opt/ros/jazzy/setup.bash
source ~/dev_ws/pinky_pro/install/setup.bash

export ROS_DOMAIN_ID=40
export ROS_AUTOMATIC_DISCOVERY_RANGE=SUBNET
unset ROS_LOCALHOST_ONLY
unset ROS_STATIC_PEERS

ros2 launch pinky_navigation nav2_view.launch.xml
```

### 4.4 실행 파라미터 확인

```bash
ros2 param get /global_costmap/global_costmap inflation_layer.inflation_radius
ros2 param get /global_costmap/global_costmap inflation_layer.cost_scaling_factor
ros2 param get /planner_server GridBased.tolerance
ros2 param get /planner_server GridBased.allow_unknown
ros2 param get /amcl update_min_d
ros2 param get /amcl update_min_a
ros2 param get /controller_server FollowPath.min_lookahead_dist
ros2 param get /controller_server FollowPath.use_regulated_linear_velocity_scaling
```

기대값:

```text
0.15
3.0
0.10
false
0.02
0.05
0.08
true
```

## 5. 테스트 시나리오

### T0. 노드와 설정 확인

**절차**

1. bringup과 Nav2를 순서대로 실행한다.
2. RViz의 Fixed Frame을 `map`으로 확인한다.
3. 위 파라미터 조회값을 기록한다.

**합격 기준**

- 설정 파일의 새 값이 실행 파라미터에 반영된다.
- `map → odom → base_footprint` TF가 끊기지 않는다.
- Nav2 lifecycle 노드가 활성 상태다.

### T1. 정지 상태 지도·라이다 정합

**절차**

1. RViz에서 local/global costmap 표시를 잠시 끈다.
2. `Map`, `LaserScan`, `RobotModel`, `ParticleCloud`만 표시한다.
3. `2D Pose Estimate`로 로봇 중심과 실제 전방 방향을 지정한다.
4. 로봇을 움직이지 않고 라이다 점과 지도 벽을 비교한다.

**합격 기준**

- 라이다 점이 두 방향 이상의 지도 벽과 겹친다.
- 로봇 모델이 검은 벽 위에 놓이지 않는다.
- 실제 존재하는 고정 벽이 지도에도 표시돼 있다.

### T2. AMCL 수렴

**절차**

1. 넓은 위치에서 로봇을 좌우 약 60~90도씩 천천히 회전한다.
2. ParticleCloud가 로봇 주변으로 모이는지 확인한다.
3. `/amcl_pose` covariance를 기록한다.

**합격 기준**

```text
covariance[0]  < 0.0025  # X 표준편차 약 5cm 미만
covariance[7]  < 0.0025  # Y 표준편차 약 5cm 미만
covariance[35] < 0.03    # Yaw 표준편차 약 10도 미만
```

기준을 만족하지 않으면 목표를 보내지 않는다. 초기 위치를 다시 지정해도 수렴하지 않으면 지도와 실제 벽의 불일치부터 해결한다.

### T3. 30cm 직선 주행

**절차**

1. 벽에서 충분히 떨어진 위치에 약 30cm 앞 목표를 지정한다.
2. 로봇, 전역 경로, 실제 궤적을 관찰한다.
3. 같은 조건으로 5회 반복한다.

**합격 기준**

- 5회 모두 물리적 벽 접촉 없이 도착한다.
- 수동 개입이나 복구 행동 없이 `SUCCEEDED`가 된다.
- RViz 위치가 실제 위치에서 눈에 띄게 벗어나지 않는다.

### T4. 넓은 45도 방향 전환

**절차**

1. 회전 공간이 충분한 곳에서 약 45도 방향이 바뀌는 목표를 지정한다.
2. 코너 안쪽으로 경로를 크게 이탈하는지 확인한다.
3. 3회 반복한다.

**합격 기준**

- 3회 모두 벽 접촉 없이 도착한다.
- 회전 중 정지·진동·과도한 제자리 회전이 반복되지 않는다.

### T5. 직각 코너 하나

**절차**

1. 20cm 병목이 아닌 넓은 직각 코너를 선택한다.
2. 전역 경로가 코너 안쪽 벽에 붙는지 먼저 확인한다.
3. 경로가 안전하면 목표를 실행한다.
4. 3회 반복한다.

**합격 기준**

- 전역 경로가 벽 중심선이 아니라 통로 중앙 쪽을 따른다.
- 로봇 모서리가 폼 벽과 접촉하지 않는다.
- `collision ahead`, `controller patience exceeded`가 반복되지 않는다.

### T6. 웨이포인트 2개

**경로**

```text
시작 → 직선 목표 → 코너 뒤 목표
```

**합격 기준**

- 두 목표가 순서대로 수행된다.
- 첫 목표 도달과 두 번째 목표 출발이 상태로 구분된다.
- 3회 연속 성공한다.

### T7. 웨이포인트 3개 순환

**경로**

```text
시작 → A → B → C
```

**합격 기준**

- 3회 연속으로 전체 순환을 완료한다.
- 수동 위치 재설정과 물리적 구조물 접촉이 없다.
- 실패 시 실패한 웨이포인트와 Nav2 오류가 기록된다.

## 6. 실패 분류와 조치

| 현상 | 우선 확인 | 다음 조치 |
|---|---|---|
| 직선에서도 한쪽으로 치우침 | AMCL, 라이다 정합, 오도메트리 | 초기 위치 재설정, 지도·실물 대조 |
| 전역 경로선이 벽에 붙음 | global inflation | `0.15 / 3.0` 적용 여부 확인 |
| 코너에서만 벽에 닿음 | lookahead, 위치 오차 | T2 재확인, FollowPath 적용값 확인 |
| 출발 즉시 collision ahead | 시작 footprint와 local costmap | 넓은 위치로 이동 후 초기화 |
| 가다가 멈춤 | local scan 장애물, controller 로그 | 실제 장애물·라이다 노이즈 확인 |
| 후진 복구 실패 | 뒤쪽 충돌 예상 또는 물리적 끼임 | 목표 취소 후 사람이 안전한 위치로 복귀 |
| 목표 근처에서 종료되지 않음 | goal tolerance | 목표 위치·방향과 goal checker 확인 |

물리적으로 벽에 끼인 상태에서 footprint나 충돌 검사를 줄여 강제 주행시키지 않는다.

## 7. 결과 기록표

| 시각 | 시험 | 시작 위치 | 목표 | AMCL X/Y/Yaw covariance | 결과 | 벽 접촉 | Nav2 메시지 | 조치 |
|---|---|---|---|---|---|---|---|---|
|  | T0 |  |  |  | 대기 |  |  |  |
|  | T1 |  |  |  | 대기 |  |  |  |
|  | T2 |  |  |  | 대기 |  |  |  |
|  | T3-1 |  |  |  | 대기 |  |  |  |
|  | T3-2 |  |  |  | 대기 |  |  |  |
|  | T3-3 |  |  |  | 대기 |  |  |  |
|  | T3-4 |  |  |  | 대기 |  |  |  |
|  | T3-5 |  |  |  | 대기 |  |  |  |

## 8. 다음 단계 진입 조건

다음 조건을 모두 만족한 뒤 설정을 원본 소스 파일에 반영하고 다른 두 로봇에 복사한다.

- T3 직선 주행 5/5 성공.
- T4 방향 전환 3/3 성공.
- T5 직각 코너 3/3 성공.
- T7 웨이포인트 순환 3회 연속 성공.
- 물리적 벽 접촉 없음.
- 시작점 배치와 AMCL 초기화 절차를 다른 팀원도 재현할 수 있음.

검증 후 반영할 원본:

```text
/home/pinky/pinky_pro/src/pinky_pro/pinky_navigation/params/nav2_params.yaml
```

그 이후 두 번째 로봇과 세 번째 로봇을 각각 단독 검증하고, 관제 서버의 고정 목표·순차 배차 단계로 진행한다.
