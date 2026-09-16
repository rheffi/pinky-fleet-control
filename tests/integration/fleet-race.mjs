import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const base = 'http://web';
async function client() {
    const response = await fetch(base);
    const html = await response.text();
    const token = html.match(/name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok(token, 'CSRF token exists');
    const cookies = new Map(response.headers.getSetCookie().map(value => {
        const pair = value.split(';')[0], index = pair.indexOf('=');
        return [pair.slice(0, index), pair.slice(index + 1)];
    }));
    return async (path, body) => {
        const response = await fetch(base + '/api/v1/' + path, {
            method: body ? 'POST' : 'GET',
            headers: { Accept: 'application/json', Cookie: [...cookies].map(([k,v]) => k + '=' + v).join('; '),
                ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token } : {}) },
            body: body ? JSON.stringify(body) : undefined,
        });
        for (const value of response.headers.getSetCookie()) {
            const pair = value.split(';')[0], index = pair.indexOf('=');
            cookies.set(pair.slice(0, index), pair.slice(index + 1));
        }
        return { status: response.status, data: await response.json() };
    };
}
const one = await client(), two = await client();
const assignments = ['eed0', '648d', '62b2'].map(robot_id => ({ robot_id, goal_id: robot_id + '-right' }));
const payload = () => ({ request_id: randomUUID(), map_id: 'sample-map', map_version: '1', assignments });
assert.equal((await one('snapshot')).data.active_run, null, 'no active run before race');
const requests = [payload(), payload()];
const raced = await Promise.all([one('runs', requests[0]), two('runs', requests[1])]);
assert.deepEqual(raced.map(r => r.status).sort(), [201,409]);
const winner = raced.findIndex(r => r.status === 201), run = raced[winner].data.run;
const request = winner === 0 ? one : two;
const replay = await request('runs', requests[winner]);
assert.equal(replay.status, 200);
assert.equal(replay.data.run.id, run.id);
assert.equal(replay.data.replayed, true);
const stop = await request('runs/' + run.id + '/stop', { request_id: randomUUID() });
assert.equal(stop.status, 202);
assert.equal(stop.data.run.status, 'stopping');
console.log(JSON.stringify({ check: 'mysql-http-concurrency-and-retry', statuses: raced.map(r => r.status), run_id: run.id, stop_status: stop.status, passed: true }));
