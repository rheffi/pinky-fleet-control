# 04. Ubuntu 관제서버 배포 준비와 인계

갱신: 2026-09-16 / 상태: G1~G3 완료, G4 현장 검증 대기, G5 관제 PC 준비 완료·로봇 연결 대기

## 1. 목표와 범위

3단계 목표는 Ubuntu 24.04 관제 노트북에 이 프로젝트를 운영용 Docker 구성으로 배포하고, 내부망에서 Laravel·Vue 관제 화면과 MySQL을 재현하는 것이다. 그 다음 Domain 40 로봇 `62b2`의 실제 ROS 상태를 한 대 연결한다.

Windows에서 준비한 저장소를 현재 Ubuntu 관제 노트북으로 인계했다. 이 문서는 이제 다음 범위를 함께 관리한다.

- Ubuntu 호스트와 저장소의 실제 상태를 확인한다.
- Docker Engine과 Compose를 운영 배포에 맞게 준비한다.
- 운영용 이미지·Compose를 작성하고 Laravel·Vue·MySQL을 검증한다.
- 인터넷이 있는 환경에서 단일 로봇 연결 준비까지 마친다.
- 이후 인터넷 없는 공유기에서 노트북 1대와 로봇 3대의 관제 시험으로 확장한다.

## 2. 확정 사항

| 항목 | 결정 |
|---|---|
| 운영체제 | Ubuntu 24.04 LTS, x86_64 |
| 애플리케이션 | Laravel 13 + Vue 3 |
| 런타임 | Docker Engine + Docker Compose 플러그인 |
| 데이터베이스 | MySQL 8.4 컨테이너와 영구 볼륨 |
| 호스트 설치 | PHP·Composer·Node·MySQL을 호스트에 별도 설치하지 않음 |
| GPU | 관제 웹 배포에 CUDA·NVIDIA 컨테이너 런타임을 요구하지 않음 |
| 웹 접근 | 공유기 내부망에서 `Ubuntu-IP:8080`으로 접근 |
| 비밀정보 | 운영 `.env`, Wi-Fi 비밀번호, 토큰, 개인키를 Git에 저장하지 않음 |
| ROS 연결 | Docker 밖 Ubuntu 호스트에서 ROS 2 Jazzy 어댑터를 실행 |
| 첫 로봇 | `62b2`, `ROS_DOMAIN_ID=40` |
| 후속 로봇 | `648d=30`, `eed0=35`, 프로세스를 Domain별로 분리 |

현재 `compose.yaml`은 Windows 개발용이다. 소스 바인드 마운트, Vite 개발 서버, loopback 웹 바인딩을 사용하므로 Ubuntu 시연용으로 그대로 사용하지 않는다.

## 3. Git 인계 절차

### Windows에서 준비

1. 기본 브랜치 `main`으로 저장소를 초기화한다.
2. `.gitignore`가 `.env`, 의존성, 캐시, 로그, 임시 빌드 폴더를 제외하는지 확인한다.
3. 추적 예정 파일에 비밀번호나 개인키가 없는지 검사한다.
4. 선택한 1cm 지도와 현재 Nav2 파라미터가 인계 파일에 포함됐는지 해시로 확인한다.
5. 첫 커밋을 만든다.
6. 팀에서 정한 원격 저장소 주소를 받은 뒤 `origin`을 추가하고 push한다.

팀 저장소는 비공개 GitHub 저장소 `rheffi/pinky-fleet-control`을 사용한다. 인증 토큰은 문서에 기록하지 않는다.

원격 저장소가 늦어질 경우 프로젝트 루트의 `artifacts/pinky-fleet-control-main.bundle`을 USB로 전달할 수 있다. `artifacts/`는 Git 추적 대상이 아니다.

```powershell
git bundle create artifacts/pinky-fleet-control-main.bundle main
git bundle verify artifacts/pinky-fleet-control-main.bundle
```

