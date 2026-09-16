# 04. Ubuntu 관제서버 배포 준비와 인계

갱신: 2026-09-16

## 1. 목표와 범위

3단계 목표는 Ubuntu 24.04 관제 노트북에 이 프로젝트를 운영용 Docker 구성으로 배포하고, 내부망에서 Laravel·Vue 관제 화면과 MySQL을 재현하는 것이다. 그 다음 Domain 40 로봇 `62b2`의 실제 ROS 상태를 한 대 연결한다.

이번 Windows 작업의 범위는 다음과 같다.

- 프로젝트를 Git으로 안전하게 전달할 수 있게 초기화한다.
- 실제 `.env`, 비밀번호, 키, 빌드 임시 파일이 추적되지 않는지 확인한다.
- Ubuntu의 Codex가 이어서 수행할 작업과 완료 기준을 이 문서에 남긴다.
- Ubuntu 설치·운영 Compose 작성·실제 배포는 Ubuntu 노트북에서 수행한다.

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

원격 저장소 주소가 정해지기 전까지 로컬 Git 저장소만 준비한다. 저장소 주소나 인증 토큰을 문서에 기록하지 않는다.

원격 저장소가 늦어질 경우 프로젝트 루트의 `artifacts/pinky-fleet-control-main.bundle`을 USB로 전달할 수 있다. `artifacts/`는 Git 추적 대상이 아니다.

```powershell
git bundle create artifacts/pinky-fleet-control-main.bundle main
git bundle verify artifacts/pinky-fleet-control-main.bundle
```

### 원격 저장소가 정해진 뒤 실행할 명령

```powershell
git remote add origin <팀-원격-저장소-주소>
git push -u origin main
```

### Ubuntu에서 받을 명령

```bash
git clone <팀-원격-저장소-주소> ~/pinky-fleet-control
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

### U2. 운영용 컨테이너 구성

- 현재 개발용 `compose.yaml` 유지
- `compose.prod.yaml` 추가
- Composer 의존성과 Vue 빌드 결과를 포함한 운영 이미지 작성
- 운영 구성에서 `node`, Vite HMR, 샘플 실행기, 전체 소스 바인드 마운트 제거
- `web`, `app`, `mysql`에 healthcheck와 재시작 정책 적용
- 웹만 내부망 `0.0.0.0:8080`으로 공개
- PHP-FPM과 MySQL 포트는 호스트에 공개하지 않음
- `APP_ENV=production`, `APP_DEBUG=false` 적용

### U3. 최초 배포

- 실제 운영 `.env`를 Ubuntu에서만 생성
- 앱 키와 서로 다른 DB 계정·root 비밀번호 생성
- 이미지 빌드 후 서비스 기동
- DB migration 실행
- `/`, `/api/health`, 정적 JS·CSS 응답 확인
- 다른 내부망 기기에서 `http://Ubuntu-IP:8080` 접속 확인
- 컨테이너 재시작 후 MySQL 데이터 유지 확인

### U4. 오프라인·재부팅 검증

- 필요한 이미지를 tar 파일로 내보낼 명령과 복구 명령 기록
- 외부 CDN·클라우드 요청이 없는지 확인
- 인터넷을 끊고 화면·API·DB 동작 확인
- Ubuntu 재부팅 후 Docker 서비스와 관제 컨테이너 자동 복구 확인

### U5. 단일 로봇 상태 연결

- Ubuntu 호스트에 ROS 2 Jazzy와 PinkyPro 워크스페이스 준비
- `ROS_DOMAIN_ID=40`에서 `62b2` 토픽 조회 확인
- 장기 실행 ROS 어댑터가 실제 위치·Nav2 상태·수신 시각을 Laravel API에 보고하도록 구성
- 관제 화면에서 샘플과 실제 상태를 구분
- ROS 메시지가 일정 시간 끊기면 도착이 아니라 `stale/offline`으로 처리
- 한 대가 안정되면 Domain 30·35 어댑터를 별도 프로세스로 확장

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

- Ubuntu 24.04 사용은 사용자 확인 사항이다.
- 선택한 1cm 지도는 `map/`, 현재 Nav2 파라미터는 `robot/pinky_navigation/params/`에 포함한다.
- 운영용 Compose와 Ubuntu 실배포는 아직 수행하지 않았다.
- 원격 Git 저장소 주소는 아직 정해지지 않았다.
- Git 초기화·추적 파일 검사·첫 커밋과 오프라인 전달용 bundle 검증 결과는 README에 남긴다.
