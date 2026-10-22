import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const base = new URL(process.argv[2] ?? 'http://127.0.0.1:8080');

async function get(path, type) {
    const url = new URL(path, base);
    assert.equal(url.origin, base.origin, 'Todos los recursos deben usar el mismo origen');
    const response = await fetch(url, { signal: AbortSignal.timeout(10000), redirect: 'error' });
    assert.equal(response.status, 200, url.pathname);
    assert.ok(response.headers.get('content-type')?.startsWith(type), `${url.pathname}: ${type}`);
    console.log(`200 ${url.pathname} (${type})`);
    return Buffer.from(await response.arrayBuffer());
}

await get('/up', 'text/html');
const html = (await get('/docs', 'text/html')).toString();
const document = await get('/docs/openapi.json', 'application/json');
assert.deepEqual(document, await readFile(new URL('../docs/openapi/openapi.json', import.meta.url)));
const assets = [...html.matchAll(/(?:src|href)="([^"]+)"/g)].map(match => match[1].replaceAll('&amp;', '&'));
assert.equal(assets.length, 4);
for (const asset of assets) {
    const pathname = new URL(asset, base).pathname;
    const type = pathname.endsWith('.js') ? 'application/javascript' : pathname.endsWith('.png') ? 'image/png' : 'text/css';
    const actual = await get(asset, type);
    const name = pathname.split('/').at(-1);
    const expected = await readFile(new URL(`../vendor/swagger-api/swagger-ui/dist/${name}`, import.meta.url));
    assert.deepEqual(actual, expected);
}
console.log('Documento servido idéntico al versionado; assets idénticos al paquete instalado.');