### 원격 저장소가 정해진 뒤 실행할 명령

```powershell
git remote add origin https://github.com/rheffi/pinky-fleet-control.git
git push -u origin main
```

### Ubuntu에서 받을 명령

```bash
git clone https://github.com/rheffi/pinky-fleet-control.git ~/pinky-fleet-control
cd ~/pinky-fleet-control
git status --short --branch
```

원격 저장소 없이 bundle로 전달받은 경우:

```bash
git clone -b main /USB-경로/pinky-fleet-control-main.bundle ~/pinky-fleet-control
cd ~/pinky-fleet-control
git status --short --branch
```

## 4. Ubuntu 작업 순서

### U1. 호스트 점검

- Ubuntu 24.04와 x86_64 확인
- 인터넷 연결이 있을 때 시스템 패키지 인덱스 갱신
- Docker Engine과 Compose 플러그인 설치 또는 기존 설치 검증
- 현재 사용자를 Docker 그룹에 추가한 뒤 새 로그인 세션에서 권한 검증
- `docker run --rm hello-world` 성공 확인
- Git, curl, 시간 동기화 상태 확인

#### 2026-09-16 실제 점검 결과

| 항목 | 확인 결과 | 판정·다음 조치 |
|---|---|---|
| OS·구조 | Ubuntu 24.04.5 LTS, x86_64, 커널 7.0.0-31 | 운영 대상과 일치 |
| 자원 | 논리 CPU 24개, 메모리 약 15GiB, 루트 디스크 여유 약 424GiB | 관제 웹 배포에 충분 |
| 저장소 | `main`이 `origin/main`을 추적, `3032424`에서 변경 없음 | 배포 작업 시작 가능 |
| 네트워크 | Wi-Fi 인터페이스가 올라와 있고 IPv4가 할당됨 | SSID·실제 IP는 문서에 기록하지 않음 |
| 포트 | 8080·5173·3306·9000 수신 없음 | 현재 관제 서비스 미기동 |
| Docker | 초기 Snap Docker 데이터가 컨테이너·이미지·볼륨 없이 312KiB뿐인 것을 확인하고 제거. 공식 Docker Engine 29.8.1·Compose 5.5.1 설치 | 서비스 active·enabled, hello-world 성공 |
| Docker 권한 | `team2`를 `docker` 그룹에 추가 | 새 로그인 전 현재 Codex 셸에서는 `sg docker` 사용, 재로그인 후 일반 명령으로 재확인 |
| 기본 도구 | Git 2.43.0, curl 8.5.0 설치 | 준비 완료 |
| ROS | ROS 2 Jazzy Desktop·Nav2 설치, Fast DDS 로컬 송수신 성공 | 관제 PC 준비 완료, Domain 40 실물 로봇 검색 대기 |

#### U1 실행 결과

1. Snap Docker에 보존할 데이터가 없음을 확인했다.
2. Snap Docker를 제거하고 Docker 공식 APT 저장소에서 Engine·Buildx·Compose 플러그인을 설치했다.
3. Docker와 containerd 서비스를 활성화하고 `team2`를 `docker` 그룹에 추가했다.
4. Docker Engine 29.8.1·Compose 5.5.1과 amd64 hello-world 실행을 확인했다.

Docker 설치는 공식 Ubuntu APT 저장소 방식을 사용했다. 사용자 그룹 변경의 일반 셸 반영은 로그아웃·로그인 뒤 최종 확인한다.

### U2. 운영용 컨테이너 구성

- 현재 개발용 `compose.yaml` 유지
- `compose.prod.yaml` 추가
- Composer 의존성과 Vue 빌드 결과를 포함한 운영 이미지 작성
- 운영 구성에서 `node`, Vite HMR, 샘플 실행기, 전체 소스 바인드 마운트 제거
- `web`, `app`, `mysql`에 healthcheck와 재시작 정책 적용
- 웹만 내부망 `0.0.0.0:8080`으로 공개
- PHP-FPM과 MySQL 포트는 호스트에 공개하지 않음
- `APP_ENV=production`, `APP_DEBUG=false` 적용

