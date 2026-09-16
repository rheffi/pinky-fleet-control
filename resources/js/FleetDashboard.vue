<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { api } from './fleet/api';
import { worldToPixel, pathPoints } from './fleet/map';

const bootstrap = ref(null), snapshot = ref(null), history = ref([]), events = ref([]);
const selected = ref(null), detail = ref(null), choices = ref({});
const loading = ref(true), busy = ref(false), networkError = ref(''), commandError = ref('');
const lastSuccess = ref(0), clock = ref(Date.now());
const controller = new AbortController();
let timer, clockTimer, alive = true, selectionVersion = 0;
const pendingKey = 'pinky-sample-pending-v1';
function readPending() {
    try {
        const value = JSON.parse(sessionStorage.getItem(pendingKey) || 'null');
        return value && typeof value.path === 'string' && value.body?.request_id ? value : null;
    } catch { return null; }
}
const pending = ref(readPending());
const colors = { eed0: '#087f8c', '648d': '#7056c7', '62b2': '#dd8732' };
const labels = { queued: '접수됨', running: '실행 중', completed: '도착 완료', stopping: '정지 확인 대기',
    cancelled: '정지 종료', interrupted: '중단 · 확인 필요', failed: '실패', pending: '출발 대기',
    idle: '준비', moving: '이동 중', waiting: '대기 중', arrived: '도착', stopped: '정지 확인',
    unknown: '미확인', error: '오류', online: '최신', stale: '수신 지연', offline: '연결 끊김' };
const map = computed(() => bootstrap.value?.map);
const robots = computed(() => snapshot.value?.robots || []);
const active = computed(() => snapshot.value?.active_run);
const shownRun = computed(() => selected.value ? detail.value : active.value);
const staleView = computed(() => !lastSuccess.value || clock.value - lastSuccess.value > 3500 || !!networkError.value);
const ready = computed(() => !loading.value && !busy.value && !pending.value && !staleView.value
    && !active.value && snapshot.value?.executor.connection_state === 'online'
    && robots.value.length === 3 && robots.value.every(r => r.connection_state === 'online' && r.pose)
    && robots.value.every(r => choices.value[r.id]));
const projected = computed(() => robots.value.map(r => ({ ...r, pixel: worldToPixel(r.pose, map.value) })));
const projectedGoals = computed(() => (bootstrap.value?.goals || []).map(g => ({ ...g, pixel: worldToPixel(g.pose, map.value) })));
const selectedGoals = computed(() => shownRun.value?.robots ? shownRun.value.robots.map(r => r.goal_id) : Object.values(choices.value));
const summary = computed(() => active.value ? labels[active.value.status] : '다음 작업을 준비하세요');
const formatTime = value => value ? new Date(value).toLocaleTimeString('ko-KR', { hour12: false }) : '—';
const duration = run => run?.started_at ? Math.max(0, Math.floor(((run.finished_at ? new Date(run.finished_at).getTime() : clock.value) - new Date(run.started_at).getTime()) / 1000)) + '초' : '—';

