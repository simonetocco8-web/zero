import { test } from 'node:test';
import assert from 'node:assert/strict';
import { requestQuotes, validQuotes, exchangeShipping, messages } from '../../resources/js/shipping.js';

const valid = { quotes: [{ id: 'quote', rate: { final_cents: 1234, carrier: 'Corriere', service: 'Standard' } }] };
async function request(fetcher, timeout) {
    globalThis.fetch = fetcher;
    return requestQuotes('/quote', 'csrf', new FormData(), new AbortController(), validQuotes, timeout);
}
test('success preserves exact cents and makes one request', async () => {
    let calls = 0;
    assert.deepEqual(await request(async () => { calls++; return Response.json(valid); }), valid);
    assert.equal(calls, 1);
});
for (const [name, fetcher, code] of [
    ['Failed to fetch', async () => { throw new TypeError('Failed to fetch'); }, 'SHIPPING_NETWORK'],
    ['HTML', async () => new Response('<html>Login</html>'), 'SHIPPING_INVALID_RESPONSE'],
    ['invalid JSON', async () => new Response('{'), 'SHIPPING_INVALID_RESPONSE'],
    ['invalid schema', async () => Response.json({quotes: null}), 'SHIPPING_INVALID_RESPONSE'],
    ['expired session HTML', async () => new Response('login', {status: 401}), 'SHIPPING_SESSION_EXPIRED'],
    ['CSRF HTML', async () => new Response('expired', {status: 419}), 'SHIPPING_SESSION_EXPIRED'],
    ['redirect/CORS/TLS blocked', async () => { throw new TypeError('fetch failed'); }, 'SHIPPING_NETWORK'],
    ['rate limit HTML', async () => new Response('slow', {status: 429}), 'SHIPPING_RATE_LIMITED'],
    ['raw provider error', async () => Response.json({message: 'secret'}, {status: 500}), 'SHIPPING_UNEXPECTED'],
]) test(name, async () => {
    await assert.rejects(request(fetcher), e => e.code === code && e.message === messages[code] && !e.message.includes('fetch'));
});
test('timeout settles and is actionable', async () => {
    await assert.rejects(request((_url, {signal}) => new Promise((_resolve, reject) => signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))), 5), e => e.code === 'SHIPPING_TIMEOUT');
});
test('structured fields, retry and support reference survive', async () => {
    const error = {code: 'SHIPPING_INVALID_POSTAL_CODE', message: 'Verifica il CAP.', fields: ['postal_code'], retryable: false, reference: 'SHP-test'};
    await assert.rejects(request(async () => Response.json({success: false, error}, {status: 422})), e => JSON.stringify(e) === JSON.stringify(error));
});
test('exchange suppresses duplicate clicks and ignores stale responses after edits', async () => {
    const state = exchangeShipping({endpoint: '/quote', csrf: 'csrf'});
    state.$refs = {exchangeForm: {}};
    const NativeFormData = globalThis.FormData;
    globalThis.FormData = class {};
    let complete, calls = 0;
    globalThis.fetch = () => { calls++; return new Promise(resolve => {complete = resolve;}); };
    try {
        const first = state.quoteShipping();
        await state.quoteShipping();
        assert.equal(calls, 1);
        state.resetShipping();
        assert.equal(state.busy, false);
        complete(Response.json({sides: {a: valid.quotes, b: []}}));
        await first;
        assert.deepEqual(state.sides, {a: [], b: []});
        assert.equal(state.selected.a, '');
        assert.equal(state.error, '');
        globalThis.fetch = async () => {throw new TypeError('Failed to fetch');};
        await state.quoteShipping();
        assert.equal(state.busy, false);
        assert.equal(state.retryable, true);
        assert.equal(state.error, messages.SHIPPING_NETWORK);
    } finally {globalThis.FormData = NativeFormData;}
});