#### 현재 작성한 운영 구성

| 파일 | 역할 |
|---|---|
| `compose.prod.yaml` | web·app·mysql 운영 서비스, healthcheck, 재시작 정책, 내부 네트워크와 MySQL 볼륨 |
| `docker/production/Dockerfile` | Composer 의존성과 Vue 빌드 결과를 포함하는 app·web 다단계 이미지 |
| `docker/nginx/production.conf` | 정적 파일 제공과 PHP-FPM 전달, Docker DNS 재조회 |
| `docker/php/production.ini` | 오류 비노출, 업로드 제한, OPcache 운영 설정 |
| `docker/php/production-fpm.conf` | 컨테이너 환경변수 전달과 PHP-FPM ping 상태 확인 |
| `deploy/production.env.example` | Git에 비밀값을 넣지 않는 운영 환경변수 템플릿 |
| `scripts/create-production-env.sh` | 앱 키와 서로 다른 DB 비밀번호를 출력 없이 생성하고 권한 600으로 저장 |

운영 Compose에는 `node`, Vite HMR, 샘플 실행기, 호스트 소스 바인드 마운트가 없다. web은 외부 접근용 frontend와 내부 control 네트워크에 연결하고, app·mysql은 내부 control에만 연결한다. 웹의 8080 포트만 호스트에 공개하며 PHP-FPM과 MySQL은 공개하지 않는다.

Compose 정적 검사, Dockerfile build check, app·web 이미지 빌드, Nginx·PHP-FPM 설정 검사를 통과했다. Alpine web healthcheck에서 지원하지 않는 `grep --quiet`를 `grep -q`로 수정했고, 내부 네트워크만 사용하면 포트 게시가 적용되지 않는 것을 확인해 web에 frontend 네트워크를 추가했다.

| 이미지 | 실제 결과 |
|---|---|
| `pinky-fleet-control-app:local` | `sha256:8fa04932...052da`, 약 856MB |
| `pinky-fleet-control-web:local` | `sha256:563b1d2...daac4`, 약 101MB |

루트 `.env`와 실제 운영 환경파일 `deploy/production.env`를 생성했고 파일 모드 600과 Git 제외를 확인했다. 두 파일에는 현재 지도 해시, 지도 프레임, 로봇별 ROS Domain ID(`62b2=40`, `648d=30`, `eed0=35`)를 기록했다. 초기 위치와 시연 목표 좌표는 현장 측정 전이므로 빈 값이며, 애플리케이션에서는 `null`로 읽는다. 비밀값은 출력하거나 문서에 기록하지 않았다.

### U3. 최초 배포

- 실제 운영 `.env`를 Ubuntu에서만 생성
- 앱 키와 서로 다른 DB 계정·root 비밀번호 생성
- 이미지 빌드 후 서비스 기동
- DB migration 실행
- `/`, `/api/health`, 정적 JS·CSS 응답 확인
- 다른 내부망 기기에서 `http://Ubuntu-IP:8080` 접속 확인
- 컨테이너 재시작 후 MySQL 데이터 유지 확인

Docker 준비 후 다음 순서로 실행한다.

1. `deploy/production.env.example`을 Git에서 제외되는 `deploy/production.env`로 복사한다.
2. 앱 키, APP_URL, 앱 DB 비밀번호와 서로 다른 root 비밀번호를 실제 값으로 바꾼다.
3. Compose 설정 검사 후 app·web 이미지를 빌드한다.
4. MySQL을 먼저 기동하고 migration과 `FleetSeeder`를 실행한다.
5. app·web을 기동하고 세 서비스의 healthcheck와 HTTP 응답을 확인한다.
6. 브라우저와 다른 내부망 기기에서 관제 화면을 확인한다.

