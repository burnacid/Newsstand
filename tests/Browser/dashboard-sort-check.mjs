// Dashboards in the sidebar and widget drag and drop: signed in, / opens the reader; create a team and
// a dashboard for it and check it is listed under the team; drag the first widget below the second and
// check the order sticks; make it the default and check the Dashboards link opens it; create a personal
// dashboard and check it is listed under Dashboards. Deletes everything again. Fails on JS errors.
// node tests/Browser/dashboard-sort-check.mjs [base-url]
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage();

const log = [];
let failures = 0;
const check = (label, ok) => { log.push(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++; };
page.on('pageerror', (e) => { log.push(`pageerror on ${page.url()}: ${e.message}`); failures++; });
page.on('dialog', (d) => d.accept());

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/(reader|dashboards)/, { timeout: 45000, waitUntil: 'commit' });

await page.goto(`${base}/`);
check('signed in, / opens the reader', /\/reader/.test(page.url()));

// A team to attach the dashboard to.
await page.goto(`${base}/teams`);
await page.locator('input[data-test=team-name], [data-test=team-name] input').first().fill('PW board team');
await page.locator('[data-test=create-team]').click();
await page.waitForURL(/\/teams\/\d+$/, { timeout: 10000 });
const teamUrl = page.url();

// Create a dashboard for the team from the security template.
await page.goto(`${base}/dashboards`);
await page.waitForLoadState('networkidle');
await page.locator('input[placeholder*="Security briefing"]').fill('PW team board');
await page.locator('select[data-test=dashboard-team], [data-test=dashboard-team] select').first().selectOption({ label: 'Team PW board team' });
await page.locator('[data-test=create-dashboard]').click();
await page.waitForURL(/\/dashboards\/\d+/, { timeout: 15000 });
await page.waitForLoadState('networkidle');

const teamGroup = page.locator('[data-test=nav-team]', { hasText: 'PW board team' });
check('team group shown in the sidebar', (await teamGroup.count()) === 1);
const navLink = teamGroup.locator(`a[href="${page.url()}"]`);
check('dashboard listed under its team', (await navLink.count()) === 1 && (await navLink.innerText()).includes('PW team board'));
check('dashboard marked current in the sidebar', (await navLink.getAttribute('data-current')) !== null);

// Drag and drop.
const titles = () => page.locator('section[wire\\:sort\\:item] h2').allInnerTexts();
const before = await titles();
check('no drag handles outside edit mode', (await page.locator('[wire\\:sort\\:handle]').count()) === 0);

await page.locator('[data-test=toggle-editing]').click();
await page.waitForTimeout(800);
check('drag handles shown in edit mode', (await page.locator('[wire\\:sort\\:handle]').count()) === before.length);

// Drag the first widget's handle onto the lower half of the second widget.
const h = await page.locator('[wire\\:sort\\:handle]').first().boundingBox();
const t = await page.locator('section[wire\\:sort\\:item]').nth(1).boundingBox();
await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
await page.mouse.down();
for (let i = 1; i <= 15; i++) {
    await page.mouse.move(h.x + ((t.x + t.width / 2) - h.x) * i / 15, h.y + ((t.y + t.height * 0.75) - h.y) * i / 15);
    await page.waitForTimeout(30);
}
await page.mouse.up();
await page.waitForTimeout(1500);

const afterDrag = await titles();
check(`order changed after drag (${before.slice(0, 2).join(' / ')} -> ${afterDrag.slice(0, 2).join(' / ')})`, afterDrag[0] === before[1] && afterDrag[1] === before[0]);

await page.reload();
await page.waitForLoadState('networkidle');
const afterDragUrl = page.url();
check('new order is saved', JSON.stringify(await titles()) === JSON.stringify(afterDrag));

// Team ownership is shown, and the team's admin manages it.
check('dashboard labelled as a team dashboard', (await page.locator('[data-test=dashboard-team-label]').innerText()).includes('PW board team'));

// Widget sizes: make the first widget full width from its size menu.
await page.locator('[data-test=toggle-editing]').click();
await page.waitForTimeout(800);
const first = page.locator('section[wire\\:sort\\:item]').first();
await first.locator('[data-test=widget-size]').click();
await page.locator('[data-test=widget-size-full]:visible').click();
await page.waitForTimeout(1200);
const grid = await page.locator('section[wire\\:sort\\:item]').first().evaluate((el) => ({
    width: el.getBoundingClientRect().width,
    gridWidth: el.parentElement.getBoundingClientRect().width,
}));
check(`full-width widget spans the row (${Math.round(grid.width)} of ${Math.round(grid.gridWidth)}px)`, grid.width > grid.gridWidth * 0.95);
await page.locator('[data-test=toggle-editing]').click();
await page.waitForTimeout(500);

// The overview lists it under Team dashboards.
await page.goto(`${base}/dashboards`);
await page.waitForLoadState('networkidle');
check('overview lists it under Team dashboards', (await page.locator('[data-test=team-dashboards]', { hasText: 'PW team board' }).count()) === 1);
await page.goto(afterDragUrl);
await page.waitForLoadState('networkidle');

// Default dashboard: the star sets it, the sidebar Dashboards link opens it.
const boardUrl = page.url();
await page.locator('[data-test=toggle-default]').click();
await page.waitForTimeout(1200);
check('star marks the default', (await page.locator('[data-test=toggle-default]').getAttribute('aria-pressed')) === 'true');
await page.goto(`${base}/reader`);
await page.waitForLoadState('networkidle');
await page.locator('a[href$="/dashboard"]').first().click();
await page.waitForURL(/\/dashboards\/\d+/, { timeout: 15000 });
check('Dashboards link opens the default dashboard', page.url() === boardUrl);

// A personal dashboard is listed under Dashboards.
await page.goto(`${base}/dashboards`);
await page.waitForLoadState('networkidle');
await page.locator('input[placeholder*="Security briefing"]').fill('PW personal board');
await page.locator('select[data-test=dashboard-team], [data-test=dashboard-team] select').first().selectOption({ label: 'Personal' });
await page.locator('[data-test=create-dashboard]').click();
await page.waitForURL(/\/dashboards\/\d+/, { timeout: 15000 });
await page.waitForLoadState('networkidle');
const personalUrl = page.url();
check('personal dashboard listed under Dashboards', (await page.locator(`[data-test=nav-dashboards] a[href="${personalUrl}"]`).count()) === 1);
await page.locator('[data-test=dashboard-menu]').click();
await page.getByText('Delete dashboard').click();
await page.waitForURL(/\/dashboards$/, { timeout: 15000 });

// Clean up: delete the team dashboard, then the team.
await page.goto(boardUrl);
await page.waitForLoadState('networkidle');
await page.locator('[data-test=dashboard-menu]').click();
await page.getByText('Delete dashboard').click();
await page.waitForURL(/\/dashboards$/, { timeout: 15000 });
check('dashboards deleted', (await page.getByText('PW team board').count()) === 0 && (await page.getByText('PW personal board').count()) === 0);

await page.goto(teamUrl);
await page.locator('[data-test=delete-team]').click();
await page.waitForURL(/\/teams$/, { timeout: 10000 });
check('team deleted', (await page.getByText('PW board team').count()) === 0);

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
