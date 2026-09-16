# 01. 개발환경 — 상태 확인과 실행 계획

작성·확인: 2026-09-16 / 대상: 현재 Windows 개발 노트북

상위 문서: [프로젝트 메인](../README.md)

**현재 결론: E1~E7 완료. Laravel·Vue·MySQL 개발환경이 실행되며, 화면·API·DB 저장·전체 서비스 재시작 후 데이터 유지·Vue 배포 빌드를 검증했다. E8의 버전·실행 방법·결과 기록도 이 문서에 반영했다.**

접속 주소는 [개발환경 확인 화면](http://127.0.0.1:8080/)이며, localhost:8080도 사용한다. 현재 web·app·mysql·node 4개 서비스가 실행 중이다. 이 화면은 환경 확인용이고 실제 로봇 연결·알고리즘 통합·5080 Ubuntu 배포는 아직 수행하지 않았다.

## 1. 작업 계획과 범위

- 설치 여부와 실행 여부를 구분하여 Docker·WSL2 상태를 확인한다.
- 기존 PHP·Node·DB와 충돌하지 않는 프로젝트 전용 Docker 구성을 정한다.
- Laravel·Vue·MySQL을 실행하기 위한 순서와 완료 기준을 작성한다.
- 같은 구성으로 Ubuntu에 조기 배포할 수 있게 개발·배포 설정을 구분한다.
- 실행할 때마다 이 문서의 결과와 메인 README의 진행 상태를 갱신한다.

이번 단계의 완료 조건인 화면 표시, API 응답, DB 저장 및 서비스 재시작 후 유지를 모두 확인했다. 노트북 OS 재부팅과 인터넷 차단 상태의 현장 시연은 후속 배포 단계에서 검증한다.

## 2. 직접 확인한 환경

### 2.1 초기 점검 당시 설치·실행 상태

아래 표의 정지·미검증 상태는 최초 점검 시점의 기록이다. 이후 기동 및 실행 검증 결과는 2.3과 5.3에 기록한다.

| 항목 | 확인 결과 | 판단 |
|---|---|---|
| OS | Windows 11 Pro, 64비트, 10.0.26200 / 25H2 | Windows 개발 환경 |
| Docker Desktop | 실행 파일 버전 4.43.1.198352 | 설치되어 있음 |
| Docker CLI | 28.3.0, windows/amd64 | 클라이언트 실행 가능 |
| Docker Compose | v2.38.1-desktop.1 | 별도 Compose 설치 우선 불필요 |
| Docker context | desktop-linux 선택됨 | Linux 엔진 대상 |
| Docker 서버 | dockerDesktopLinuxEngine 파이프 없음, version/info 연결 실패 | 엔진 실행·컨테이너 구동 미검증 |
| Docker Desktop 프로세스 | 조회 시 일치하는 프로세스 없음 | 앱 정지 상태로 판단 |
| WSL | 2.5.9.0 | 설치되어 있음 |
| WSL 커널 | 6.6.87.2-1 | 버전 조회 성공, 배포판 기동 시험은 미수행 |
| WSL 기본 버전 | 2 | WSL2 기본값 |
| WSL 기본 배포판 | Ubuntu-24.04, Stopped, VERSION 2 | 등록됨·현재 정지 |
| 다른 배포판 | Ubuntu-22.04, Stopped, VERSION 2 | 등록됨·현재 정지 |
| Docker 배포판 | docker-desktop, Stopped, VERSION 2 | 등록됨·현재 정지 |
| 서비스 | WslService Running, com.docker.service·vmcompute Stopped | 서비스 상태만으로 Docker 정상 동작을 확정하지 않음 |
| TCP 8080·5173 | 점검 시 수신 포트 목록에서 미발견 | 개발 포트 후보, 실행 직전 재확인 |
| TCP 3306 | IPv4·IPv6 수신 확인 | 이미 점유됨. 점유 프로그램은 이번에 확인하지 않음 |

Windows 레지스트리 ProductName은 Windows 10 Pro로 남아 있었으나, Win32_OperatingSystem의 Caption은 Windows 11 Pro였다. OS 표기는 Caption과 실제 빌드를 기준으로 기록했다.

### 2.2 점검 시 발생한 오류와 해석

첫 샌드박스 조회에서는 Docker 사용자 설정 접근이 거부되어 context가 default로 표시됐다. 제한 밖에서 같은 상태 명령을 다시 실행하자 실제 context인 desktop-linux가 확인됐다. 따라서 첫 조회의 default를 실제 설정으로 채택하지 않는다.

WSL 조회도 제한 밖에서는 성공했다. 이전 E_ACCESSDENIED는 WSL 미설치의 증거가 아니며, 현재 두 Ubuntu 배포판과 docker-desktop이 WSL2로 등록되어 있음을 확인했다.

초기 점검에서는 Docker 엔진 연결 실패가 재조회에서도 유지됐다. 이후 Docker Desktop이 실행된 상태에서는 샌드박스가 Windows 엔진 파이프 접근을 거부했다. 사용자 권한 변경 후 같은 desktop-linux 엔진에 연결하여 조회·샘플 실행에 성공했다. 접근 거부를 엔진 고장으로 판단하지 않는다.

### 2.3 권한 변경 후 실제 실행 검증

| 항목 | 확인 결과 |
|---|---|
| Docker Desktop | 조회 시 이미 실행 중, 이 작업에서 시작·재시작하지 않음 |
| Client / Server | 모두 28.3.0, version 명령 종료 코드 0 |
| 엔진 환경 | OS=linux, Arch=x86_64, OperatingSystem=Docker Desktop |
| 이미지 다운로드 | Docker Hub library/hello-world:latest 다운로드 성공 |
| 이미지 플랫폼 | linux/amd64 |
| 샘플 실행 | Hello from Docker! 출력, 종료 코드 0 |
| 샘플 컨테이너 | --rm으로 종료 후 제거됨. 지정 이름의 잔여 컨테이너 없음 확인 |
| 남은 이미지 | hello-world 이미지는 로컬 캐시에 유지 |

## 3. 결정 사항과 권장 구성

### 3.1 이미 결정한 사항

- 현재 Windows에서는 Docker Desktop의 WSL2 Linux 엔진을 사용한다.
- 별도 관제 노트북에는 Ubuntu와 Docker Engine + Compose를 준비한다.
- PHP·Composer·Node·MySQL은 프로젝트 컨테이너에 둔다.
- 호스트 PHP 7.4와 기존 Node·DB 설정은 변경하지 않는다.
- 코드와 설정은 Git으로 공유하고, 시연에는 미리 빌드한 Linux 이미지를 전달한다.

### 3.2 구현에 사용할 권장값

다음은 계획에서 채택한 기본 구성이다. E4~E7에서 실제 설치·빌드를 완료했으며, 정확한 실행 버전과 이미지 식별값은 5.5에 기록한다.

| 항목 | 권장값 | 선택 이유·확인 방법 |
|---|---|---|
| 구성 관리 | 직접 관리하는 Docker Compose | 개발·Ubuntu 배포 설정을 한 프로젝트에서 관리 |
| Laravel | 13.x | 공식 문서에서 PHP 8.3 이상 지원 확인 |
| PHP | 8.4 FPM | Laravel 13 지원 범위, 기존 PHP와 분리 |
| Composer | 2.x, PHP 앱 이미지에 포함 | 호스트 PHP를 사용하지 않고 의존성 설치 |
| Vue | 3.x, JavaScript 기반 SFC | Laravel이 제공하는 화면에 Vue를 연결, 단일 관제부터 구현 |
| 프런트 빌드 | Vite + Laravel/Vue 플러그인 | 실제 선택 버전 간 호환성을 설치·빌드로 확인 |
| Node | 24 LTS, 24.12 이상 패치 | 현행 Vue 시작 안내 요구 범위 고려 |
| MySQL | 8.4 LTS | 별도 볼륨으로 데이터 유지 |
| 웹 서버 | Nginx 안정 버전 | Laravel public 디렉터리와 빌드된 Vue 제공 |
| 웹 포트 | 개발 127.0.0.1:8080 → web:80 | 점검 시 8080 미점유 |
| Vite 포트 | 개발 127.0.0.1:5173 → node:5173 | 개발 중 화면 갱신용, 배포에서는 제외 |
| DB 포트 | 컨테이너 내부 mysql:3306만 사용 | 기존 호스트 3306과 충돌 방지 |

Composer·npm의 실제 패치 버전은 composer.lock·package-lock.json에 저장했다. Docker 기본 태그는 계열 태그이므로 향후 새로 pull/build하면 바뀔 수 있다. 이번에 검증한 이미지 digest를 5.5에 기록하며, 현장 배포는 검증한 이미지 자체를 내보내 사용하는 계획이다. 앱 패키지 설치·자동 테스트·Vue 빌드 조합을 검증했다.

Sail은 필수로 추가하지 않는다. Nginx·PHP·MySQL·Node의 기본 Compose를 직접 작성해 개발과 배포의 차이를 명확히 관리한다. Redis, Python 알고리즘, 작업 worker는 해당 기능 단계에서 필요에 따라 추가한다.

### 3.3 코드 위치와 편집 방식

- 기준 경로는 현재 프로젝트 폴더 `C:\Users\cch97\Desktop\project\pinky-fleet-control`을 유지한다.
- 우선 PowerShell의 Docker CLI와 기존 IDE로 개발한다. WSL Ubuntu 배포판에 PHP·MySQL을 따로 설치하지 않는다.
- Windows 파일을 개발 컨테이너에 연결하고 vendor·node_modules에는 전용 볼륨을 사용하는 안으로 시작한다. 패키지 명령은 컨테이너 안에서 실행한다.
- 파일 변경 감지·성능은 실제로 시험한다. 문제가 있을 때만 Vite polling 또는 WSL Linux 파일시스템으로 작업 위치를 옮기는 방안을 선택한다. 두 작업 복사본을 동시에 수정하지 않는다.
- Windows 바인드 마운트의 성능 특성 때문에 이 안의 속도를 보장하지 않는다. 먼저 작은 앱으로 확인한다.

### 3.4 후속 단계에서 확인할 항목

- 5080 Ubuntu에서 동일 코드·이미지를 실행했을 때의 동작과 파일 권한.
- 인터넷 차단·OS 재부팅 후 실행 재현.
- 현장 Ubuntu의 CPU 구조, 포트, IP, 실제 로봇 API 접근.

이 항목들은 현재 노트북의 개발환경 완료와 구분해 배포·실물 연결 단계에서 검증한다.

## 4. 반영된 실행 계획

### 4.1 컨테이너 역할

```text
브라우저 ── localhost:8080 ── web (Nginx)
                                │
                              app (PHP-FPM + Composer)
                                │
                              mysql:3306 ── DB 전용 볼륨

개발 중: 브라우저 ── localhost:5173 ── node (Vite)
배포 시: 빌드된 Vue를 web에서 제공, Vite 개발 서버 제외
```

web과 app은 일치하는 앱 코드·public 경로를 사용해야 한다. PHP-FPM 9000과 MySQL 3306은 호스트에 공개하지 않는다. app의 DB_HOST는 localhost가 아닌 Compose 서비스명 mysql이다.

컨테이너 내부 HTTP/Vite 서비스는 컨테이너 밖에서 접근할 수 있게 수신 주소를 설정한다. 현장 배포에서는 web의 호스트 바인딩을 Ubuntu 내부망에서 접근할 수 있도록 변경하고 방화벽·IP를 검증한다. 앱과 Vue는 같은 관제 주소를 사용하도록 구성한다.

### 4.2 실행 순서와 완료 기준

아래 표는 계획과 누적 실행 상태다. 실제 검증이 끝난 항목만 완료로 표시한다.

| 순서 | 실행할 작업 | 완료 기준 | 상태 |
|---|---|---|---|
| E1 | Docker Desktop 실행 상태 확인 후 docker version·info 조회 | Server 정보와 OSType=linux 확인 | 완료 — 이미 실행 중, 서버 28.3.0·linux 확인 |
| E2 | 작은 Linux 컨테이너 실행·이미지 다운로드 확인 | 컨테이너 실행 성공, 오류 원인 없거나 해결 기록 | 완료 — hello-world 다운로드·실행 성공, 종료 코드 0 |
| E3 | 프로젝트 전용 Compose·PHP Dockerfile·Nginx 설정·환경변수 예시 작성 | Compose 설정 검증 성공, 기존 프로젝트와 이름·포트 분리 | 완료 — Compose·Dockerfile 정적 검사·Nginx 문법 검사 성공 |
| E4 | PHP 컨테이너로 Laravel 생성, Node 컨테이너로 Vue·Vite 의존성 설치 | 호스트 PHP 없이 설치, lock 파일 생성 | 완료 — PHP 이미지 빌드, Composer/npm 설치·lock 생성 |
| E5 | 앱 키·DB 연결·마이그레이션 준비, web/app/mysql/node 시작 | 서비스 준비 확인, DB 연결·마이그레이션 성공 | 완료 — 4개 마이그레이션, 4개 서비스 실행 |
| E6 | 최소 화면·상태 확인 API·DB 저장 확인 | localhost:8080 화면 및 API 응답, Vue 수정 반영 | 완료 — 실제 API·DB, 브라우저 렌더링·HMR, 검증 값 저장 |
| E7 | 서비스 재시작과 프런트 배포 빌드 | 테스트 DB 데이터 유지, npm 빌드 성공 | 완료 — 전체 stop/start 후 값 유지, Vite 정지 상태에서 빌드 화면 확인 |
| E8 | 실제 버전·실행 방법·문제·결과 기록 | 이 문서와 README 갱신 | 완료 — E4~E7 수행 결과를 함께 기록 |

Laravel 생성은 문서가 들어 있는 현재 폴더를 비우지 않고 임시 생성 위치를 이용한다. README·기존 문서·.gitignore 충돌을 확인한 후 필요한 앱 파일만 반영한다.

프로젝트 이름과 볼륨 이름은 pinky-fleet-control 전용으로 분리한다. DB healthcheck를 사용하고, 의존 서비스가 준비된 뒤 초기화를 진행한다. DB 유지 시험에서는 볼륨을 삭제하지 않는다.

### 4.3 Ubuntu 배포에 이어질 준비

- 개발 Compose와 배포 Compose를 구분한다. 개발의 소스 바인드 마운트·Vite 서버가 배포에 남지 않게 한다.
- 배포 이미지는 Composer 의존성·Vue 빌드 결과를 포함한다. DB·웹 서버 등 사용하는 이미지도 전부 준비한다.
- 런타임 환경변수와 앱 키·비밀번호는 이미지와 Git에 넣지 않는다. .env.example에는 항목과 비밀이 아닌 예시만 둔다.
- 지도 파일·DB 볼륨은 이미지와 별도로 관리하고, 첫 배포에서 DB 초기화를 수행한다.
- 앱 파일 소유권·storage/bootstrap/cache 쓰기 권한과 컨테이너 사용자 설정을 확인한다.
- 인터넷 연결이 있을 때 빌드·의존성 준비를 끝내고, 현장에서는 이미지 불러오기와 기동만으로 동작하도록 시험한다.
- GPU·CUDA·ROS2는 웹 개발환경의 선행 설치 요건으로 추가하지 않는다. 필요한 담당 기능에서 별도 검증한다.

## 5. 실제 수행과 검증 결과

### 5.1 이번에 실행한 조회

```text
wsl --version
wsl --status
wsl --list --verbose
docker version
docker compose version
docker context ls
docker info --format "Server={{.ServerVersion}} OS={{.OSType}} Arch={{.Architecture}} OperatingSystem={{.OperatingSystem}}"
```

추가로 Windows OS 정보, Docker 실행 파일 버전, 관련 서비스·프로세스, 8080·5173·3306 TCP 수신 상태를 읽었다. Docker 설정 파일의 비밀정보나 인증 내용은 출력하지 않았다.

| 확인 항목 | 결과 |
|---|---|
| WSL 설치·등록 목록 | 성공, 두 Ubuntu와 Docker 배포판 모두 WSL2 |
| Docker 클라이언트·Compose | 버전 조회 성공 |
| Docker Linux 서버 연결 | 권한 변경 후 성공, Server 28.3.0·linux/x86_64 |
| 설치·업데이트·서비스 시작 | 이 작업에서는 미수행, 재시도 시 Docker Desktop이 이미 실행 중 |
| 이미지 pull·컨테이너 실행 | hello-world 다운로드·실행 성공, 종료 코드 0 |
| Compose·Dockerfile·Nginx 설정 | 작성 및 설정 검증 성공. Nginx 검사 컨테이너 정상 종료 |
| PHP 앱 이미지 실제 빌드·MySQL 기동 | 성공, 앱 이미지 생성·MySQL healthy |
| Laravel·Vue 생성 및 빌드 | 성공, Laravel 13.32.0·Vue 3.5.42·Vite 8.3.0 |
| DB 저장·재시작 시험 | 성공, 전체 서비스 stop/start 전후 UUID·저장 시각 일치 |
| 자동 테스트·코드 스타일 | PHPUnit 4 passed / 12 assertions, Pint 57 files PASS |
| Ubuntu 배포·로봇 연결 | 미수행 |

### 5.2 작업 로그

| 날짜 | 내용 | 결과·다음 작업 |
|---|---|---|
| 2026-09-16 | 현재 노트북 Docker·WSL2 상태 확인 | 이미 설치됨, Docker 엔진은 연결 불가. 재설치보다 기존 앱 시작·엔진 검증부터 진행 |
| 2026-09-16 | 개발환경 계획 작성 | 권장 버전·서비스·포트·순서 정리. 다음 작업 E1 |
| 2026-09-16 | E1·E2 재시도 및 검증 | 사용자 권한 변경 후 Linux 엔진 연결, 공식 hello-world 이미지 다운로드·실행 성공. E1·E2 완료, 다음 E3 |
| 2026-09-16 | E3 개발용 Docker 설정 작성·검증 | 4개 서비스, 전용 볼륨, localhost 포트 구성. Compose 검증·Dockerfile 정적 검사·Nginx 문법 검사 모두 성공. 다음 E4 |
| 2026-09-16 | E4~E5 앱 구성·서비스 실행 | Laravel 원본 골격을 임시 위치에서 생성 후 문서 보존 병합. PHP 이미지·Composer/npm 설치, 앱 키·DB 마이그레이션, 4개 서비스 기동 |
| 2026-09-16 | E6 화면·API·저장·수정 반영 | 브라우저에서 API·DB 연결 표시, 임시 Vue 문구로 HMR 확인 후 원복, DB 검증 UUID 저장 |
| 2026-09-16 | E7 재시작·배포 빌드 | 전체 서비스 stop/start 후 UUID·저장 시각 유지. 남은 public/hot 문제 수정 후 Vite 없이 화면·정적 파일·API 정상 확인 |
| 2026-09-16 | E8 결과·실행 방법 기록 | 자동 테스트 4개·Pint 통과, 버전 및 재현 명령 기록, 개발용 4개 서비스 실행 상태로 복귀 |

### 5.3 E1·E2 실행 증거

실제로 실행한 명령:

```powershell
docker --context desktop-linux version --format 'Client={{.Client.Version}} Server={{.Server.Version}}'
docker --context desktop-linux info --format 'Server={{.ServerVersion}} OS={{.OSType}} Arch={{.Architecture}} OperatingSystem={{.OperatingSystem}}'
docker --context desktop-linux run --rm --pull=always --name pinky-fleet-env-check hello-world:latest
docker --context desktop-linux image inspect hello-world:latest --format 'ID={{.Id}} Platform={{.Os}}/{{.Architecture}} Digests={{json .RepoDigests}}'
docker --context desktop-linux ps -a --filter 'name=^/pinky-fleet-env-check$' --format '{{.Names}} {{.Status}}'
```

- 엔진 출력: `Client=28.3.0 Server=28.3.0`, `OS=linux Arch=x86_64`.
- 샘플 실행 출력: `Hello from Docker!`, 종료 코드 `0`.
- 다운로드한 이미지 digest: `hello-world@sha256:5e23090353324d887c48ad5e5c56d294eab81588df9605b07d1afe895f9cc8f8`.
- 로컬 image ID: `sha256:e2ac70e7319a02c5a477f5825259bd118b94e8b02c279c67afa63adab6d8685b`.
- 샘플 컨테이너 조회 결과: 출력 없음. 임시 컨테이너 제거 확인.
- 변경 범위: 샘플 이미지 캐시 추가, 임시 컨테이너 실행·자동 제거, README와 이 문서 갱신. 앱·DB·기존 로봇 프로젝트 변경 없음.

### 5.4 E3 작성 파일과 검증 결과

새 상세 문서는 추가하지 않고 이 문서에 결과를 누적한다.

| 파일 | 역할 |
|---|---|
| [compose.yaml](../compose.yaml) | web·app·mysql·node 개발 서비스, 프로젝트 이름·포트·볼륨·DB 준비 대기 |
| [PHP Dockerfile](../docker/php/Dockerfile) | PHP 8.4 FPM, Composer 2, bcmath·intl·mbstring·pdo_mysql·zip 확장 빌드 정의 |
| [PHP 개발 설정](../docker/php/development.ini) | 시간대·메모리·업로드·로그 설정 |
| [Nginx 설정](../docker/nginx/default.conf) | public 웹 루트, Laravel index.php를 app:9000으로 전달 |
| [.env.example](../.env.example) | 실제 비밀값이 없는 앱·DB·포트 환경변수 템플릿 |
| [.dockerignore](../.dockerignore) | 비밀 설정·의존성 폴더·작업 파일을 빌드 컨텍스트에서 제외 |
| [.gitattributes](../.gitattributes) | Docker·설정 파일의 LF 줄바꿈 유지 |
| [.gitignore](../.gitignore) | 기존 제외 규칙에 Vite hot 파일·Laravel 캐시 추가 |

구성 상세:

- 프로젝트 이름은 `pinky-fleet-control`, 볼륨은 `mysql_data`, `vendor`, `node_modules`이며 실제 이름에 프로젝트 접두사가 붙는다.
- web은 `127.0.0.1:8080`, node는 `127.0.0.1:5173`에 연결한다. 재조회에서도 두 포트의 수신은 없었고 호스트 3306은 점유 상태였다.
- MySQL 3306과 PHP-FPM 9000에는 호스트 포트 공개가 없다.
- MySQL healthcheck는 앱 DB 계정으로 TCP 연결해 `SELECT 1`을 실행하는 구성이다. app은 MySQL이 healthy가 된 뒤 시작하도록 정의했다. E5에서 실제 healthy 상태와 마이그레이션 성공을 확인했다.
- `.env`의 DB 비밀번호가 비어 있으면 Compose가 설정 오류를 내도록 했다. 이번에는 `.env.example`의 명시적인 예시값을 **설정 검증에만** 사용했으며 DB를 생성하지 않았다.
- 실제 `.env`는 E4에서 서로 다른 무작위 DB 비밀번호와 앱 키를 생성해 준비했다. 비밀값은 문서·Git에 넣지 않는다. 기존 Compose 변수와 Laravel에 필요한 앱 설정을 유지했다.
- 개발용 바인드 마운트 구성이다. Ubuntu 시연용 이미지·배포 Compose는 아직 작성하지 않았다.

프로젝트 폴더에서 실행한 설정 검사:

```powershell
docker compose --env-file .env.example config --format json
docker --context desktop-linux buildx build --check --progress=plain -f docker/php/Dockerfile .
docker --context desktop-linux run --rm --network none --add-host app:127.0.0.1 --mount 'type=bind,source=C:\Users\cch97\Desktop\project\pinky-fleet-control\docker\nginx\default.conf,target=/etc/nginx/conf.d/default.conf,readonly' --entrypoint nginx nginx:stable-alpine -t
```

첫 명령의 결과 JSON은 검사 코드에서만 읽었고 환경변수 전체를 출력하지 않았다. 실제 비밀값을 설정한 이후에도 config 전체 출력을 외부에 공유하지 않는다.

| 검사 | 실제 결과 |
|---|---|
| Compose 모델 | 정상 파싱, 4개 서비스·파일 경로·전용 볼륨·loopback 포트·DB 내부 연결·준비 대기 확인 |
| Dockerfile 정적 검사 | `Check complete, no warnings found.`, 종료 코드 0 |
| Nginx 문법 검사 | `syntax is ok`, `test is successful`, 종료 코드 0 |

Nginx 검사는 외부 네트워크·호스트 포트 없이, `app`을 테스트용 127.0.0.1로 이름 해석하도록 해서 설정 문법만 확인했다. PHP에 실제 요청을 전달하거나 Laravel 화면을 검증한 시험이 아니다.

검사에 사용한 Nginx 이미지 digest: `nginx@sha256:dc5069ad14f19660b141b21236140b91656bf89bbc3e2417c70ae650cd66104c`. 이미지 캐시는 유지된다. PHP Dockerfile의 `--check`는 확장 컴파일·앱 이미지 생성을 수행하지 않으므로 실제 빌드 성공과 구분한다.

E4는 `.env` 준비 → PHP 이미지 실제 빌드 → 임시 위치에 Laravel 생성 및 기존 문서 보존 병합 → 컨테이너에서 Vue 의존성 설치 순서로 완료했다. Composer·Node의 일회성 설치 명령에는 `docker compose run --rm --no-deps ...`를 사용했다.

### 5.5 E4~E7 실행 결과

#### 구현 파일과 동작

- Laravel 원본 골격 `laravel/laravel v13.10.1`에서 시작했다. 기존 README·문서·Docker 파일·환경 설정을 덮어쓰지 않고 병합했다.
- Composer의 기본 host npm 실행용 setup/dev 스크립트와 사용하지 않는 pail/pao 개발 패키지는 제외했다. 모든 PHP·Node 명령은 컨테이너에서 수행한다.
- [HealthController](../app/Http/Controllers/HealthController.php): `GET /api/health`에서 MySQL에 실제 `SELECT 1`을 실행한다. 정상은 HTTP 200, DB 실패는 세부 연결정보 없이 HTTP 503을 반환한다.
- [Vue 화면](../resources/js/App.vue): API·DB 연결과 마지막 확인 시각, 다시 확인 버튼을 표시한다. 로봇 연결은 후속 단계로 표시한다. 외부 CDN·폰트 요청은 사용하지 않는다.
- [DB 확인 명령](../app/Console/Commands/CheckEnvironment.php): `app:check-environment --write`로 검증 UUID를 저장하고 `--expect=...`로 재시작 후 동일 값인지 확인한다.
- [검증 테이블 마이그레이션](../database/migrations/2026_09_16_000001_create_environment_checks_table.php): `environment_checks`에 저장한다. 기존 기본 users/cache/jobs 마이그레이션을 포함해 총 4개를 실행했다.
- [API 테스트](../tests/Feature/HealthCheckTest.php): DB 성공 및 실패 응답과 오류 상세정보 비노출을 검사한다. PHPUnit의 테스트 DB 환경은 강제로 SQLite 메모리 설정을 사용하게 해 실제 MySQL과 분리했다.
- [Vite 설정](../vite.config.js): Windows 파일 변경을 polling으로 감지하고 localhost 개발 서버의 HMR 주소를 명시했다.
- [package.json](../package.json): 배포 빌드 성공 후 `public/hot`을 정리한다. Laravel 골격의 `.npmrc`는 ignore-scripts=true이므로 자동 postbuild 훅에 의존하지 않고 build 명령에 직접 연결했다.

#### 확인한 실행 버전

| 항목 | 실제 버전 |
|---|---|
| PHP / Composer | 8.4.25 / 2.10.3 |
| Laravel Framework | 13.32.0 |
| Node / npm | 24.21.0 / 11.19.0 |
| Vue / Vite | 3.5.42 / 8.3.0 |
| Vue 플러그인 / Laravel Vite 플러그인 | 6.0.9 / 3.2.0 |
| MySQL / Nginx | 8.4.11 / 1.30.4 |

PHP 앱 이미지 ID: `sha256:443e6da9c39a1d9da2cffd84fae296a53ce2ed3f8db88cf5a502122ab3aae1f5`.

이번 빌드·실행의 원본 이미지 digest:

| 이미지 | SHA256 |
|---|---|
| php:8.4-fpm-bookworm | `075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e` |
| composer:2 | `d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332` |
| node:24-bookworm-slim | `2fe369e969550cde8e867afc3fe370b260140cab4a23d467074295b42163d553` |
| mysql:8.4 | `85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a` |
| nginx:stable-alpine | `dc5069ad14f19660b141b21236140b91656bf89bbc3e2417c70ae650cd66104c` |

#### 검증 결과와 실제 한계

| 검증 | 결과 |
|---|---|
| 서비스 | web/app/node running, mysql running·healthy |
| HTTP | `/`와 `/api/health` 200, database=connected |
| 브라우저 | API·DB 연결 표시, 다시 확인 버튼 동작 |
| HMR | Vue 제목에 임시 HMR-CHECK 추가 후 수동 새로고침 없이 반영 확인, 원래 파일로 복구 |
| DB 저장·유지 | `--write` UUID와 저장 시각이 전체 4개 서비스 stop/start 후 동일함. `--expect` 종료 코드 0 |
| 자동 테스트 | 4 passed, 12 assertions |
| PHP 스타일 | Pint 57 files PASS |
| 배포 빌드 | npm run build 성공. JS 약 63.72 kB, CSS 약 3.76 kB 생성 |
| 개발 서버 없는 화면 | node 중지 상태에서 빌드 HTML·JS·CSS HTTP 200, 5173 참조 없음, 브라우저 렌더링·새로고침 버튼·API 정상, 해당 확인 탭 콘솔 오류 없음 |
| 종료 시 상태 | 개발을 이어갈 수 있도록 node를 다시 시작해 4개 서비스 실행 |

처음 정적 화면 검사에서는 남은 `public/hot` 때문에 HTML이 중지된 Vite 서버를 참조했다. 빌드 명령의 정리 단계로 해결 후 재검증했다. 재시작 중 열린 인앱 브라우저 탭에 연결 오류 페이지가 남아 새 IPv4 로컬 탭에서 정상 렌더링을 확인했다. 이것을 실제 서버 실패나 로봇 통신 실패로 기록하지 않는다.

이 결과는 현재 Windows 노트북의 Docker 개발환경에 대한 검증이다. Ubuntu 배포, 인터넷 차단, OS 재부팅, 실물 주행·충돌 회피를 검증한 결과가 아니다. 별도의 배포용 Compose와 앱 코드가 포함된 배포 이미지는 아직 만들지 않았다.

### 5.6 자주 사용하는 명령

아래 명령은 `.env`·의존성·DB 초기화를 마친 **현재 프로젝트 폴더의 PowerShell**에서 실행한다. 새 PC에서는 먼저 Docker와 이미지·환경변수·의존성을 준비해야 한다. 호스트의 PHP 7.4나 npm으로 실행하지 않는다.

```powershell
# 개발환경 시작 / 상태
docker compose up -d --wait
docker compose ps

# 자동 테스트
docker compose exec -T app php artisan test --compact
docker compose exec -T app vendor/bin/pint --test

# 현재 데이터 확인 (값을 새로 쓰지 않음)
docker compose exec -T app php artisan app:check-environment

# 배포 빌드 파일로 화면 확인: 먼저 개발 서버를 멈춘다.
docker compose stop node
docker compose run --rm --no-deps node npm run build

# 화면 개발로 복귀
docker compose up -d node

# 전체 정지: 데이터 볼륨은 유지된다.
docker compose stop
```

접속: [화면](http://127.0.0.1:8080/) / [상태 API](http://127.0.0.1:8080/api/health). `docker compose down -v`는 DB·의존성 볼륨을 삭제하므로 일반 정지·재시작에 사용하지 않는다.

DB 유지 검증을 다시 할 때는 다음처럼 쓴다. 검증용 UUID는 인증 토큰이 아니다.

```powershell
$probe = docker compose exec -T app php artisan app:check-environment --write | ConvertFrom-Json
docker compose stop
docker compose up -d --wait
docker compose exec -T app php artisan app:check-environment "--expect=$($probe.token)"
```

다음 작업은 메인 계획의 2단계인 최소 관제 API·화면 범위와 데이터 규약 정리다. 새 세부 문서는 그 작업을 시작할 때 하나만 작성한다.

### 5.7 다음 실행 후 추가할 기록

- 수행 날짜 / 작업 ID:
- 실제 명령 / 변경 파일:
- 설치·빌드된 버전 / 이미지 식별값:
- 성공한 검증 / 실패 로그 요약:
- 남은 문제 / README 상태 변경:
- 바로 다음 작업:

## 공식 근거

2026-09-16 확인. 아래 문서의 요구 조건과 실제 설치된 환경을 구분한다.

- [Docker Desktop Windows 요구 조건](https://docs.docker.com/desktop/setup/install/windows-install/) — 일반 WSL2 구성에 WSL 2.1.5 이상 요구. 조회한 2.5.9는 버전 조건 충족. 이 노트북의 실제 Linux 컨테이너 실행도 확인 완료.
- [Docker WSL2 구성](https://docs.docker.com/desktop/features/wsl/) — Windows에서 Linux 컨테이너를 실행하는 구성.
- [Laravel 13 릴리스·PHP 지원 범위](https://laravel.com/framework/docs/13.x/releases) — PHP 8.3 이상, PHP 8.4 포함.
- [Vue 시작 안내](https://vuejs.org/guide/quick-start) · [Vite 시작 안내](https://vite.dev/guide/) — 선택하는 템플릿·플러그인에 따라 요구 Node 버전 재확인.
- [Node.js 릴리스 관리](https://github.com/nodejs/Release) — Node 24 LTS 계열 기준.
- [MySQL 8.4 매뉴얼](https://dev.mysql.com/doc/refman/8.4/en/) — MySQL 8.4 계열 사용 계획.
- [Compose 배포 구성](https://docs.docker.com/compose/how-tos/production/) — 개발과 배포 설정 분리.
