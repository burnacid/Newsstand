// README screenshots. Run against a server whose database holds only the demo data:
//   php artisan db:seed --class=DemoSeeder   (on an empty, throwaway database)
//   node tests/Browser/screenshots.mjs http://127.0.0.1:8011
// Writes docs/screenshots/*.png.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const out = 'docs/screenshots';
mkdirSync(out, { recursive: true });

const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });

async function session({ dark = false, viewport = { width: 1440, height: 900 } } = {}) {
    const context = await browser.newContext({ viewport, deviceScaleFactor: 1, colorScheme: dark ? 'dark' : 'light' });
    const page = await context.newPage();
    await page.addInitScript((appearance) => localStorage.setItem('flux.appearance', appearance), dark ? 'dark' : 'light');
    await page.goto(`${base}/login`);
    await page.fill('input[name=email]', 'demo@newsstand.test');
    await page.fill('input[name=password]', 'demo-password');
    await page.locator('button[type=submit]').first().click();
    await page.waitForURL(/\/(reader|dashboards)/, { timeout: 45000, waitUntil: 'commit' });
    return { context, page };
}

async function shot(page, path, name) {
    await page.goto(`${base}${path}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${out}/${name}.png` });
    console.log(`saved ${out}/${name}.png`);
}

const { context, page } = await session();

// Reader: list with the top story open.
await page.goto(`${base}/reader?show=all`);
await page.waitForLoadState('networkidle');
await page.locator('[data-test=layout-list]').click();
await page.waitForTimeout(600);
await page.locator('[data-entry-id]').first().click();
await page.waitForTimeout(1200);
await page.mouse.move(0, 0);
await page.screenshot({ path: `${out}/reader.png` });
console.log(`saved ${out}/reader.png`);

// River.
await page.goto(`${base}/reader?show=all`);
await page.waitForLoadState('networkidle');
await page.locator('[data-test=layout-river]').click();
await page.waitForTimeout(1500);
await page.mouse.move(0, 0);
await page.screenshot({ path: `${out}/river.png` });
console.log(`saved ${out}/river.png`);
await page.locator('[data-test=layout-list]').click();
await page.waitForTimeout(800);

// The default dashboard (the sidebar's Dashboards link opens it).
await page.goto(`${base}/reader`);
await page.waitForLoadState('networkidle');
await page.locator('[data-test=nav-dashboard]').first().click();
await page.waitForURL(/\/dashboards\/\d+/);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1000);
await page.screenshot({ path: `${out}/dashboard.png` });
console.log(`saved ${out}/dashboard.png`);
await shot(page, '/security', 'security');
await shot(page, '/security/cves/CVE-2026-41021', 'cve');
await shot(page, '/rules', 'rules');
await context.close();

// Dark mode.
{
    const { context, page } = await session({ dark: true });
    await page.goto(`${base}/reader?show=all`);
    await page.waitForLoadState('networkidle');
    await page.locator('[data-entry-id]').nth(3).click();
    await page.waitForTimeout(1200);
    await page.mouse.move(0, 0);
    await page.screenshot({ path: `${out}/reader-dark.png` });
    console.log(`saved ${out}/reader-dark.png`);
    await context.close();
}

// Phone.
{
    const { context, page } = await session({ viewport: { width: 390, height: 844 } });
    await shot(page, '/reader?show=all', 'mobile');
    await context.close();
}

await browser.close();
