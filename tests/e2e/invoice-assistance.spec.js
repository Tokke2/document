const { test, expect } = require('@playwright/test');

const username = process.env.PORTAL_TEST_USERNAME || '';
const pin = process.env.PORTAL_TEST_PIN || '';

test('röstförslag granskas innan det läggs till på en fakturarad', async ({ page }) => {
    test.skip(!process.env.PORTAL_BASE_URL || !username || !pin, 'Kräver skyddad staging och ett särskilt fakturatestkonto.');
    // The test never persists its sample data, including via autosave.
    await page.route('**/*', async (route) => {
        const request = route.request();
        if (request.method() === 'POST' && (request.postData() || '').includes('action=autosave_draft')) {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
        } else {
            await route.continue();
        }
    });
    await page.addInitScript(() => {
        window.SpeechRecognition = class {
            start() {
                queueMicrotask(() => {
                    this.onresult({ results: [[{ transcript: window.__voiceNext || 'Byte av vindruta, två timmar, åttahundrafemtio kronor i timmen' }]] });
                    this.onend();
                });
            }
        };
    });
    await page.goto('?tab=invoice');
    if (await page.locator('#invoice-login-title').isVisible().catch(() => false)) {
        await page.locator('#invoice-login-username').fill(username);
        await page.locator('#invoice-login-pin').fill(pin);
        await page.locator('form:has(input[name="action"][value="invoice_pin_login"]) button[type="submit"]').click();
    }
    await expect(page.locator('#invoice-form')).toBeVisible();
    await page.locator('#invoice-voice-panel summary').click();
    await page.locator('#voice-start').click();
    await expect(page.locator('#voice-name')).toHaveValue('Byte av vindruta');
    await expect(page.locator('#voice-qty')).toHaveValue('2');
    await expect(page.locator('#voice-price')).toHaveValue('850');
    await expect(page.locator('#voice-unit')).toHaveValue('tim');
    await expect(page.locator('.invoice-item-name').first()).toHaveValue('');
    await page.locator('#voice-transcript').fill('Byte av vindruta, en och en halv timme, 850 kronor');
    await page.locator('#voice-apply').click();
    await expect(page.locator('.invoice-item-name').first()).toHaveValue('');
    await page.locator('#voice-parse').click();
    await expect(page.locator('#voice-qty')).toHaveValue('1.5');
    await page.locator('#voice-apply').click();
    await expect(page.locator('.invoice-item-name').first()).toHaveValue('Byte av vindruta');
    await expect(page.locator('.invoice-item-price').first()).toHaveValue('850');
    const rowCount = await page.locator('.invoice-item-name').count();
    page.once('dialog', dialog => dialog.dismiss());
    await page.locator('#voice-apply').click();
    await expect(page.locator('.invoice-item-name')).toHaveCount(rowCount);
    await page.locator('#voice-undo').click();
    await expect(page.locator('.invoice-item-name').first()).toHaveValue('');
    await page.evaluate(() => { window.__voiceNext = '2st Glasreparationer 750 kr st'; });
    await page.locator('#voice-start').click();
    await expect(page.locator('#voice-name')).toHaveValue('Glasreparationer');
    await expect(page.locator('#voice-qty')).toHaveValue('2');
    await expect(page.locator('#voice-unit')).toHaveValue('st');
    await expect(page.locator('#voice-price')).toHaveValue('750');
    await page.locator('#voice-apply').click();
    await page.evaluate(() => { window.__voiceNext = 'nästa'; });
    await page.locator('#voice-start').click();
    await expect(page.locator('#voice-destination')).toHaveValue('2');
    await expect(page.locator('.invoice-item-name').nth(1)).toBeFocused();
});
