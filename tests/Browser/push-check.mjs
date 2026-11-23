// Push notifications: on Settings → Alerts turn push on for this browser, check it is listed as "This device",
// add a "Push notification" channel and send a test (it fails cleanly when the browser's push service can't be
// reached from a headless browser), turn push off again and delete the channel. Fails on JS errors.
// The server must be able to make push keys: on Windows, start it with OPENSSL_CONF set (see README).
// node tests/Browser/push-check.mjs [base-url]
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
await context.grantPermissions(['notifications'], { origin: base });
const page = await context.newPage();

const log = [];
let failures = 0;
const check = (label, ok) => { log.push(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++; };
page.on('pageerror', (e) => { log.push(`pageerror on ${page.url()}: ${e.message}`); failures++; });
page.on('dialog', (d) => d.accept());
process.on('uncaughtException', async (e) => { console.log(log.join('\n')); console.log('ERROR', e.message.split('\n')[0]); await browser.close(); process.exit(1); });

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/reader/, { timeout: 45000, waitUntil: 'commit' });

await page.goto(`${base}/settings/alerts`);
await page.waitForLoadState('networkidle');
await page.evaluate(() => navigator.serviceWorker.ready);

const card = page.locator('[data-test=push-devices]');
check('push card shown', (await card.count()) === 1);
check('server can send push', (await page.locator('[data-test=push-unavailable]').count()) === 0);
check('"Turn on" offered', await page.locator('[data-test=push-enable]').isVisible());

// Turn it on. Headless browsers usually have no push service; the card then shows the browser's error, and the
// rest runs against a stand-in subscription (real P-256 keys, a made-up endpoint that is never sent to).
const turnOn = async () => {
    await page.locator('[data-test=push-enable]').click();
    await page.waitForFunction(() => !window.Alpine.$data(document.querySelector('[data-test=push-devices]')).busy, null, { timeout: 30000 });
    await page.waitForTimeout(800);
    return card.evaluate((el) => ({ enabled: window.Alpine.$data(el).enabled, error: window.Alpine.$data(el).error }));
};
let state = await turnOn();
let stubbed = false;

if (!state.enabled) {
    log.push(`info  this browser could not subscribe (${state.error}); using a stand-in subscription`);
    check('subscribe error shown on the card', state.error !== '' && (await card.locator('.text-red-600').isVisible()));
    await page.evaluate(async () => {
        const pair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
        const b64 = (bytes) => btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        const keys = { p256dh: b64(new Uint8Array(await crypto.subtle.exportKey('raw', pair.publicKey))), auth: b64(crypto.getRandomValues(new Uint8Array(16))) };
        let current = null;
        const subscription = { endpoint: 'https://8.8.8.8/push/pw-check', options: {}, toJSON: () => ({ endpoint: subscription.endpoint, keys }), unsubscribe: async () => { current = null; return true; } };
        PushManager.prototype.subscribe = async () => (current = subscription);
        PushManager.prototype.getSubscription = async () => current;
    });
    stubbed = true;
    state = await turnOn();
}

check('turned on', state.enabled);

if (state.enabled) {
    check('turned on: this device is listed', (await card.locator('[data-test=push-device]', { hasText: 'This device' }).count()) === 1);
    check('"Turn off" offered', await page.locator('[data-test=push-disable]').isVisible());

    // A push channel and a test send.
    await page.locator('[data-test=add-channel]').click();
    await page.waitForTimeout(500);
    check('new channels default to push', (await page.locator('[data-test=push-channel-note]').count()) === 1);
    await page.locator('[data-test=save-channel]').click();
    await page.waitForTimeout(1200);
    const channel = page.locator('[wire\\:key^=channel-]', { hasText: 'Push notification' });
    check('push channel saved, with one device', (await channel.count()) === 1 && (await channel.innerText()).includes('1 device'));
    if (!stubbed) {
        await channel.locator('[data-test=test-channel]').click();
        await page.waitForTimeout(6000);
        const error = await channel.locator('[data-test=channel-error]').count() ? await channel.locator('[data-test=channel-error]').innerText() : '';
        log.push(`info  test send: ${error ? `failed: ${error}` : 'sent'}`);
    }

    // Clean up: turn off, delete the channel.
    await page.locator('[data-test=push-disable]').click();
    await page.waitForTimeout(1500);
    check('turned off: device removed', (await card.locator('[data-test=push-device]').count()) === 0);
    await channel.locator('button:has([data-flux-icon][data-slot]), button').last().click();
    await page.waitForTimeout(1200);
    check('channel deleted', (await page.locator('[wire\\:key^=channel-]', { hasText: 'Push notification' }).count()) === 0);
}

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