function setScenario(side) {
    choices.value = Object.fromEntries((bootstrap.value?.robots || []).map(r => [r.id, r.id + '-' + side]));
}
async function loadEvents(runId, version) {
    if (!runId) { events.value = []; return; }
    const cursor = events.value.at(-1)?.id || 0;
    const result = await api('runs/' + runId + '/events?after_id=' + cursor, null, controller.signal);
    if (version === selectionVersion && alive) events.value = [...events.value, ...result.events].slice(-200);
}
async function selectRun(run) {
    selected.value = run?.id || null;
    detail.value = run || null;
    events.value = [];
    selectionVersion++;
    // The single poll loop loads the selected detail and its events.
}
async function poll() {
    try {
        if (!bootstrap.value) {
            bootstrap.value = await api('bootstrap', null, controller.signal);
            setScenario('right');
        }
        const result = await api('snapshot', null, controller.signal);
        if (!alive) return;
        if (!snapshot.value || result.snapshot_revision >= snapshot.value.snapshot_revision) snapshot.value = result;
        if (active.value) {
            choices.value = Object.fromEntries(active.value.robots.map(r => [r.robot_id, r.goal_id]));
        }
        networkError.value = '';
        lastSuccess.value = Date.now();
        history.value = (await api('runs?limit=12', null, controller.signal)).runs;
        const version = selectionVersion;
        const target = selected.value || active.value?.id || history.value[0]?.id;
        if (!selected.value && !active.value && target) {
            selected.value = target;
        }
        if (selected.value) {
            const response = await api('runs/' + selected.value, null, controller.signal);
            if (version === selectionVersion) detail.value = response.run;
        }
        const nextEventRun = selected.value || active.value?.id;
        if (events.value.length && events.value[0].run_id !== nextEventRun) events.value = [];
        await loadEvents(nextEventRun, version);
    } catch (error) {
        if (alive) networkError.value = error.message || '관제 API 응답을 확인할 수 없습니다.';
    } finally {
        loading.value = false;
        if (alive) timer = setTimeout(poll, 1000);
    }
}
function savePending(value) {
    pending.value = value;
    try {
        if (value) sessionStorage.setItem(pendingKey, JSON.stringify(value));
        else sessionStorage.removeItem(pendingKey);
    } catch { /* The in-memory request ID still protects retries in this page. */ }
}
async function submit(type) {
    if (busy.value) return;
    if (!pending.value) {
        if (type === 'start' && !ready.value) return;
        if (type === 'stop' && !active.value) return;
        const body = type === 'start'
            ? { request_id: crypto.randomUUID(), map_id: map.value.id, map_version: map.value.version,
                assignments: robots.value.map(r => ({ robot_id: r.id, goal_id: choices.value[r.id] })) }
            : { request_id: crypto.randomUUID() };
        savePending({ path: type === 'start' ? 'runs' : 'runs/' + active.value.id + '/stop', body });
    }
    busy.value = true;
    commandError.value = '';
    try {
        const result = await api(pending.value.path, pending.value.body, controller.signal);
        savePending(null);
        await selectRun(result.run);
    } catch (error) {
        commandError.value = error.status ? error.message : '응답을 확인하지 못했습니다. 같은 요청 ID로 재확인해 주세요.';
        if (error.status && error.status < 500) savePending(null);
    } finally { busy.value = false; }
}
onMounted(() => {
    poll();
    clockTimer = setInterval(() => { clock.value = Date.now(); }, 1000);
});
onUnmounted(() => {
    alive = false; controller.abort(); clearTimeout(timer); clearInterval(clockTimer);
});
</script>