#### U3 실제 검증 결과

- MySQL 8.4 이미지 다운로드와 영구 볼륨 생성을 완료했다.
- 전체 migration과 `FleetSeeder` 실행을 완료했다.
- mysql·app·web 세 서비스가 모두 healthy다.
- `/`, `/api/health`, `/api/v1/bootstrap`, 빌드된 JS·CSS가 모두 HTTP 200을 반환했다.
- health API는 `status=ok`, `database=connected`를 반환했다.
- bootstrap은 sample 모드와 로봇 3대를 반환했다. 실제 로봇 모드로 오해하지 않는다.
- loopback과 현재 Wi-Fi IPv4의 8080 포트에서 상태 API HTTP 200을 확인했다. 다른 물리 기기에서의 접속은 아직 확인하지 않았다.
- web에만 `0.0.0.0:8080→80` 바인딩이 있고 app·mysql에는 호스트 포트와 바인드 마운트가 없다.

### U4. 오프라인·재부팅 검증

- 필요한 이미지를 tar 파일로 내보낼 명령과 복구 명령 기록
- 외부 CDN·클라우드 요청이 없는지 확인
- 인터넷을 끊고 화면·API·DB 동작 확인
- Ubuntu 재부팅 후 Docker 서비스와 관제 컨테이너 자동 복구 확인

현재까지 다음 복구 검증을 완료했다.

- 전체 컨테이너 stop/start 후 환경 검증 UUID와 저장 시각 유지.
- Docker 데몬 재시작 후 세 서비스 자동 복구·healthy, DB 검증값 유지, API 정상.
- 컨테이너와 네트워크를 제거한 뒤 `--pull never --no-build`로 로컬 이미지만 사용해 재생성 성공, MySQL 볼륨과 API 정상.
- 제공되는 HTML·JS·CSS에 외부 CDN·폰트 자산 의존성이 없음을 확인. 빌드 JS의 W3C namespace와 Vue 오류 문서 문자열은 다운로드 자산이 아니다.

실제 인터넷 연결 차단과 Ubuntu 재부팅은 현재 Codex 연결을 종료하므로 사용자가 현장 전 직접 실행하고 복구 결과를 확인한다.

현장 반출용 이미지 묶음은 인터넷이 연결된 현재 장비에서 다음 명령으로 만든다. 이 파일은 실행 이미지 복구용이며 MySQL 볼륨 백업을 대신하지 않는다.

```bash
cd ~/project/pinky-fleet-control
mkdir -p artifacts
docker image save \
  --output artifacts/pinky-fleet-control-images.tar \
  pinky-fleet-control-app:local \
  pinky-fleet-control-web:local \
  mysql:8.4
sha256sum artifacts/pinky-fleet-control-images.tar \
  > artifacts/pinky-fleet-control-images.tar.sha256
```

오프라인 장비에서는 두 파일을 같은 디렉터리에 둔 뒤 검사·복구한다.

```bash
sha256sum --check artifacts/pinky-fleet-control-images.tar.sha256
docker image load --input artifacts/pinky-fleet-control-images.tar
docker compose --env-file deploy/production.env -f compose.prod.yaml \
  up --detach --pull never --no-build --wait
```

### U5. 단일 로봇 상태 연결

- Ubuntu 호스트에 ROS 2 Jazzy와 PinkyPro 워크스페이스 준비
- `ROS_DOMAIN_ID=40`에서 `62b2` 토픽 조회 확인
- 장기 실행 ROS 어댑터가 실제 위치·Nav2 상태·수신 시각을 Laravel API에 보고하도록 구성
- 관제 화면에서 샘플과 실제 상태를 구분
- ROS 메시지가 일정 시간 끊기면 도착이 아니라 `stale/offline`으로 처리
- 한 대가 안정되면 Domain 30·35 어댑터를 별도 프로세스로 확장

