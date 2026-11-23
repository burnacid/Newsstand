// PWA: the manifest is linked and valid, the service worker installs and takes control, a page opened offline
// shows the offline page, and "Try again" loads the real page once the connection is back. Fails on JS errors.
// node tests/Browser/pwa-check.mjs [base-url]
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const context = await browser.newContext();
const page = await context.newPage();

const log = [];
let failures = 0;
const check = (label, ok) => { log.push(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++; };
page.on('pageerror', (e) => { log.push(`pageerror on ${page.url()}: ${e.message}`); failures++; });

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/(reader|dashboards)/, { timeout: 45000, waitUntil: 'commit' });
await page.goto(`${base}/reader`);
await page.waitForLoadState('networkidle');

// Manifest.
const manifestHref = await page.locator('link[rel=manifest]').getAttribute('href');
const manifest = await (await page.request.get(new URL(manifestHref, base).href)).json();
check('manifest linked and installable', manifest.display === 'standalone' && manifest.start_url === '/reader' && manifest.icons.length >= 2);

// Service worker.
const active = await page.evaluate(() => Promise.race([
    navigator.serviceWorker.ready.then((r) => r.active?.scriptURL ?? null),
    new Promise((resolve) => setTimeout(() => resolve(null), 10000)),
]));
check(`service worker active (${active})`, active?.endsWith('/sw.js'));
await page.reload();
await page.waitForLoadState('networkidle');
check('page controlled by the service worker', await page.evaluate(() => !!navigator.serviceWorker.controller));

// Offline.
await context.setOffline(true);
await page.goto(`${base}/security`).catch(() => {});
check('offline navigation shows the offline page', (await page.getByText("You're offline").count()) === 1);
check('offline page shows the mark from cache', await page.locator('main img:visible').evaluate((img) => img.complete && img.naturalWidth > 0));

// Back online the offline page reloads itself; "Try again" does the same by hand.
await context.setOffline(false);
await page.waitForTimeout(1500);
if (await page.locator('[data-test=offline-retry]').count()) {
    await page.locator('[data-test=offline-retry]').click();
}
await page.waitForLoadState('networkidle');
check('back online, the real page loads', /\/security/.test(page.url()) && (await page.getByText("You're offline").count()) === 0);

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
