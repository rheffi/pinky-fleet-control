<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { api } from './fleet/api';
import { worldToPixel } from './fleet/map';

const bootstrap = ref(null), snapshot = ref(null), history = ref([]), events = ref([]);
const selected = ref(null), detail = ref(null);
const loading = ref(true), busy = ref(false), networkError = ref(''), commandError = ref('');
const lastSuccess = ref(0), clock = ref(Date.now());
const controller = new AbortController();
let timer, clockTimer, alive = true, selectionVersion = 0;
const pendingKey = 'pinky-real-pending-v1';
const colors = { eed0: '#087f8c', '648d': '#7056c7', '62b2': '#dd8732' };
const labels = {
    queued: '전달 대기', running: '이동 중', completed: '도착 완료', stopping: '정지 확인 대기',
    cancelled: '정지 완료', interrupted: '확인 필요', failed: '주행 실패', pending: '출발 대기',
    idle: '출발 가능', moving: '이동 중', arrived: '도착 완료', stopped: '정지 완료',
    unknown: '위치 확인 필요', error: '오류', online: '정상 수신', stale: '수신 지연', offline: '연결 끊김',
};

function readPending() {
    try {
        const value = JSON.parse(sessionStorage.getItem(pendingKey) || 'null');
        return value && typeof value.path === 'string' && value.body?.request_id ? value : null;
    } catch {
        return null;
    }
}

const pending = ref(readPending());
const map = computed(() => bootstrap.value?.map);
const robots = computed(() => snapshot.value?.robots || []);
const active = computed(() => snapshot.value?.active_run);
const activeRobotId = computed(() => active.value?.robots?.[0]?.robot_id || null);
const shownRun = computed(() => selected.value ? detail.value : active.value);
const staleView = computed(() => !lastSuccess.value || clock.value - lastSuccess.value > 3500 || !!networkError.value);
const projected = computed(() => robots.value.map(robot => ({ ...robot, pixel: worldToPixel(robot.pose, map.value) })));
const goals = computed(() => (bootstrap.value?.robots || [])
    .filter(robot => robot.goal_pose)
    .map(robot => ({ robot_id: robot.id, pose: robot.goal_pose, pixel: worldToPixel(robot.goal_pose, map.value) })));
const summary = computed(() => active.value
    ? activeRobotId.value + ' · ' + labels[active.value.status]
    : '로봇별 주행 준비');

const formatTime = value => value
    ? new Date(value).toLocaleTimeString('ko-KR', { hour12: false })
    : '수신 없음';
const duration = run => run?.started_at
    ? Math.max(0, Math.floor(((run.finished_at ? new Date(run.finished_at).getTime() : clock.value) - new Date(run.started_at).getTime()) / 1000)) + '초'
    : '—';
const fixed = value => Number.isFinite(Number(value)) ? Number(value).toFixed(2) : '—';

function displayState(robot) {
    if (robot.connection_state === 'offline') return 'offline';
    if (robot.connection_state === 'stale') return 'stale';
    if (!robot.pose) return 'unknown';
    return robot.motion_state;
}

function startReason(robot) {
    if (busy.value || pending.value) return '요청 처리 중';
    if (staleView.value) return '관제 서버 갱신 확인 필요';
    if (active.value) return activeRobotId.value + ' 주행 중';
    if (!robot.goal_configured) return '목표 좌표 미설정';
    if (robot.connection_state !== 'online') return labels[robot.connection_state];
    if (!robot.pose) return '현재 위치 미수신';
    if (!worldToPixel(robot.pose, map.value)) return '지도 좌표 불일치';
    return '';
}

function canStart(robot) {
    return startReason(robot) === '';
}

function lastResult(robotId) {
    for (const run of history.value) {
        const entry = run.robots?.find(item => item.robot_id === robotId);
        if (entry?.result) return entry;
    }
    return null;
}