#### U5 관제 PC 준비 결과

- ROS 2 Jazzy Desktop과 개발 도구를 공식 ROS APT 저장소에서 설치했다.
- 기본 RMW는 `rmw_fastrtps_cpp`이며 별도 Domain 99에서 talker 메시지를 한 번 수신해 로컬 DDS 송수신을 확인했다.
- 공식 PinkyPro `main`을 `~/pinky_pro/src/pinky_pro`에 clone했다. 기준 커밋은 `75f76e8b7cd971c32c07233f7accd418dab1d6b4`다.
- 전체 공식 저장소의 Gazebo 시뮬레이션 의존성은 현재 실물 관제 범위에서 제외했다. `pinky_navigation`에 필요한 `navigation2`와 `nav2_bringup` 의존성만 설치했다.
- 프로젝트의 `nav2_params.yaml`, `cbs_map.yaml`, `cbs_map.pgm`을 워크스페이스 source에 복사하고 `pinky_navigation` 빌드를 완료했다.
- 세 파일 모두 프로젝트 원본, 워크스페이스 source, install 공간의 SHA-256이 각각 일치한다.
- 관제 화면은 `cbs_map.pgm`에서 생성한 PNG와 YAML의 실제 해상도·원점·크기를 표시한다. 실제 로봇 위치 연동 전까지 샘플 명령 데이터와 표시 지도 메타데이터는 분리한다.
- `ros2 pkg prefix pinky_navigation`과 `nav2_view.launch.xml --show-args`를 확인해 설치 경로와 launch 해석을 검증했다.
- 호스트 UFW는 비활성이고 Wi-Fi 인터페이스는 multicast가 활성화돼 있다.
- 공식 Pinky Studio 0.2.3 amd64 `.deb`의 게시 SHA-256을 확인하고 설치했다. Bluetooth 서비스는 active, 어댑터는 unblocked·powered 상태다.
- `ROS_DOMAIN_ID=40` 검색에는 로컬 기본 토픽만 보이고 로봇 토픽이나 로봇의 같은 네트워크 이웃이 아직 없다. 다음 진행 조건은 `62b2`의 전원·부팅 완료와 이 노트북과 같은 Wi-Fi 연결이다.

로봇 연결은 앱 메뉴에서 **Pinky Studio**를 열고 다음 순서로 수행한다. Wi-Fi 비밀번호는 앱에만 입력하며 문서나 Git에 기록하지 않는다. Pinky Studio Wi-Fi 설정은 로봇 이미지 `pinky_pro_v1.8` 이상이 필요하다.

1. 로봇 전원을 켜고 **Pinky 검색**에서 `pinky_62b2`를 선택해 BLE 연결한다.
2. **WiFi 설정**에서 이 노트북과 같은 인터넷 가능 Wi-Fi를 선택하고 연결한다.
3. 표시된 로봇 IP와 Wi-Fi 연결 상태를 확인한다.
4. **ROS2 Domain 설정**에서 `40`을 적용한다.
5. 필요하면 표시된 실제 IP의 `http://로봇-IP:8888`로 Jupyter에 접속해 로봇 측 에이전트를 실행한다.
6. 로봇에서 새 터미널을 열거나 `source ~/.bashrc`를 실행한 뒤 아래 Domain 40 검색을 다시 수행한다.

로봇이 같은 네트워크에 들어오면 아래처럼 실제 토픽부터 수집한다. 토픽 이름을 확인하기 전에는 관제 어댑터의 위치·Nav2 인터페이스를 임의로 정하지 않는다.

```bash
source /opt/ros/jazzy/setup.bash
source ~/pinky_pro/install/setup.bash
export ROS_DOMAIN_ID=40
ros2 node list --no-daemon
ros2 topic list --no-daemon
ros2 action list -t
```

### 단계별 실행 게이트

