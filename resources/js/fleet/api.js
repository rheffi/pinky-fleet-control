export async function api(path, body, signal) {
    const timeout = AbortSignal.timeout(6000);
    const response = await fetch('/api/v1/' + path, {
        method: body ? 'POST' : 'GET',
        credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', ...(body ? {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        } : {}) },
        body: body ? JSON.stringify(body) : undefined,
        signal: signal ? AbortSignal.any([timeout, signal]) : timeout,
    }).catch(() => {
        throw new Error('관제 서버 응답이 없거나 연결이 끊겼습니다.');
    });
    let result;
    try {
        result = await response.json();
    } catch {
        const error = new Error('서버 응답을 읽을 수 없습니다. 잠시 후 다시 확인해 주세요.');
        error.status = response.status >= 500 ? response.status : undefined;
        throw error;
    }
    if (!response.ok) {
        const error = new Error(result.error?.message || '요청을 처리하지 못했습니다.');
        error.status = response.status;
        error.fields = result.error?.fields;
        throw error;
    }
    return result;
}
