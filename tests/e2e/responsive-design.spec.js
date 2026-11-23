const { test, expect } = require('@playwright/test');

// Staging only: read-only navigation. No form is submitted and no customer data is created.
test('layout, sharp surfaces and navigation at phone, tablet and desktop widths', async ({ page }, testInfo) => {
    test.skip(!process.env.PORTAL_BASE_URL, 'Ange PORTAL_BASE_URL till en skyddad stagingmiljö.');
    test.skip(testInfo.project.name !== 'chromium-desktop', 'Viewport sizes are varied explicitly in this test.');
    for (const viewport of [
        { width: 320, height: 700 }, { width: 390, height: 844 },
        { width: 768, height: 1024 }, { width: 1280, height: 800 },
        { width: 1920, height: 1080 }, { width: 844, height: 390 },
    ]) {
        await page.setViewportSize(viewport);
        for (const path of ['pwa-underlag.php', 'pwa-protokoll.php', 'skadeblankett-kund.php']) {
            await page.goto(path);
            await expect(page.locator('main')).toBeVisible();
            const metrics = await page.evaluate(() => {
                const main = document.querySelector('main').getBoundingClientRect();
                return { left:main.left, right:main.right, viewport:document.documentElement.clientWidth };
            });
            expect(metrics.left).toBeGreaterThanOrEqual(-1);
            expect(metrics.right).toBeLessThanOrEqual(metrics.viewport + 1);
            const blurred = await page.locator('body *').evaluateAll(elements => elements.some(el => {
                const style = getComputedStyle(el);
                return style.display !== 'none' && (style.backdropFilter || '').includes('blur(');
            }));
            expect(blurred).toBe(false);
        }
    }
    await page.setViewportSize({ width: 320, height: 700 });
    await page.goto('?tab=calendar');
    const toggle = page.locator('#portal-nav-toggle');
    if (await toggle.isVisible()) {
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await toggle.press('Escape');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    }
});
