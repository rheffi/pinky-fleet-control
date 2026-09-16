import test from 'node:test';
import assert from 'node:assert/strict';
import { api } from '../../resources/js/fleet/api.js';

test('a disconnected API gives a readable error', async t => {
    t.mock.method(globalThis, 'fetch', async () => { throw new TypeError('Failed to fetch'); });
    await assert.rejects(api('snapshot'), /관제 서버 응답이 없거나/);
});

test('a non-JSON gateway failure stays retryable', async t => {
    t.mock.method(globalThis, 'fetch', async () => new Response('<html>Bad gateway</html>', { status: 502 }));
    await assert.rejects(api('snapshot'), error => error.status === 502 && error.message.includes('서버 응답을 읽을 수 없습니다'));
});