<template>
    <div class="fleet">
        <header class="fleet-header">
            <a href="/" class="fleet-brand"><span class="brand-icon">P</span><span>Pinky <strong>Fleet Control</strong><small>세 로봇, 하나의 관제</small></span></a>
            <div class="header-right"><span class="sample-badge">SAMPLE</span><a href="/environment">환경 확인 ↗</a></div>
        </header>
        <main>
            <section class="title-row">
                <div><p class="eyebrow">FLEET OVERVIEW</p><h1>로봇의 흐름을 한눈에.</h1><p class="subtitle">목표를 지정하고 이동부터 도착까지 확인하세요.</p></div>
                <div class="sync"><i :class="{ bad: staleView }"></i>{{ staleView ? '관제 갱신 확인 필요' : '관제 서버 연결됨' }}<small>마지막 수신 {{ formatTime(lastSuccess) }}</small></div>
            </section>
            <div class="sample-note"><span>샘플 모드</span> 실측 지도가 아닌 화면 개발용 시나리오입니다. 경로·대기는 미리 지정되어 있으며, 실물 주행과 CBS 검증은 후속 단계입니다.</div>
            <div v-if="networkError" class="alert" role="alert">관제 API 연결 오류 · {{ networkError }} 마지막 수신 정보를 표시합니다.</div>
            <div v-else-if="snapshot && snapshot.executor.connection_state !== 'online'" class="alert" role="alert">샘플 실행기 {{ labels[snapshot.executor.connection_state] }} · 새 작업을 시작할 수 없습니다. 정지 요청은 접수할 수 있습니다.</div>
            <div v-if="active?.status === 'interrupted'" class="alert" role="alert">{{ active.error?.message }} 자동 재개하지 않습니다.</div>
            <section class="workspace">
                <article class="panel map-panel">
                    <div class="panel-title"><div><p class="eyebrow">LIVE MAP</p><h2>샘플 주행 공간</h2></div><span class="tag">MAP v{{ map?.version || '—' }}</span></div>
                    <div class="map-wrap">
                        <svg v-if="map" :viewBox="'0 0 ' + map.width_px + ' ' + map.height_px" role="img" aria-label="샘플 지도, 로봇 세 대의 현재 위치와 계획 경로">
                            <image :href="map.image_url" :width="map.width_px" :height="map.height_px" />
                            <g v-for="entry in shownRun?.robots || []" :key="entry.robot_id">
                                <polyline :points="pathPoints(entry.planned_path, map)" fill="none" :stroke="colors[entry.robot_id]" stroke-width="3" stroke-dasharray="7 6" opacity=".55" />
                            </g>
                            <g v-for="goal in projectedGoals.filter(g => g.pixel)" :key="goal.id" :transform="'translate(' + goal.pixel.x + ',' + goal.pixel.y + ')'">
                                <circle r="15" :fill="selectedGoals.includes(goal.id) ? colors[goal.robot_id] : 'white'" :fill-opacity="selectedGoals.includes(goal.id) ? '.14' : '.9'" :stroke="colors[goal.robot_id]" stroke-width="2" stroke-dasharray="3 3"/>
                                <path d="M-4 0H4M0-4V4" :stroke="colors[goal.robot_id]" stroke-width="2"/>
                                <text y="31" text-anchor="middle" fill="#5b6e75" font-size="10">{{ goal.id.endsWith('left') ? '서쪽' : '동쪽' }}</text>
                            </g>
                            <g v-for="robot in projected.filter(r => r.pixel)" :key="robot.id" :transform="'translate(' + robot.pixel.x + ',' + robot.pixel.y + ')'">
                                <circle r="19" :fill="colors[robot.id]" fill-opacity=".15"/>
                                <circle r="12" :fill="colors[robot.id]" stroke="white" stroke-width="3"/>
                                <path d="M5 -4L11 0L5 4" stroke="white" fill="none" stroke-width="2" :transform="'rotate(' + robot.pixel.heading + ')'"/>
                                <rect x="-24" y="-39" width="48" height="18" rx="5" :fill="colors[robot.id]"/>
                                <text y="-26" text-anchor="middle" fill="white" font-size="11" font-weight="700">{{ robot.id }}</text>
                            </g>
                        </svg>
                        <div v-else class="empty">샘플 지도를 불러오는 중입니다.</div>
                    </div>
                    <div class="map-footer"><span><b class="legend-line"></b>지정된 계획 경로</span><span>＋ 고정 목표</span><span>좌표 m / 각도 rad</span></div>
                    <p class="map-caption" v-if="selected">선택한 작업의 계획 경로 · 로봇 아이콘은 마지막 수신 위치</p>
                    <p class="map-caption" v-else>실측 지도 아님 · SAMPLE FIXTURE · 충돌 회피 알고리즘 미연결</p>
                </article>
                <aside class="robot-panel">
                    <div class="robots-heading"><h2>로봇 상태 <span>03</span></h2><span class="tag">샘플 수신</span></div>
                    <div v-if="loading && !robots.length" class="empty">상태를 불러오는 중입니다.</div>
                    <article v-for="robot in robots" :key="robot.id" class="robot-card" :style="{ '--robot': colors[robot.id] }">
                        <div class="robot-top"><h3><i></i>{{ robot.id }}<small>Domain {{ robot.ros_domain_id }}</small></h3><span class="state-pill">{{ labels[robot.motion_state] }}</span></div>
                        <div class="robot-meta"><span :class="{ warning: robot.connection_state !== 'online' || staleView }">{{ labels[robot.connection_state] }}{{ staleView ? ' · 갱신 확인 필요' : '' }}</span><time>{{ formatTime(robot.received_at) }}</time></div>
                        <div class="position" v-if="robot.pose">X <b>{{ robot.pose.x_m.toFixed(2) }}</b><span>Y <b>{{ robot.pose.y_m.toFixed(2) }}</b></span><span>θ <b>{{ robot.pose.yaw_rad.toFixed(2) }}</b></span></div>
                        <div v-else class="position">위치 미확인</div>
                        <label :for="'goal-' + robot.id">고정 목표</label>
                        <select :id="'goal-' + robot.id" v-model="choices[robot.id]" :disabled="!!active || busy || !!pending">
                            <option v-for="goal in bootstrap.goals.filter(g => g.robot_id === robot.id)" :key="goal.id" :value="goal.id">{{ goal.label }}</option>
                        </select>
                    </article>
                </aside>
            </section>
            <section class="panel control-panel">
                <div class="control-description"><p class="eyebrow">MISSION CONTROL</p><h2>{{ summary }}</h2><p>{{ active ? '작업 ' + active.id.slice(0, 8) + ' · ' + duration(active) : '세 로봇을 모두 동쪽 또는 모두 서쪽 목표로 지정합니다.' }}</p></div>
                <div class="controls">
                    <div class="scenario-buttons"><span>목표 일괄 선택</span><button :disabled="!!active || busy || !!pending || !bootstrap" @click="setScenario('right')">동쪽 →</button><button :disabled="!!active || busy || !!pending || !bootstrap" @click="setScenario('left')">← 서쪽</button></div>
                    <div class="action-buttons"><button class="start" :disabled="!ready" @click="submit('start')">{{ busy ? '요청 중…' : '샘플 작업 시작' }} <span>↗</span></button><button class="stop" :disabled="!active || busy || !!pending" @click="submit('stop')">전체 정지 요청</button></div>
                </div>
                <div v-if="pending && !busy" class="pending" role="status">이전 요청의 결과 확인이 필요합니다. <button @click="submit('retry')">같은 요청 재확인</button></div>
                <p v-if="commandError" class="command-error" role="alert">{{ commandError }}</p>
                <p class="control-footnote">정지 요청 접수와 정지 확인은 구분됩니다. 이 버튼은 물리 비상정지 장치가 아닙니다.</p>
            </section>
            <section class="bottom-grid">
                <article class="panel history-panel">
                    <div class="panel-title"><div><p class="eyebrow">RECENT RUNS</p><h2>작업 기록</h2></div><button class="text-button" @click="selectRun(null)">현재 작업 보기</button></div>
                    <div v-if="!history.length" class="empty">아직 작업이 없습니다. 첫 샘플 주행을 시작해 보세요.</div>
                    <button v-for="run in history" :key="run.id" class="history-row" :class="{ selected: selected === run.id }" @click="selectRun(run)"><span class="run-id">{{ run.id.slice(0, 8) }}<small>{{ formatTime(run.created_at) }}</small></span><span>{{ labels[run.status] }}</span><span>{{ duration(run) }} ›</span></button>
                </article>
                <article class="panel events-panel">
                    <div class="panel-title"><div><p class="eyebrow">ACTIVITY LOG</p><h2>진행 이벤트</h2></div><span class="tag">{{ shownRun?.id.slice(0, 8) || '대기' }}</span></div>
                    <div v-if="!events.length" class="empty">작업을 실행하면 상태 변화가 기록됩니다.</div>
                    <div class="event-list"><div v-for="event in [...events].reverse()" :key="event.id" class="event-row"><time>{{ formatTime(event.occurred_at) }}</time><span class="event-dot" :style="{ background: colors[event.robot_id] || '#70838a' }"></span><div><b v-if="event.robot_id">{{ event.robot_id }} · </b>{{ event.message }}</div></div></div>
                    <div v-if="shownRun?.robots" class="results"><span v-for="entry in shownRun.robots" :key="entry.robot_id">{{ entry.robot_id }} <b>{{ labels[entry.state] }}</b></span></div>
                </article>
            </section>
        </main>
        <footer><span>PINKY FLEET CONTROL / SAMPLE WORKSPACE</span><span>실제 로봇 연결 · 알고리즘 통합 전</span></footer>
    </div>
</template>

<style scoped src="../css/fleet.css"></style>
