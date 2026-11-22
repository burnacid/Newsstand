// Alerts end to end: add an ntfy channel (random topic on ntfy.sh), send a test,
// set a watchlist item's alert level, and check the rule editor offers the channel.
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

// Add an ntfy channel with the suggested defaults (ntfy.sh + random topic).
await page.goto(`${base}/settings/alerts`);
await page.locator('[data-test=add-channel]').click();
await page.waitForTimeout(800);
const topic = await page.locator('input[name="fields.topic"]').inputValue();
check('a random topic is suggested', /^newsstand-[a-z0-9]{12}$/.test(topic));
await page.locator('[data-test=save-channel]').click();
await page.waitForTimeout(1500);
check('channel saved', (await page.locator('[data-test=test-channel]').count()) >= 1);

await page.locator('[data-test=test-channel]').last().click();
await page.waitForTimeout(4000);
check('test sent without errors', (await page.locator('[data-test=channel-error]').count()) === 0 && (await page.getByText('last sent').count()) > 0);

// Watchlist: set an alert level.
await page.goto(`${base}/watchlist`);
const select = page.locator('select[data-test^=alert-level-], [data-test^=alert-level-] select').first();
if (await select.count()) {
    await select.selectOption('kev');
    await page.waitForTimeout(1200);
    await page.reload();
    check('alert level saved', (await page.locator('select[data-test^=alert-level-], [data-test^=alert-level-] select').first().inputValue()) === 'kev');
} else {
    log.push('SKIP  no watchlist items');
}

// Rules: the channel is offered as an action.
await page.goto(`${base}/rules`);
await page.locator('[data-test=new-rule]').click();
await page.waitForTimeout(800);
check('rule editor offers the channel', (await page.locator('[data-test^=rule-alert-]').count()) >= 1);

console.log(log.join('\n'));
console.log(`(test alert went to ntfy topic ${topic})`);
await browser.close();
process.exit(failures ? 1 : 0);