async function loadEvents(runId, version) {
    if (!runId) {
        events.value = [];
        return;
    }
    const cursor = events.value.at(-1)?.id || 0;
    const result = await api('runs/' + runId + '/events?after_id=' + cursor, null, controller.signal);
    if (version === selectionVersion && alive) events.value = [...events.value, ...result.events].slice(-200);
}

async function selectRun(run) {
    selected.value = run?.id || null;
    detail.value = run || null;
    events.value = [];
    selectionVersion++;
}

async function poll() {
    try {
        if (!bootstrap.value) bootstrap.value = await api('bootstrap', null, controller.signal);
        const result = await api('snapshot', null, controller.signal);
        if (!alive) return;
        if (!snapshot.value || result.snapshot_revision >= snapshot.value.snapshot_revision) snapshot.value = result;
        networkError.value = '';
        lastSuccess.value = Date.now();
        history.value = (await api('runs?limit=12', null, controller.signal)).runs;
        const version = selectionVersion;
        const target = selected.value || active.value?.id || history.value[0]?.id;
        if (!selected.value && !active.value && target) selected.value = target;
        if (selected.value) {
            const response = await api('runs/' + selected.value, null, controller.signal);
            if (version === selectionVersion) detail.value = response.run;
        }
        const eventRun = selected.value || active.value?.id;
        if (events.value.length && events.value[0].run_id !== eventRun) events.value = [];
        await loadEvents(eventRun, version);
    } catch (error) {
        if (alive) networkError.value = error.message || '관제 API 응답을 확인할 수 없습니다.';
    } finally {
        loading.value = false;
        if (alive) timer = setTimeout(poll, bootstrap.value?.settings?.poll_ms || 1000);
    }
}

function savePending(value) {
    pending.value = value;
    try {
        if (value) sessionStorage.setItem(pendingKey, JSON.stringify(value));
        else sessionStorage.removeItem(pendingKey);
    } catch {
        // The in-memory request ID still protects retries in this page.
    }
}

async function sendPending() {
    if (!pending.value || busy.value) return;
    busy.value = true;
    commandError.value = '';
    try {
        const result = await api(pending.value.path, pending.value.body, controller.signal);
        savePending(null);
        await selectRun(result.run);
    } catch (error) {
        commandError.value = error.status
            ? error.message
            : '응답을 확인하지 못했습니다. 같은 요청 ID로 다시 확인해 주세요.';
        if (error.status && error.status < 500) savePending(null);
    } finally {
        busy.value = false;
    }
}

async function startRobot(robot) {
    if (!canStart(robot)) return;
    savePending({
        path: 'robots/' + robot.id + '/start',
        body: { request_id: crypto.randomUUID() },
    });
    await sendPending();
}

async function stopActive() {
    if (!active.value || busy.value || pending.value) return;
    savePending({
        path: 'runs/' + active.value.id + '/stop',
        body: { request_id: crypto.randomUUID() },
    });
    await sendPending();
}

onMounted(() => {
    poll();
    clockTimer = setInterval(() => { clock.value = Date.now(); }, 1000);
});

onUnmounted(() => {
    alive = false;
    controller.abort();
    clearTimeout(timer);
    clearInterval(clockTimer);
});
</script>

