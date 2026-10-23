const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const html = fs.readFileSync(require('node:path').join(__dirname, '..', 'live.html'), 'utf8');
const code = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const elements = new Map();
let response = { ok: true, status: 200, json: async () => ({}) };
const context = vm.createContext({
    console,
    Date,
    setInterval() {},
    localStorage: { getItem: () => null, setItem() {} },
    document: { getElementById(id) { if (!elements.has(id)) elements.set(id, { innerHTML: '', textContent: '', style: {} }); return elements.get(id); } },
    fetch: async url => url.startsWith('public/ipv6-status.json') ? response : { ok: true, text: async () => '' },
});
vm.runInContext(code, context);
(async () => {
    response = { ok: true, status: 200, json: async () => ({
        instance: { label: '<img src=x onerror=alert(1)>', requested: 32, current: 31, status: 'ready', updated_at: '<time>' },
        invalid: { label: 'Invalid target', requested: 33, current: '<script>' },
        unknown: { label: 'No observation yet', requested: 1, current: null, status: 'error' },
    }) };
    await vm.runInContext('loadIpv6Status()', context);
    const output = elements.get('ipv6-grid').innerHTML;
    assert.match(output, /Requested IPv6 objects.*32/);
    assert.match(output, /Counted object budget.*31/);
    assert.match(output, /&lt;img src=x onerror=alert\(1\)&gt;/);
    assert.match(output, /&lt;time&gt;/);
    assert.doesNotMatch(output, /<img|<script|Invalid target/);
    assert.match(output, /Unknown/);
    assert.match(output, /Needs local CLI retry or review/);
    response = { ok: false, status: 500 };
    await vm.runInContext('loadIpv6Status()', context);
    assert.match(elements.get('ipv6-grid').textContent, /status unavailable/);
    console.log('IPv6 dashboard fixtures passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
