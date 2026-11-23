// Teams end to end: create a team, add a team folder, make an invite link, switch the
// watchlist to the team, add and delete a note on a story, then delete the team again.
// Leaves no data behind. Fails on JS errors.
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
await page.waitForURL(/\/(reader|dashboards)/, { timeout: 45000, waitUntil: 'commit' });

// Create a team from the link in the user menu.
await page.locator('[data-test=sidebar-menu-button]').click();
await page.locator('[data-test=teams-link]:visible').click();
await page.waitForURL(/\/teams$/);
await page.locator('input[data-test=team-name], [data-test=team-name] input').first().fill('PW check team');
await page.locator('[data-test=create-team]').click();
await page.waitForURL(/\/teams\/\d+$/, { timeout: 10000 });
const teamUrl = page.url();
check('team page opens', (await page.locator('h1', { hasText: 'PW check team' }).count()) === 1);

// Team folder.
await page.locator('input[data-test=team-folder-name], [data-test=team-folder-name] input').first().fill('PW folder');
await page.locator('[data-test=team-folder-add]').click();
await page.waitForTimeout(1200);
check('team folder created', (await page.getByText('PW folder', { exact: true }).count()) > 0);

// Invite link.
await page.locator('[data-test=invite-create]').click();
await page.waitForTimeout(1200);
const inviteUrl = await page.locator('input[data-test=invite-url], [data-test=invite-url] input').first().inputValue();
check(`invite link created (${inviteUrl.replace(/[A-Za-z0-9]{40}$/, '…')})`, /\/teams\/join\/[A-Za-z0-9]{40}$/.test(inviteUrl));
await page.goto(inviteUrl);
check('own invite link redirects members to the team', page.url() === teamUrl);

// Team watchlist switcher.
await page.goto(`${base}/watchlist`);
await page.locator('[data-test=watch-team] select, select[data-test=watch-team]').first()
    .selectOption({ label: 'Team: PW check team' }).catch(async () => {
        // Flux select may render a native select inside a wrapper.
        await page.locator('select').filter({ hasText: 'PW check team' }).first().selectOption({ label: 'Team: PW check team' });
    });
await page.waitForTimeout(1200);
check('watchlist switched to the team', (await page.getByText('Add to the PW check team watchlist').count()) === 1);

// Notes on a story.
await page.goto(`${base}/reader?show=all`);
await page.waitForLoadState('networkidle');
await page.locator('[data-entry-id]').first().click();
await page.waitForTimeout(1500);
const notes = page.locator('[data-test=notes]').first();
check('notes section under the story', (await notes.count()) === 1);
if (await notes.count()) {
    if ((await page.locator('[data-test=note-body]').count()) === 0) {
        await notes.locator('button').first().click();
        await page.waitForTimeout(800);
    }
    await page.locator('textarea[data-test=note-body], [data-test=note-body] textarea').first().fill('PW check note');
    await page.locator('[data-test=note-add]').click();
    await page.waitForTimeout(1200);
    check('note added', (await page.getByText('PW check note').count()) > 0);
    await page.locator('[data-test=note-delete]').last().click();
    await page.waitForTimeout(1200);
    check('note deleted', (await page.getByText('PW check note').count()) === 0);
}

// Clean up: delete the team.
await page.goto(teamUrl);
await page.locator('[data-test=delete-team]').click();
await page.waitForURL(/\/teams$/, { timeout: 10000 });
check('team deleted', (await page.getByText('PW check team').count()) === 0);

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
