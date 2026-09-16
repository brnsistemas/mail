import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {test} from 'node:test';

const source = fs.readFileSync(new URL('../../public/invite.js', import.meta.url), 'utf8');
const syntheticToken = 'a'.repeat(64);
async function run({fragment = syntheticToken, response = 204, reject = false} = {}) {
    const state = {requests: [], removed: false, reloaded: false};
    const status = {textContent: 'Abra o link completo'};
    const page = {dataset: {inviteOpen: '/invite/synthetic-id/open'}, querySelector: () => null};
    const document = {querySelector: selector => selector === '[data-invite-open]' ? page : {content: 'synthetic-csrf'}, getElementById: () => status};
    const window = {location: {hash: fragment ? '#'+fragment : '', pathname: '/invite/synthetic-id', reload() {state.reloaded = true;}},
        history: {replaceState(a, b, url) {assert.equal(url, '/invite/synthetic-id'); state.removed = true;}}};
    const fetch = async (url, options) => {state.requests.push({url, options}); if (reject) throw new Error('QA unavailable'); return {status: response};};
    vm.runInNewContext(source, {document, window, fetch, URLSearchParams});
    await new Promise(resolve => setImmediate(resolve));
    return {state, status};
}
test('valid link is exchanged over POST before removing the private fragment', async () => {
    const {state} = await run();
    assert.equal(state.requests.length, 1);
    assert.equal(state.requests[0].url.includes(syntheticToken), false);
    assert.equal(state.requests[0].options.method, 'POST');
    assert.equal(state.requests[0].options.body.get('token'), syntheticToken);
    assert.equal(state.requests[0].options.headers['X-CSRF-TOKEN'], 'synthetic-csrf');
    assert.equal(state.removed, true); assert.equal(state.reloaded, true);
});
test('reload without fragment leaves the server-rendered verified form intact', async () => {
    const {state} = await run({fragment: ''});
    assert.equal(state.requests.length, 0); assert.equal(state.reloaded, false);
});
for (const response of [410, 419, 429, 500]) {
    test('failed exchange '+response+' keeps the link recoverable', async () => {
        const {state, status} = await run({response});
        assert.equal(state.removed, false); assert.equal(state.reloaded, false);
        assert.match(status.textContent, /convite/);
    });
}
test('network failure keeps the private fragment for an explicit retry', async () => {
    const {state, status} = await run({reject: true});
    assert.equal(state.removed, false); assert.match(status.textContent, /conexão/);
});
test('malformed fragment never reaches the server', async () => {
    const {state, status} = await run({fragment: 'invalid'});
    assert.equal(state.requests.length, 0); assert.match(status.textContent, /incompleto/);
});
