// Admin panel end to end (the throwaway user must be an admin:
//   php artisan newsstand:admin playwright@example.test).
// Creates a user, disables it, flips registration and back, loads Health. Fails on JS errors.
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage();

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

check('admin link in sidebar', (await page.locator('[data-test=admin-link]').count()) === 1);

// Users: create, then disable.
const email = `pw-${Date.now()}@example.test`;
await page.goto(`${base}/admin/users`);
await page.locator('input[name=newEmail]').fill(email);
await page.locator('[data-test=create-user]').click();
await page.waitForTimeout(1500);
check('user created, password shown once', (await page.locator('[data-test=created-password]').count()) === 1);

await page.locator('input[placeholder*="Search"]').fill(email);
await page.waitForTimeout(1200);
await page.locator('[data-test^=user-menu-]').first().click();
await page.getByText('Disable account').click();
await page.waitForTimeout(1200);
check('user disabled', (await page.getByText('Disabled', { exact: true }).count()) > 0);

// Settings: switch registration to disabled and back.
await page.goto(`${base}/admin/settings`);
await page.locator('[data-test=registration-disabled] input, [data-test=registration-disabled]').first().click();
await page.locator('[data-test=save-settings]').click();
await page.waitForTimeout(1200);
const anon = await browser.newContext();
const anonPage = await anon.newPage();
const reg = await anonPage.goto(`${base}/register`);
check('registration closed for visitors', reg.status() === 404);
await anon.close();
await page.getByText('use the server default').click();
await page.waitForTimeout(1000);

// Health.
await page.goto(`${base}/admin/health`);
check('health page shows its status cards', (await page.locator('[data-test=health-card]').count()) === 6);

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