| 게이트 | 작업 | 통과 조건 | 상태 |
|---|---|---|---|
| G1 | Ubuntu 기본 도구·Docker 준비 | 일반 사용자로 Docker API 접근, hello-world 성공 | 완료 — 그룹 재로그인 확인만 남음 |
| G2 | 운영 이미지·Compose 작성 | Vite·소스 바인드 마운트 없이 이미지 빌드, 설정 검사 통과 | 완료 |
| G3 | 관제서버 최초 기동 | `/`, `/api/health`, JS·CSS HTTP 200, DB migration 성공 | 완료 — 다른 물리 기기 접속은 후속 확인 |
| G4 | 운영 복구 검증 | 컨테이너 재시작 후 DB 유지, 인터넷 차단·호스트 재부팅 후 자동 복구 | 진행 — 데몬·컨테이너·로컬 이미지 복구 통과, 실제 차단·재부팅 대기 |
| G5 | 단일 로봇 연결 | `62b2 / Domain 40`의 실제 상태와 마지막 수신 시각 표시, 끊김은 stale/offline 처리 | 진행 — ROS·Nav2·Pinky 워크스페이스 준비 완료, 같은 Wi-Fi의 실물 로봇 대기 |

각 게이트의 실제 증거를 기록한 뒤 다음 게이트로 진행한다. G1~G4가 끝나기 전에는 로봇 주행 명령을 관제에 연결하지 않는다.

## 5. 3단계 완료 기준

- Ubuntu 내부망 주소에서 관제 화면이 열린다.
- Laravel 상태 API가 DB 연결을 포함해 HTTP 200을 반환한다.
- 운영 Compose에 Vite 개발 서버와 소스 바인드 마운트가 없다.
- MySQL 포트가 외부에 노출되지 않고 데이터가 재시작 후 유지된다.
- 인터넷 차단과 Ubuntu 재부팅 후에도 관제 화면이 복구된다.
- `62b2 / Domain 40`의 실제 위치 또는 Nav2 상태와 마지막 수신 시각이 표시된다.
- 통신 중단을 실제 도착이나 정지 성공으로 기록하지 않는다.
- 수행 명령, 변경 파일, 검증 결과와 미해결 문제를 이 문서와 README에 기록한다.

## 6. Ubuntu Codex 전달 프롬프트

아래 내용을 Ubuntu 노트북에서 이 저장소를 clone한 뒤 Codex에 그대로 전달한다.

