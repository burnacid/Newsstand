// Dashboards end to end: create from template, open a story, add a widget,
// enable the public link and view it signed out. Fails on JS errors.
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const context = await browser.newContext();
const page = await context.newPage();

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

// Create from the security template.
await page.goto(`${base}/dashboards`);
await page.locator('input[placeholder*="Security briefing"]').fill('Browser briefing');
await page.locator('[data-test=create-dashboard]').click();
await page.waitForURL(/\/dashboards\/\d+/, { timeout: 15000 });
await page.waitForLoadState('networkidle');
check('dashboard created with template widgets', (await page.locator('section[wire\\:sort\\:item]').count()) === 4);

// Unread only, then open a story: it stays visible during this visit and is hidden after a reload.
await page.locator('[data-test=unread-only]').click();
await page.waitForTimeout(1200);

const story = page.locator('section button[wire\\:click^="open("]').first();
const openedTitle = (await story.count()) ? (await story.locator('span.font-serif').innerText()).trim() : null;
if (openedTitle) {
    await story.click();
    await page.waitForTimeout(1200);
    check('story opens in a dialog', await page.locator('[data-flux-modal] .prose-article, dialog .prose-article').first().isVisible());
    await page.keyboard.press('Escape');
    await page.waitForTimeout(800);
    check('opened story stays visible while in unread mode', (await page.getByText(openedTitle, { exact: true }).count()) > 0);
    await page.reload();
    await page.waitForLoadState('networkidle');
    check('unread-only is remembered and hides the read story', (await page.getByText(openedTitle, { exact: true }).count()) === 0);
    await page.locator('[data-test=unread-toggle] button').first().click();
    await page.waitForTimeout(1000);
    check('switching back to all shows it again', (await page.getByText(openedTitle, { exact: true }).count()) > 0);
}

// Add a widget.
await page.locator('[data-test=toggle-editing]').click();
await page.waitForTimeout(500);
await page.locator('[data-test=add-widget]').click();
await page.waitForTimeout(500);
await page.locator('select[name=wType]').selectOption('top');
await page.waitForTimeout(500);
await page.locator('input[name=wTitle]').fill('Browser widget');
await page.locator('[data-test=save-widget]').click();
await page.waitForTimeout(1500);
check('widget added', (await page.getByText('Browser widget').count()) > 0);

// Public link.
await page.locator('[data-test=toggle-editing]').click();
await page.locator('[data-test=share]').click();
await page.waitForTimeout(500);
await page.locator('[data-test=public-link-toggle]').click();
await page.waitForTimeout(1500);
const url = await page.locator('input[readonly]').first().inputValue().catch(() => '');
check('public link created', /\/d\/[A-Za-z0-9]{40}$/.test(url));

if (url) {
    const anon = await browser.newContext();
    const pub = await anon.newPage();
    pub.on('pageerror', (e) => { log.push(`public pageerror: ${e.message}`); failures++; });
    const res = await pub.goto(url);
    check('public page loads signed out', res.status() === 200 && (await pub.getByText('Browser briefing').count()) > 0);
    await anon.close();
}

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
