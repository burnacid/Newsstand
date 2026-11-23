// Tag menu: create a tag from the reader's tag menu, toggle it on and off and close the menu with Escape right
// after each toggle (this used to throw "Invalid to show a popover during another show operation"), then delete
// the tag again on the Feeds page. Fails on JS errors. Needs at least one story in the throwaway user's reader.
// node tests/Browser/tag-menu-check.mjs [base-url]
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

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

await page.goto(`${base}/reader?show=all`);
await page.waitForLoadState('networkidle');
await page.locator('[data-entry-id]').first().click();
await page.waitForTimeout(1200);

const menu = () => page.locator('[data-flux-menu]:visible');
const item = () => menu().locator('[data-flux-menu-item]', { hasText: 'pw-escape-tag' });
const chip = () => page.locator('article a', { hasText: '#pw-escape-tag' });

// Create the tag from the menu (it is put on the story right away).
await page.locator('[data-tag-menu]').last().click();
await page.waitForTimeout(400);
await menu().locator('input').fill('pw-escape-tag');
await menu().locator('input').press('Enter');
await page.waitForTimeout(1200);
check('new tag put on the story', (await chip().count()) > 0);

// Toggle off, Escape straight away.
if (!(await menu().count())) {
    await page.locator('[data-tag-menu]').last().click();
    await page.waitForTimeout(400);
}
await item().click();
await page.keyboard.press('Escape');
await page.waitForTimeout(1200);
check('toggle off then Escape closes the menu', (await menu().count()) === 0);
check('tag removed from the story', (await chip().count()) === 0);

// Toggle on, Escape straight away.
await page.locator('[data-tag-menu]').last().click();
await page.waitForTimeout(400);
await item().click();
await page.keyboard.press('Escape');
await page.waitForTimeout(1200);
check('toggle on then Escape closes the menu', (await menu().count()) === 0);
check('tag back on the story', (await chip().count()) > 0);

// The menu still opens and works afterwards.
await page.locator('[data-tag-menu]').last().click();
await page.waitForTimeout(400);
check('menu opens again', (await item().count()) === 1);
await item().click();
await page.waitForTimeout(1200);
await page.keyboard.press('Escape');
await page.waitForTimeout(400);
check('Escape after the toggle has settled closes the menu', (await menu().count()) === 0);
check('Escape closed only the menu, the story stays open', (await page.locator('[data-tag-menu]').count()) > 0);
await page.locator('[data-tag-menu]').last().click();
await page.waitForTimeout(400);
check('menu opens again after Escape closed it', (await item().count()) === 1);
await page.keyboard.press('Escape');
await page.waitForTimeout(400);

// With the menu closed (focus back on the tag button), Escape closes the story as before.
await page.keyboard.press('Escape');
await page.waitForTimeout(1200);
check('Escape outside a menu closes the story', (await page.locator('[data-tag-menu]').count()) === 0);

// Clean up on the Feeds page.
await page.goto(`${base}/feeds`);
await page.waitForLoadState('networkidle');
const row = page.locator('.list-row', { hasText: 'pw-escape-tag' });
await row.hover();
await row.locator('.list-row-actions button').last().click();
await page.waitForTimeout(800);
check('tag deleted', (await row.count()) === 0);

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
