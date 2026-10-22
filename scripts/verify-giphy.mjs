// Explicit live-provider probe. Never logs credentials, API keys, or bearer tokens.
import assert from 'node:assert/strict';

const base = new URL(process.argv[2] ?? 'http://127.0.0.1:8080');
async function request(path, method = 'GET', body, token) {
    const response = await fetch(new URL(path, base), {
        method,
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
        signal: AbortSignal.timeout(15000),
    });
    console.log(`${method} ${path.split('?')[0]}: ${response.status}`);
    return { status: response.status, data: await response.json() };
}
const login = await request('/api/v1/login', 'POST', {
    email: process.env.PROBE_EMAIL ?? 'user@example.test',
    password: process.env.PROBE_PASSWORD ?? 'contraseña-local',
});
assert.equal(login.status, 200, 'login');
const token = login.data.access_token;
const search = await request('/api/v1/gifs?QUERY=cats&LIMIT=1', 'GET', undefined, token);
assert.equal(search.status, 200, 'GIPHY search');
assert.ok(search.data.data.length > 0, 'GIPHY returned no GIFs');
const id = search.data.data[0].id;
const gif = await request(`/api/v1/gifs/${encodeURIComponent(id)}`, 'GET', undefined, token);
assert.equal(gif.status, 200, 'GIPHY lookup');
assert.equal(gif.data.data.id, id);
const favorite = await request('/api/v1/favorites', 'POST', {
    GIF_ID: id, ALIAS: 'Phase 3 live validation', USER_ID: login.data.user.id,
}, token);
assert.equal(favorite.status, 201, 'favorite creation (use a fresh isolated database)');
console.log('Live GIPHY flow passed.');
