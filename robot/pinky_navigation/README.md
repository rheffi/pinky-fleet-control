# PinkyPro Nav2 인계 파일

이 폴더에는 현재 공통 지도와 함께 검증 중인 PinkyPro Nav2 파라미터를 보관한다.

- 대상 패키지 경로: `pinky_pro/src/pinky_pro/pinky_navigation/params/nav2_params.yaml`
- 현재 지도: `map/cbs_map.yaml`, `map/cbs_map.pgm`
- 지도 크기: 140 × 205 px
- 지도 해상도: 0.01 m/px
- 지도 원점: `[-0.209, -1.738, 0]`
- 주요 값: local/global resolution 0.01, inflation radius 0.12, cost scaling 3.0, footprint padding 0.01

로봇에 적용할 때는 source 공간에 복사한 뒤 반드시 해당 패키지를 다시 빌드한다.

```bash
cp nav2_params.yaml \
  ~/pinky_pro/src/pinky_pro/pinky_navigation/params/nav2_params.yaml

cd ~/pinky_pro
colcon build --packages-select pinky_navigation
```

실행 전에 `src`와 `install` 파일의 SHA-256이 같은지 확인한다. 현재 수령본은 1.43m 첫 주행 성공 기록이 있지만, 지도 아래쪽 좁은 막다른 슬롯을 목표로 한 주행은 ABORTED 이력이 있다. 해당 구역은 시연 목표에서 제외하고 별도 검증한다.
