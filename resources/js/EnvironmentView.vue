<script setup>
import { computed, onMounted, ref } from 'vue';

const health = ref(null);
const loading = ref(false);
const error = ref('');
const checkedAt = computed(() => health.value?.checked_at
    ? new Date(health.value.checked_at).toLocaleString('ko-KR') : '아직 확인하지 않음');

async function refresh() {
    loading.value = true;
    error.value = '';
    health.value = null;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);

    try {
        const response = await fetch('/api/health', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal: controller.signal,
        });
        const result = await response.json();
        if (result.service !== 'pinky-fleet-control' || !['ok', 'degraded'].includes(result.status)) {
            throw new Error('Unexpected response');
        }
        if (!response.ok && response.status !== 503) throw new Error('Request failed');
        health.value = result;
        if (!response.ok) error.value = 'API에는 연결됐지만 데이터베이스 응답을 확인하지 못했습니다.';
    } catch {
        error.value = '서버 응답을 확인하지 못했습니다. 서비스를 확인한 뒤 다시 시도해 주세요.';
    } finally {
        clearTimeout(timeout);
        loading.value = false;
    }
}

onMounted(refresh);
</script>

<template>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="Pinky Fleet Control 홈"><span class="brand-mark">P</span>Pinky <strong>Fleet Control</strong></a>
            <span class="context-label"><span class="dot"></span>로컬 개발환경</span>
        </header>

        <main>
            <section class="intro">
                <p class="eyebrow">PROJECT SETUP · 01</p>
                <h1>연결부터, 하나씩.</h1>
                <p class="intro-copy">관제 개발을 시작하기 전에 웹 서버와 데이터베이스의 연결을 확인합니다.</p>
            </section>

            <section class="status-panel" aria-labelledby="status-heading" :aria-busy="loading">
                <div class="panel-heading">
                    <div><p class="eyebrow">ENVIRONMENT CHECK</p><h2 id="status-heading">개발환경 상태</h2></div>
                    <button type="button" :disabled="loading" @click="refresh">{{ loading ? '확인 중…' : '다시 확인' }}<span aria-hidden="true">↻</span></button>
                </div>

                <div class="status-grid" aria-live="polite">
                    <article class="status-card">
                        <span class="card-index">01 / APPLICATION</span><h3>관제 API</h3>
                        <p class="status-value" :class="{ success: health, failure: !loading && error && !health }">{{ loading ? '확인 중' : health ? '연결됨' : '확인 필요' }}</p>
                        <p class="card-description">Laravel 응답 상태</p>
                    </article>
                    <article class="status-card">
                        <span class="card-index">02 / DATABASE</span><h3>데이터베이스</h3>
                        <p class="status-value" :class="{ success: health?.database === 'connected', failure: health?.database === 'unavailable' }">{{ loading ? '확인 중' : health?.database === 'connected' ? '연결됨' : '확인 필요' }}</p>
                        <p class="card-description">MySQL 실제 쿼리 응답</p>
                    </article>
                    <article class="status-card neutral">
                        <span class="card-index">03 / ROBOTS</span><h3>로봇 연결</h3>
                        <p class="status-value muted">후속 단계</p>
                        <p class="card-description">현재 화면은 개발환경 확인용입니다.</p>
                    </article>
                </div>

                <p v-if="error" class="error-message" role="alert">{{ error }}</p>
                <div class="panel-footer"><span>마지막 확인</span><time>{{ checkedAt }}</time></div>
            </section>

            <section class="next-section">
                <span class="next-icon" aria-hidden="true">↗</span>
                <div><h2>다음은 관제의 기본 화면입니다.</h2><p>샘플 데이터로 로봇 목록과 작업 흐름을 만든 뒤, Ubuntu 관제 노트북에서 실제 로봇을 연결합니다.</p></div>
                <span class="step-label">NEXT STEP</span>
            </section>
        </main>
        <footer class="page-footer"><span>PINKY FLEET CONTROL</span><span>실물 주행 검증은 별도 단계에서 진행합니다.</span></footer>
    </div>
</template>
