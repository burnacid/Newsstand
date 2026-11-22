// Visits every main page (and reader modes) as the throwaway user and reports
// any JavaScript errors. node tests/Browser/page-errors.mjs [base-url]
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage();

let current = 'login';
const errors = [];
page.on('pageerror', (e) => errors.push(`${current}: ${e.message}`));
page.on('console', (m) => { if (m.type() === 'error') errors.push(`${current}: console: ${m.text()}`); });

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/(reader|dashboards)/, { timeout: 45000, waitUntil: 'commit' });

const visit = async (label, path, after) => {
    current = label;
    await page.goto(`${base}${path}`);
    await page.waitForLoadState('networkidle');
    if (after) await after();
    await page.waitForTimeout(500);
    console.log(`visited ${label}`);
};

await visit('welcome', '/');
await visit('teams', '/teams');
await visit('watchlist', '/watchlist');
await visit('alerts', '/settings/alerts');
await visit('reader list', '/reader?show=all', async () => {
    await page.keyboard.press('j');
    await page.waitForTimeout(800);
    await page.keyboard.press('?');
});
await visit('reader river', '/reader?show=all', async () => {
    await page.locator('[data-test=layout-river]').click();
    await page.waitForTimeout(800);
    await page.locator('[data-scroll-area]').evaluate((el) => el.scrollBy(0, 1500));
    await page.waitForTimeout(1200);
    await page.locator('[data-test=layout-list]').click();
});
await visit('reader top stories', '/reader?s=top', async () => {
    await page.locator('[data-entry-id]').first().click();
    await page.waitForTimeout(800);
});
await visit('vulnerabilities', '/security', async () => {
    await page.getByText('Exploited', { exact: true }).first().click();
    await page.waitForTimeout(800);
});
await visit('rules', '/rules', async () => {
    await page.locator('[data-test=new-rule]').click();
    await page.waitForTimeout(500);
    await page.locator('[data-flux-modal] input').first().fill('Browser check rule');
    await page.locator('[data-flux-modal] input[placeholder*="ransomware"]').fill('zero-day');
    await page.locator('[data-flux-modal] select[name=highlight]').selectOption('red');
    await page.locator('[data-test=save-rule]').click();
    await page.waitForTimeout(1500);
    const saved = await page.getByText('Browser check rule').count();
    console.log(`  rule saved through the UI: ${saved > 0}`);
    if (!saved) errors.push('rules: rule was not saved through the UI');
});
await visit('feeds', '/feeds');
await visit('settings profile', '/settings/profile');
await visit('settings appearance', '/settings/appearance');

console.log(errors.length ? `\nERRORS:\n${errors.join('\n')}` : '\nNo JavaScript errors.');
await browser.close();
process.exit(errors.length ? 1 : 0);
