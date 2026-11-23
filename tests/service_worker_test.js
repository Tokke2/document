'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const listeners = {};
let fetchCalls = 0;
const context = {
    self: { location: { origin: 'https://portal.example' }, clients: { claim: async () => {} }, skipWaiting: async () => {}, addEventListener: (name, callback) => { listeners[name] = callback; } },
    URL, Response,
    fetch: async () => { fetchCalls++; throw new Error('offline'); },
};
vm.runInNewContext(fs.readFileSync(require.resolve('../service-worker.js'), 'utf8'), context);
(async () => {
    let response;
    listeners.fetch({ request: { method: 'GET', mode: 'navigate', url: 'https://portal.example/index.php?secret=1' }, respondWith: value => { response = value; } });
    const offline = await response;
    assert.equal(offline.status, 503);
    assert.equal(offline.headers.get('cache-control'), 'no-store');
    assert.ok(!(await offline.text()).includes('secret=1'));
    let handled = false;
    listeners.fetch({ request: { method: 'POST', mode: 'navigate', url: 'https://portal.example/index.php' }, respondWith: () => { handled = true; } });
    listeners.fetch({ request: { method: 'GET', mode: 'navigate', url: 'https://other.example/' }, respondWith: () => { handled = true; } });
    assert.equal(handled, false);
    assert.equal(fetchCalls, 1);
    console.log('PASS: offline fallback never stores or echoes portal data.');
})().catch(error => { console.error(error); process.exitCode = 1; });
