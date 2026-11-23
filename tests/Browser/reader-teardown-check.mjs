// Reader teardown: open the reader and leave again before it has settled, many times, by link (wire:navigate)
// and by full page load. This used to log "newCount is not defined". Fails on JS errors.
// node tests/Browser/reader-teardown-check.mjs [base-url]
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

const log = [];
let failures = 0;
const check = (label, ok) => { log.push(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++; };
page.on('pageerror', (e) => { log.push(`pageerror on ${page.url()}: ${e.message}\n${(e.stack ?? '').split('\n').slice(1, 4).join('\n')}`); failures++; });
process.on('uncaughtException', async (e) => { console.log(log.join('\n')); console.log('ERROR', e.message.split('\n')[0]); await browser.close(); process.exit(1); });

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/reader/, { timeout: 45000, waitUntil: 'commit' });

const errorsBefore = failures;

// Full page loads, leaving after a short, growing delay.
for (let i = 0; i < 8; i++) {
    await page.goto(`${base}/reader`, { waitUntil: 'commit' });
    await page.waitForTimeout(50 + i * 60);
    await page.goto(`${base}/security`, { waitUntil: 'commit' });
    await page.waitForTimeout(150);
}

// Through the sidebar (wire:navigate), leaving while the new-stories check may still be running.
await page.goto(`${base}/security`);
await page.waitForLoadState('networkidle');
for (let i = 0; i < 8; i++) {
    await page.locator('a[href$="/reader"]').first().click();
    await page.waitForTimeout(50 + i * 60);
    await page.locator('a[href$="/security"]').first().click();
    await page.waitForTimeout(200);
}

// The reader still works afterwards: the new-stories check runs when the tab becomes visible.
await page.goto(`${base}/reader`);
await page.waitForLoadState('networkidle');
await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
await page.waitForTimeout(1500);
check('reader loads and its new-stories bar is set up', await page.evaluate(() => {
    const root = document.querySelector('[x-data="reader"]');
    return !!root && typeof window.Alpine.$data(root).newCount === 'number';
}));
await page.evaluate(() => { window.Alpine.$data(document.querySelector('[x-data="reader"]')).newCount = 3; });
await page.waitForTimeout(300);
check('new-stories bar shows the count', (await page.locator('[data-test=show-new]:visible', { hasText: '3 new stories' }).count()) === 1);
await page.locator('[data-test=show-new]').click();
await page.waitForTimeout(1000);
check('clicking it hides the bar', (await page.locator('[data-test=show-new]:visible').count()) === 0);
check('no JS errors while leaving the reader mid-load', failures === errorsBefore);

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
