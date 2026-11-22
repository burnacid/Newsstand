// Watchlist + CVE pages end to end. Adds a watchlist item, opens the reader's
// watchlist view, a CVE page and the "My products" filter. Fails on JS errors.
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

// Add an item through the UI (vendor suggestions come from a datalist).
await page.goto(`${base}/watchlist`);
check('vendor suggestions available', (await page.locator('#watch-vendors option').count()) > 50);
await page.locator('[data-test=watch-vendor] input, input[data-test=watch-vendor]').first().fill('Ivanti');
await page.locator('[data-test=watch-add]').click();
await page.waitForTimeout(1500);
check('item added', (await page.getByText('Ivanti', { exact: true }).count()) > 0);

// Reader: watchlist view with badges.
await page.goto(`${base}/reader?s=watchlist&show=all`);
await page.waitForLoadState('networkidle');
const rows = await page.locator('[data-entry-id]').count();
check('reader shows watched stories', rows > 0);
check('stories carry the watchlist badge', (await page.locator('[data-test=watch-badge]').count()) > 0);

// Open a story with CVEs and follow a CVE link to its page.
await page.goto(`${base}/reader?show=all&q=CVE-`);
await page.waitForLoadState('networkidle');
await page.locator('[data-entry-id]').first().click();
await page.waitForTimeout(1500);
const cveLink = page.locator('[data-test=cve-link]').first();
if (await cveLink.count()) {
    const id = (await cveLink.innerText()).trim();
    await cveLink.click();
    await page.waitForURL(/\/security\/cves\//, { timeout: 10000 });
    check(`CVE page opens (${id})`, (await page.locator('h1', { hasText: id }).count()) === 1);
} else {
    log.push('SKIP  no story with CVE links found');
}

// Vulnerabilities: My products.
await page.goto(`${base}/security`);
await page.locator('[data-test=filter-mine]').click();
await page.waitForTimeout(1500);
check('My products filter lists CVEs', (await page.locator('a[href*="/security/cves/"]').count()) > 0);

console.log(log.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
