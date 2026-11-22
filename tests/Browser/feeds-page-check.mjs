// Browser smoke check for the Feeds page: delete tag, rename tag, unsubscribe.
// Runs against a running dev server as a throwaway user (see tests/Browser/README.md).
//
//   node tests/Browser/feeds-page-check.mjs [base-url] [screenshot.png]
//
// Uses the installed Microsoft Edge by default; set PW_CHANNEL=chromium to use
// Playwright's own Chromium (after `npx playwright install chromium`).
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage({ colorScheme: 'dark' });

const log = [];
let failures = 0;
const check = (label, ok) => { log.push(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++; };

page.on('pageerror', (e) => { log.push(`pageerror: ${e.message}`); failures++; });
page.on('dialog', (d) => d.accept());

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/(reader|dashboards)/, { timeout: 15000 });

await page.goto(`${base}/feeds`);
await page.waitForLoadState('networkidle');

const tagRow = (name) => page.locator('.list-row', { hasText: name });

// Delete a tag.
await tagRow('pw-delete-me').hover();
await tagRow('pw-delete-me').locator('.list-row-actions button').last().click();
await page.waitForTimeout(1500);
check('deleted tag disappears', (await tagRow('pw-delete-me').count()) === 0);

// Rename a tag.
await tagRow('pw-rename-me').hover();
await tagRow('pw-rename-me').locator('.list-row-actions button').first().click();
await tagRow('pw-rename-me').locator('input').fill('pw-renamed');
await tagRow('pw-rename-me').locator('input').press('Enter');
await page.waitForTimeout(1500);
check('renamed tag shows new name', (await tagRow('pw-renamed').count()) === 1);

// Unsubscribe via the row menu.
const rows = page.locator('tbody [data-flux-row]');
const before = await rows.count();
await rows.first().locator('button').first().click();
await page.locator('[data-flux-menu-item]:visible', { hasText: 'Unsubscribe' }).click();
await page.waitForTimeout(1500);
check('unsubscribed feed disappears', (await rows.count()) === before - 1);

if (process.argv[3]) await page.screenshot({ path: process.argv[3], fullPage: true });

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
