// Deterministic UI logic tests; synthetic values only, no database or browser session.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/auth-security.js', import.meta.url), 'utf8');
const fakeKey = 'SYNTHETIC-KEY-NOT-A-TOTP-SECRET';
const fakeCodes = Array.from({length: 8}, (_, i) => (i + 1).toString(16).padStart(20, '0'));
let checks = 0;

function harness({clipboard = 'ok', fallback = true, codes = fakeCodes} = {}) {
    const events = new Map();
    const state = {copied: null, selected: false, links: [], blobs: [], revoked: []};
    const status = {textContent: ''};
    const input = {value: fakeKey, focus() {}, select() {state.selected = true;}, setSelectionRange() {}};
    const button = (id) => ({addEventListener(type, handler) {events.set(`${id}:${type}`, handler);}, focus() {}});
    const elements = {'security-action-status': status, 'totp-key': input, 'copy-totp-key': button('copy'), 'download-recovery-codes': button('download')};
    const document = {
        getElementById(id) {return elements[id];},
        querySelectorAll() {return codes.map(textContent => ({textContent}));},
        execCommand(command) {assert.equal(command, 'copy'); return fallback;},
        createElement(name) {assert.equal(name, 'a'); return {click() {state.links.push({href: this.href, download: this.download});}, remove() {}};},
        body: {append() {}},
    };
    const navigator = clipboard === 'absent' ? {} : {clipboard: {async writeText(value) {
        if (clipboard === 'denied') throw new Error('Synthetic denied permission');
        state.copied = value;
    }}};
    const window = {isSecureContext: true, setTimeout() {return 1;}, clearTimeout() {}, addEventListener(type, handler) {events.set(type, handler);}};
    vm.runInNewContext(source, {document, navigator, window, Blob, URL: {
        createObjectURL(blob) {state.blobs.push(blob); return 'blob:local-synthetic-' + state.blobs.length;},
        revokeObjectURL(url) {state.revoked.push(url);},
    }});
    return {state, status, events};
}

{
    const h = harness(); await h.events.get('copy:click')();
    assert.equal(h.state.copied, fakeKey); assert.match(h.status.textContent, /Chave copiada/); checks++;
}
for (const clipboard of ['denied', 'absent']) {
    const h = harness({clipboard}); await h.events.get('copy:click')();
    assert.equal(h.state.selected, true); assert.match(h.status.textContent, /Chave copiada/); checks++;
}
{
    const h = harness({clipboard: 'denied', fallback: false}); await h.events.get('copy:click')();
    assert.equal(h.state.selected, true); assert.match(h.status.textContent, /use Copiar no menu/); checks++;
}
{
    const h = harness(); h.events.get('download:click')();
    assert.equal(h.state.links[0].download, 'brnmail-codigos-recuperacao.txt');
    const text = await h.state.blobs[0].text();
    assert.equal(fakeCodes.every(code => text.includes(code)), true);
    assert.match(text, /uma única vez/); assert.equal(text.includes(fakeKey), false); checks++;
    h.events.get('download:click')();
    assert.deepEqual(h.state.revoked, ['blob:local-synthetic-1']); checks++;
    h.events.get('pagehide')();
    assert.deepEqual(h.state.revoked, ['blob:local-synthetic-1', 'blob:local-synthetic-2']); checks++;
}
{
    const h = harness({codes: ['invalid']}); h.events.get('download:click')();
    assert.equal(h.state.blobs.length, 0); assert.match(h.status.textContent, /Não foi possível/); checks++;
}
console.log(JSON.stringify({mfa_ui_logic_checks: checks, passed: true, network_calls: 0}));
