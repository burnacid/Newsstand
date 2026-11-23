// Team tags end to end: create a team and a team tag, put the tag on a story from the reader's
// tag menu, check the chip and the sidebar, show it in a tag widget on a team dashboard, then
// delete the team again (which removes the tag and the dashboard). Fails on JS errors.
// Needs at least one story in the throwaway user's reader.
// node tests/Browser/team-tags-check.mjs [base-url]
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

const log = [];
let failures = 0;
const check = (label, ok) => { log.push(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++; };
page.on('pageerror', (e) => { log.push(`pageerror on ${page.url()}: ${e.message}`); failures++; });
page.on('dialog', (d) => d.accept());

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/reader/, { timeout: 45000, waitUntil: 'commit' });

// Team and team tag.
await page.goto(`${base}/teams`);
await page.locator('input[data-test=team-name], [data-test=team-name] input').first().fill('PW tag team');
await page.locator('[data-test=create-team]').click();
await page.waitForURL(/\/teams\/\d+$/, { timeout: 10000 });
const teamUrl = page.url();
await page.locator('input[data-test=team-tag-name], [data-test=team-tag-name] input').first().fill('pw-team-tag');
await page.locator('[data-test=team-tag-add]').click();
await page.waitForTimeout(1200);
check('team tag created on the team page', (await page.locator('[data-test=team-tags]', { hasText: '#pw-team-tag' }).count()) === 1);

// Tag a story from the reader.
await page.goto(`${base}/reader?show=all`);
await page.waitForLoadState('networkidle');
await page.locator('[data-entry-id]').first().click();
await page.waitForTimeout(1200);
const title = (await page.locator('article h1, article h2').first().innerText().catch(() => '')).trim();
await page.locator('[data-tag-menu]').last().click();
await page.waitForTimeout(500);
check('tag menu groups team tags under the team', (await page.locator('[data-flux-menu]:visible', { hasText: 'PW tag team' }).count()) > 0);
await page.locator('[data-flux-menu-item]:visible', { hasText: 'pw-team-tag' }).click();
await page.waitForTimeout(1200);
check('team tag chip shown on the story', (await page.locator('a[href*="s=tag%3A"], a[href*="s=tag:"]', { hasText: '#pw-team-tag' }).count()) > 0);

const teamGroup = page.locator('[data-test=nav-team]', { hasText: 'PW tag team' });
check('team tag listed under the team in the sidebar', (await teamGroup.locator('[data-test=nav-team-tag]', { hasText: 'pw-team-tag' }).count()) === 1);

// Team dashboard with a tag widget.
await page.goto(`${base}/dashboards`);
await page.waitForLoadState('networkidle');
await page.locator('input[placeholder*="Security briefing"]').fill('PW tag board');
await page.locator('select[data-test=dashboard-team], [data-test=dashboard-team] select').first().selectOption({ label: 'Team PW tag team' });
await page.locator('[data-test=create-dashboard]').click();
await page.waitForURL(/\/dashboards\/\d+/, { timeout: 15000 });
await page.waitForLoadState('networkidle');
await page.locator('[data-test=toggle-editing]').click();
await page.waitForTimeout(500);
await page.locator('[data-test=add-widget]').click();
await page.waitForTimeout(500);
await page.locator('select[name=wType]').selectOption('tag');
await page.waitForTimeout(600);
await page.locator('select[name=wTagId]').selectOption({ label: '#pw-team-tag' });
await page.locator('input[name=wTitle]').fill('PW tag widget');
await page.locator('[data-test=save-widget]').click();
await page.waitForTimeout(1500);
const widget = page.locator('section', { hasText: 'PW tag widget' });
check('tag widget added to the team dashboard', (await widget.count()) === 1);
if (title) {
    check(`tag widget shows the tagged story (${title.slice(0, 40)}…)`, (await widget.getByText(title, { exact: false }).count()) > 0);
}

// Team rules: from the team page, add a rule that puts the team tag on stories.
await page.goto(teamUrl);
await page.locator('[data-test=team-rules-link]').click();
await page.waitForURL(/\/rules\?team=\d+/, { timeout: 15000 });
await page.waitForLoadState('networkidle');
check('team rules page explains team rules', (await page.locator('[data-test=team-rules-note]').count()) === 1);
await page.locator('[data-test=new-rule]').click();
await page.waitForTimeout(600);
await page.locator('input[name=name]').fill('PW team rule');
await page.locator('[data-test=rule-tag] select, select[data-test=rule-tag]').first().selectOption({ label: '#pw-team-tag' });
check('team rules offer no personal-only actions', (await page.getByText('Star it').count()) === 0);
await page.locator('input[name="conditions.0.value"]').fill('the');
await page.locator('[data-test=save-rule]').click();
await page.waitForTimeout(2000);
check('team rule saved', (await page.getByText('PW team rule').count()) > 0);

// Clean up: deleting the team removes its tags, dashboards and rules.
await page.goto(teamUrl);
await page.locator('[data-test=delete-team]').click();
await page.waitForURL(/\/teams$/, { timeout: 10000 });
await page.goto(`${base}/dashboards`);
await page.waitForLoadState('networkidle');
check('team, tag and dashboard deleted', (await page.getByText('PW tag board').count()) === 0);

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
