// River view: scrolling past stories should mark them read.
// Prints how many stories were scrolled past; compare with the database.
import { chromium } from 'playwright';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: process.env.PW_CHANNEL ?? 'msedge' });
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
page.on('pageerror', (e) => console.log('pageerror:', e.message));

await page.goto(`${base}/login`);
await page.fill('input[name=email]', 'playwright@example.test');
await page.fill('input[name=password]', 'playwright-pass');
await page.locator('button[type=submit]').first().click();
await page.waitForURL(/\/(reader|dashboards)/, { timeout: 15000 });

await page.goto(`${base}/reader`);
await page.locator('[data-test=layout-river]').click();
await page.waitForTimeout(1000);

const area = page.locator('[data-scroll-area]');
for (let i = 0; i < 6; i++) {
    await area.evaluate((el) => el.scrollBy(0, 400));
    await page.waitForTimeout(150);
}
await page.waitForTimeout(2000); // batch flush (700ms) + request

const passed = await page.locator('[data-river-id]').evaluateAll((els) => {
    const top = document.querySelector('[data-scroll-area]').getBoundingClientRect().top;
    return els.filter((el) => el.getBoundingClientRect().top < top - 1).length;
});
const tinted = await page.locator('[data-river-id].bg-zinc-100').count();
console.log(`scrolled past: ${passed}, shown as read: ${tinted}`);

await page.locator('[data-test=layout-list]').click();
await page.waitForTimeout(500);
await browser.close();
