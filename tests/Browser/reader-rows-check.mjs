// Reader rows (one component per story), keyboard focus, sort order, mark-read menu and saved searches.
// node tests/Browser/reader-rows-check.mjs [base-url]
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage();
const errors = [];
let failed = 0;
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
const check = (label, ok) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failed++; };

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/(reader|dashboards)/, { timeout: 45000, waitUntil: 'commit' });
await page.goto(`${base}/reader?show=all`);
await page.waitForLoadState('networkidle');

const selected = () => page.evaluate(() => document.querySelector('[data-entry-id][aria-current=true]')?.dataset.entryId ?? null);
const focusedRow = () => page.evaluate(() => document.activeElement?.closest('[data-entry-id]')?.dataset.entryId ?? null);
const ids = () => page.evaluate(() => [...document.querySelectorAll('[data-entry-id]')].map((el) => el.dataset.entryId));

const before = await ids();
check('rows are listed', before.length > 1);

// Keyboard: j selects and focuses; Escape closes and keeps focus on the row; Enter reopens it.
await page.keyboard.press('j');
await page.waitForTimeout(1000);
await page.keyboard.press('j');
await page.waitForTimeout(1000);
const second = await selected();
check('j moves the selection and focus', second === before[1] && (await focusedRow()) === second);
check('rows stay in place after selecting', JSON.stringify(await ids()) === JSON.stringify(before));
await page.keyboard.press('Escape');
await page.waitForTimeout(800);
check('Escape closes the story and keeps focus on its row', (await selected()) === null && (await focusedRow()) === second);
await page.keyboard.press('Enter');
await page.waitForTimeout(1000);
check('Enter on the focused row opens it', (await selected()) === second);

// Clicking another row.
await page.locator('[data-entry-id]').nth(2).click();
await page.waitForTimeout(1000);
check('clicking a row opens it', (await selected()) === before[2]);

// Star from the pane updates the row's star icon.
await page.locator('[data-test=toggle-star]').click();
await page.waitForTimeout(1000);
const starred = await page.locator(`[data-entry-id="${before[2]}"] [data-flux-icon]`).count();
await page.locator('[data-test=toggle-star]').click();
await page.waitForTimeout(1000);
const unstarred = await page.locator(`[data-entry-id="${before[2]}"] [data-flux-icon]`).count();
check('starring from the pane updates the row', starred === unstarred + 1);

// Sort order.
await page.locator('[data-test=sort-toggle]').click();
await page.waitForTimeout(1200);
const reversed = await ids();
check('oldest first reverses the list', reversed[0] !== before[0]);
await page.locator('[data-test=sort-toggle]').click();
await page.waitForTimeout(1200);
check('newest first again', (await ids())[0] === before[0]);

// Mark-read menu opens and closes without errors.
await page.locator('[data-test=mark-read-menu]').click();
await page.waitForTimeout(400);
check('mark-read menu offers older-than options', await page.locator('[data-test=mark-older-week]').isVisible());
await page.keyboard.press('Escape');
await page.waitForTimeout(400);

// Saved search: save, appears in the sidebar, remove.
const word = (await page.locator('[data-entry-id] button').first().innerText()).split(/\s+/).find((w) => w.length > 4) ?? 'the';
await page.locator('[data-scroll-area]').evaluate((el) => el.scrollTo(0, 0));
await page.locator('input[placeholder="Search"]').fill(word);
await page.waitForTimeout(1500);
await page.locator('[data-test=save-search]').click();
await page.waitForTimeout(1500);
check('saved search shows in the sidebar', await page.locator('[data-test=nav-saved-search]', { hasText: word }).first().isVisible());
await page.locator('[data-test=save-search]').click();
await page.waitForTimeout(1500);
check('removed saved search leaves the sidebar', (await page.locator('[data-test=nav-saved-search]', { hasText: word }).count()) === 0);
await page.locator('input[placeholder="Search"]').fill('');
await page.waitForTimeout(1200);

// River: tag menu toggles a tag and stays open; star from a river row.
await page.locator('[data-test=layout-river]').click();
await page.waitForTimeout(1500);
const riverId = await page.locator('[data-river-id]').first().getAttribute('data-river-id');
const river = page.locator(`[data-river-id="${riverId}"]`);
await river.locator('[data-tag-menu]').click();
await page.waitForTimeout(500);
const newTag = `pw-row-${Date.now() % 100000}`;
const tagInput = page.locator('[data-flux-menu]:popover-open input').first();
await tagInput.fill(newTag);
await tagInput.press('Enter');
await page.waitForTimeout(1500);
check('new tag shows on the river story', await river.getByText(`#${newTag}`).isVisible());
const menuItem = page.locator('[data-flux-menu]:popover-open [data-flux-menu-item]', { hasText: newTag });
await menuItem.click();
await page.waitForTimeout(1500);
check('tag menu stays open after toggling', await page.locator('[data-flux-menu]:popover-open').count() > 0);
check('toggled tag leaves the river story', (await river.getByText(`#${newTag}`).count()) === 0);
await page.keyboard.press('Escape');
await page.waitForTimeout(500);

await river.locator('button:has([data-flux-icon])').nth(1).click(); // star
await page.waitForTimeout(1500);
check('river row still present after starring', (await page.locator(`[data-river-id="${riverId}"]`).count()) === 1);
await page.locator(`[data-river-id="${riverId}"] button:has([data-flux-icon])`).nth(1).click(); // unstar
await page.waitForTimeout(1500);

await page.locator(`[data-river-id="${riverId}"]`).getByRole('button', { name: 'Continue reading' }).click().catch(() => {});
await page.waitForTimeout(1500);
check('continue reading expands the story', (await page.locator(`[data-river-id="${riverId}"]`).getByRole('button', { name: 'Show less' }).count()) === 1);

await page.locator('[data-test=layout-list]').click();
await page.waitForTimeout(1000);

// Clean up the tag created above.
await page.goto(`${base}/feeds`);
await page.waitForLoadState('networkidle');
const row = page.locator('.list-row', { hasText: newTag });
if (await row.count()) {
    await row.hover();
    page.once('dialog', (d) => d.accept());
    await row.locator('.list-row-actions button').last().click();
    await page.waitForTimeout(1200);
}

check('no JavaScript errors', errors.length === 0);
if (errors.length) console.log(errors.join('\n'));
await browser.close();
process.exit(failed ? 1 : 0);
