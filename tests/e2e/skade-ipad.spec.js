const { test, expect } = require('@playwright/test');

// Read-only staging test: no submission, PDF or email. A physical iPad is still required.
test('kundläge fungerar i iPad-storlekar och utan sidledsskroll', async ({ page }, testInfo) => {
    test.skip(!process.env.PORTAL_BASE_URL, 'Ange PORTAL_BASE_URL till skyddad staging.');
    test.skip(!['chromium-desktop', 'webkit-desktop'].includes(testInfo.project.name), 'Viewport-storlekar varieras explicit.');
    for (const viewport of [
        { width:320,height:700 }, { width:390,height:844 },
        { width:768,height:1024 }, { width:1024,height:768 },
        { width:834,height:1194 }, { width:1194,height:834 },
    ]) {
        await page.setViewportSize(viewport);
        await page.goto('skadeblankett-kund.php');
        await expect(page.locator('#skade-form')).toBeVisible();
        await expect(page.locator('#skade-install-help summary')).toBeVisible();
        await expect(page.locator('#skade-progress-track')).toHaveAttribute('aria-valuenow','0');
        const layout = await page.evaluate(() => {
            const width=document.documentElement.clientWidth;
            const controls=[...document.querySelectorAll('#skade-form input:not([type=hidden]), #skade-form textarea, #skade-form button, #skade-form canvas, .skade-kiosk-mode .skade-card')];
            return { width, scroll: document.documentElement.scrollWidth, controls: controls.filter(el => {
                const r=el.getBoundingClientRect(), style=getComputedStyle(el);
                return style.display!=='none' && (r.left < -1 || r.right > width+1);
            }).map(el=>el.id || el.className) };
        });
        expect(layout.scroll).toBeLessThanOrEqual(layout.width+1);
        expect(layout.controls).toEqual([]);
        expect(await page.locator('.skade-submit').evaluate(el=>getComputedStyle(el).position)).toBe('static');
        await page.locator('#sk-namn').fill('Test Kund\nTestgatan 1');
        await expect(page.locator('#skade-progress-track')).not.toHaveAttribute('aria-valuenow','0');
    }
});