<template>
    <div class="fleet">
        <header class="fleet-header">
            <a href="/" class="fleet-brand"><span class="brand-icon">P</span><span>Pinky <strong>Fleet Control</strong><small>세 로봇, 하나의 관제</small></span></a>
            <div class="header-right"><span class="real-badge">실제 로봇</span><a href="/environment">환경 확인 ↗</a></div>
        </header>
        <main>
            <section class="sync-row">
                <div class="sync"><i :class="{ bad: staleView }"></i>{{ staleView ? '관제 갱신 확인 필요' : '관제 서버 연결됨' }}<small>화면 갱신 {{ formatTime(lastSuccess) }}</small></div>
            </section>
            <div class="real-note"><span>실로봇 관제</span> 위치와 상태는 로봇별 ROS 실행기가 전달한 정보입니다.</div>
            <div v-if="networkError" class="alert" role="alert">관제 API 연결 오류 · {{ networkError }}</div>
            <div v-if="active?.status === 'interrupted'" class="alert" role="alert">{{ active.error?.message }}</div>

            <section class="workspace">
                <article class="panel map-panel">
                    <div class="panel-title"><div><p class="eyebrow">LIVE MAP</p><h2>공통 주행 지도</h2></div><span class="tag">MAP {{ map?.version || '—' }}</span></div>
                    <div class="map-wrap">
                        <svg v-if="map" :viewBox="'0 0 ' + map.width_px + ' ' + map.height_px" :style="{ '--map-ratio': map.width_px / map.height_px }" role="img" aria-label="PinkyPro 실제 공통 주행 지도">
                            <image class="occupancy-map" :href="map.image_url" :width="map.width_px" :height="map.height_px" />
                            <g v-for="goal in goals.filter(item => item.pixel)" :key="'goal-' + goal.robot_id" :transform="'translate(' + goal.pixel.x + ',' + goal.pixel.y + ')'">
                                <circle r="12" fill="white" fill-opacity=".72" :stroke="colors[goal.robot_id]" stroke-width="2" stroke-dasharray="3 3"/>
                                <path d="M-4 0H4M0-4V4" :stroke="colors[goal.robot_id]" stroke-width="2"/>
                            </g>
                            <g v-for="robot in projected.filter(item => item.pixel)" :key="robot.id" :class="['map-robot', displayState(robot)]" :transform="'translate(' + robot.pixel.x + ',' + robot.pixel.y + ')'">
                                <circle class="robot-halo" r="19" :fill="colors[robot.id]" fill-opacity=".17"/>
                                <circle r="12" :fill="colors[robot.id]" stroke="white" stroke-width="3"/>
                                <path d="M5 -4L11 0L5 4" stroke="white" fill="none" stroke-width="2" :transform="'rotate(' + robot.pixel.heading + ')'"/>
                                <rect x="-24" y="-39" width="48" height="18" rx="5" :fill="colors[robot.id]"/>
                                <text y="-26" text-anchor="middle" fill="white" font-size="11" font-weight="700">{{ robot.id }}</text>
                            </g>
                        </svg>
                        <div v-else class="empty">지도를 불러오는 중입니다.</div>
                    </div>
                    <div class="map-footer"><span>해상도 {{ map?.resolution_m_per_pixel }}m/px</span><span>크기 {{ map?.width_px }}×{{ map?.height_px }}px</span><span>좌표 frame: {{ map?.frame_id }}</span></div>
                    <p class="map-caption">실제 지도 · 실시간 AMCL 위치</p>
                </article>

                <aside class="robot-panel">
                    <div class="robots-heading"><h2>로봇 상태 <span>03</span></h2><span class="tag">ROS 수신</span></div>
                    <div v-if="loading && !robots.length" class="empty">상태를 불러오는 중입니다.</div>
                    <article v-for="robot in robots" :key="robot.id" :class="['robot-card', displayState(robot)]" :style="{ '--robot': colors[robot.id] }">
                        <div class="robot-top">
                            <h3><i></i>{{ robot.id }}<small>Domain {{ robot.ros_domain_id }}</small></h3>
                            <span class="state-pill">{{ labels[displayState(robot)] }}</span>
                        </div>
                        <div class="robot-meta">
                            <span :class="{ warning: robot.connection_state !== 'online' }">{{ labels[robot.connection_state] }}</span>
                            <time>마지막 수신 {{ formatTime(robot.received_at) }}</time>
                        </div>
                        <div class="position" v-if="robot.pose">
                            X <b>{{ fixed(robot.pose.x_m) }}</b><span>Y <b>{{ fixed(robot.pose.y_m) }}</b></span><span>θ <b>{{ fixed(robot.pose.yaw_rad) }}</b></span>
                        </div>
                        <div v-else class="position">현재 위치 미수신</div>
                        <div class="goal-position" v-if="robot.goal_pose">목표 X {{ fixed(robot.goal_pose.x_m) }} · Y {{ fixed(robot.goal_pose.y_m) }} · θ {{ fixed(robot.goal_pose.yaw_rad) }}</div>
                        <div class="goal-position missing" v-else>목표 좌표 미설정</div>
                        <div v-if="lastResult(robot.id)?.result" class="arrival-result">
                            최근 결과 {{ labels[lastResult(robot.id).state] || lastResult(robot.id).state }}
                            <span v-if="lastResult(robot.id).result.position_error_m !== undefined">위치 오차 {{ fixed(lastResult(robot.id).result.position_error_m) }}m</span>
                            <time>{{ formatTime(lastResult(robot.id).result.received_at) }}</time>
                        </div>
                        <button class="robot-start" :disabled="!canStart(robot)" :title="startReason(robot)" @click="startRobot(robot)">
                            {{ busy && pending?.path.includes(robot.id) ? '요청 중…' : '주행 시작' }}
                        </button>
                        <small v-if="startReason(robot)" class="start-reason">{{ startReason(robot) }}</small>
                    </article>
                </aside>
            </section>

            <section class="panel control-panel">
                <div class="control-description"><p class="eyebrow">MISSION STATUS</p><h2>{{ summary }}</h2><p>{{ active ? '작업 ' + active.id.slice(0, 8) + ' · ' + duration(active) : '한 번에 한 대씩 고정 목표로 이동합니다.' }}</p></div>
                <div class="action-buttons"><button class="stop" :disabled="!active || busy || !!pending" @click="stopActive">현재 로봇 정지 요청</button></div>
                <div v-if="pending && !busy" class="pending" role="status">이전 요청 결과 확인이 필요합니다. <button @click="sendPending">같은 요청 재확인</button></div>
                <p v-if="commandError" class="command-error" role="alert">{{ commandError }}</p>
                <p class="control-footnote">도착은 Nav2의 성공 결과와 실제 위치를 모두 수신한 뒤 확정됩니다. 정지 요청은 물리 비상정지 장치가 아닙니다.</p>
            </section>

            <section class="bottom-grid">
                <article class="panel history-panel">
                    <div class="panel-title"><div><p class="eyebrow">RECENT RUNS</p><h2>주행 기록</h2></div><button class="text-button" @click="selectRun(null)">현재 작업 보기</button></div>
                    <div v-if="!history.length" class="empty">아직 실제 주행 기록이 없습니다.</div>
                    <button v-for="run in history" :key="run.id" class="history-row" :class="{ selected: selected === run.id }" @click="selectRun(run)">
                        <span class="run-id">{{ run.robots?.[0]?.robot_id || '—' }}<small>{{ formatTime(run.created_at) }}</small></span>
                        <span>{{ labels[run.status] }}</span><span>{{ duration(run) }} ›</span>
                    </button>
                </article>
                <article class="panel events-panel">
                    <div class="panel-title"><div><p class="eyebrow">ACTIVITY LOG</p><h2>주행 이벤트</h2></div><span class="tag">{{ shownRun?.id.slice(0, 8) || '대기' }}</span></div>
                    <div v-if="!events.length" class="empty">주행을 시작하면 실제 상태 변화가 기록됩니다.</div>
                    <div class="event-list"><div v-for="event in [...events].reverse()" :key="event.id" class="event-row"><time>{{ formatTime(event.occurred_at) }}</time><span class="event-dot" :style="{ background: colors[event.robot_id] || '#70838a' }"></span><div><b v-if="event.robot_id">{{ event.robot_id }} · </b>{{ event.message }}</div></div></div>
                    <div v-if="shownRun?.robots" class="results">
                        <span v-for="entry in shownRun.robots" :key="entry.robot_id">{{ entry.robot_id }} <b>{{ labels[entry.state] }}</b><small v-if="entry.result?.received_at">결과 수신 {{ formatTime(entry.result.received_at) }}</small></span>
                    </div>
                </article>
            </section>
        </main>
        <footer><span>PINKY FLEET CONTROL / REAL ROBOT</span><span>고정 목표 · 순차 주행</span></footer>
    </div>
</template>

<style scoped src="../css/fleet.css"></style>