```text
현재 작업 폴더는 PinkyPro 3대 관제용 Laravel 13 + Vue 3 프로젝트다.

먼저 AGENTS.md, README.md, docs/01-development-environment.md,
docs/02-sample-fleet-control.md, docs/04-ubuntu-control-server.md를 읽어라.

목표는 Ubuntu 24.04 관제 노트북에서 3단계 배포를 실제로 완료하는 것이다.
우선 호스트와 Docker 상태를 확인하고, 필요한 경우 Docker Engine과 Docker
Compose 플러그인을 공식 저장소 방식으로 설치한다. 호스트에 PHP, MySQL,
Node 또는 CUDA를 관제 웹의 선행 조건으로 설치하지 마라.

현재 compose.yaml은 Windows 개발용이므로 유지한다. 별도의 운영용
compose.prod.yaml과 필요한 운영 Dockerfile·Nginx 설정을 작성하라.
운영 이미지는 Composer 의존성과 Vue 빌드 결과를 포함해야 하고, Vite 개발
서버·sample simulator·전체 소스 바인드 마운트가 없어야 한다. 웹만 공유기
내부망의 8080 포트로 공개하고 PHP-FPM과 MySQL은 외부에 공개하지 마라.
서비스에는 healthcheck와 재시작 정책을 적용하라.

실제 .env는 Ubuntu에서만 생성하고 APP_ENV=production, APP_DEBUG=false로
설정하라. 앱 키와 DB 비밀번호를 생성하되 출력·문서·Git에 기록하지 마라.
운영 서비스를 기동하고 migration, 루트 화면, /api/health, 정적 자산,
DB 데이터 유지, 컨테이너 재시작을 검증하라. 가능하면 다른 내부망 기기에서
Ubuntu-IP:8080 접근도 확인하라.

그 다음 인터넷 차단 배포를 위해 사용 이미지의 save/load 절차를 작성하고,
외부 CDN 의존성을 확인하라. Ubuntu 재부팅 뒤 자동 복구도 실제로 검증하라.

Docker 배포가 통과하면 ROS 연결은 Docker 밖 Ubuntu 호스트에서 진행한다.
첫 대상은 robot_id=62b2, ROS_DOMAIN_ID=40이다. 실제로 존재하는 ROS 토픽만
사용하고, 위치·Nav2 상태·마지막 수신 시각을 Laravel에 보고하는 최소 어댑터
방식을 결정하라. Vue/PHP 요청 처리 안에서 모터 제어 루프를 실행하지 마라.
Domain 30·35는 한 대 연결 검증 후 별도 프로세스로 확장한다.

작업 중 기존 샘플 관제 기능과 테스트를 보존하고, 비밀정보와 Wi-Fi 정보를
추적 파일에 넣지 마라. 실행한 명령, 변경 파일, 검증 결과, 실패와 남은 작업을
docs/04-ubuntu-control-server.md와 README.md에 갱신하라. 완료 표시는 실제
증거가 있을 때만 사용하라. sudo 비밀번호처럼 내가 직접 입력해야 하는 경우만
멈추고 정확한 명령과 이유를 알려라.

계획만 작성하지 말고 점검, 구현, 테스트, 문서화까지 계속 진행하라.
진행
```

## 7. 현재 인계 상태

- 현재 장비에서 Ubuntu 24.04.5 LTS와 x86_64를 직접 확인했다.
- 선택한 1cm 지도는 `map/`, 현재 Nav2 파라미터는 `robot/pinky_navigation/params/`에 포함한다.
- 초기 점검 시 저장소는 깨끗한 `main`에서 `origin/main`을 추적했다. 현재 작업 트리에는 이번 운영 배포 구성과 문서 변경이 커밋 전 상태로 남아 있다.
- 공식 Docker Engine·Compose 설치와 hello-world를 확인했다. `team2`의 Docker 그룹 변경은 새 로그인 뒤 일반 셸에서 최종 확인한다.
- 운영용 Compose·이미지·환경변수 생성 도구를 작성하고 실제 빌드·배포·DB 초기화·HTTP·복구 검증을 수행했다. 세 운영 서비스는 현재 healthy 상태로 실행 중이다.
- Git에서 제외한 `.env` 두 파일에 지도 버전과 세 로봇 Domain을 반영했다. 초기 위치·목표 좌표는 실측 뒤 각 로봇의 `FLEET_ROBOT_*_INITIAL_*`, `FLEET_ROBOT_*_GOAL_*` 값에 입력한다.
- ROS 2 Jazzy Desktop·Nav2를 설치하고 로컬 DDS 시험을 통과했다. 공식 PinkyPro 워크스페이스에 프로젝트 지도·파라미터를 반영해 `pinky_navigation` 빌드와 설치 해시를 확인했다.
- Pinky Studio 0.2.3 설치와 Bluetooth 동작을 확인했다. Domain 40에는 아직 실물 로봇 토픽이 없으므로 앱에서 `62b2`를 현재 노트북과 같은 Wi-Fi에 연결한 뒤 실제 노드·토픽·액션을 수집해야 한다.
- 비공개 GitHub 저장소 `rheffi/pinky-fleet-control`의 `main`에 게시했다.
- Git 초기화·추적 파일 검사·첫 커밋·원격 push와 오프라인 전달용 bundle 검증 결과는 README에 남긴다.
