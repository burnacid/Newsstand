// PWA: the manifest is linked and valid, the service worker installs and takes control, "Install app" appears in
// the user menu once the browser offers it, a new version shows the update notice, a link shared to /share lands on
// the feeds page filled in, and a page opened offline shows the offline page until the connection is back.
// Fails on JS errors.
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
process.on('uncaughtException', async (e) => { console.log(log.join('\n')); console.log('ERROR', e.message.split('\n')[0]); await browser.close(); process.exit(1); });

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

// Install app: hidden until the browser offers it (headless never does, so offer it by hand).
const menuButton = page.locator('[data-flux-sidebar] [data-flux-profile], [data-flux-sidebar] button:has([data-flux-avatar])').first();
await menuButton.click();
await page.waitForTimeout(400);
check('no "Install app" until the browser offers it', !(await page.locator('[data-test=install-app]').first().isVisible()));
await page.keyboard.press('Escape');
await page.evaluate(() => {
    const offer = new Event('beforeinstallprompt', { cancelable: true });
    offer.prompt = () => { window.__installPrompted = true; };
    offer.userChoice = Promise.resolve({ outcome: 'accepted' });
    window.dispatchEvent(offer);
});
await menuButton.click();
await page.waitForTimeout(400);
const installItem = page.locator('[data-test=install-app]:visible');
check('"Install app" shown once offered', (await installItem.count()) === 1);
await installItem.click();
await page.waitForTimeout(400);
check('"Install app" opens the browser prompt', await page.evaluate(() => window.__installPrompted === true));
check('"Install app" gone after the prompt', !(await page.locator('[data-test=install-app]').first().isVisible()));
await page.keyboard.press('Escape').catch(() => {});

// Update notice: a new worker taking over a controlled page.
check('no update notice by default', !(await page.locator('[data-test=pwa-update]').isVisible()));
await page.evaluate(() => navigator.serviceWorker.dispatchEvent(new Event('controllerchange')));
await page.waitForTimeout(500);
check('update notice shown when a new version takes over', await page.locator('[data-test=pwa-update]').isVisible());
await page.locator('[data-test=pwa-update] button[aria-label]').click();
await page.waitForTimeout(500);
check('"Later" hides the update notice', !(await page.locator('[data-test=pwa-update]').isVisible()));

// Share to Newsstand.
await page.goto(`${base}/share?title=Worth+following&text=${encodeURIComponent('Have a look: https://krebsonsecurity.com/')}`);
await page.waitForLoadState('networkidle');
check('shared link opens the feeds page with the address filled in',
    /\/feeds/.test(page.url()) && (await page.locator('[data-test=shared-url-note]').count()) === 1
    && (await page.locator('input[wire\\:model=url]').inputValue()) === 'https://krebsonsecurity.com/');

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
