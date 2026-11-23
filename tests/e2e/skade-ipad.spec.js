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

test.describe('touchgester på kundsidan', () => {
    test.use({ hasTouch:true, isMobile:true, viewport:{width:834,height:1194} });
    // CDP sends actual browser touch input, unlike dispatchEvent. This remains
    // a simulated Chromium device; real iPad Safari / standalone is essential.
    test('sidledsdrag stoppas, vertikal scroll och signering fungerar', async ({page,context}, testInfo) => {
        test.skip(!process.env.PORTAL_BASE_URL, 'Ange PORTAL_BASE_URL till skyddad staging.');
        test.skip(testInfo.project.name !== 'chromium-desktop', 'Kräver Chromium CDP.');
        await page.goto('skadeblankett-kund.php');
        await expect(page.locator('#skade-form')).toBeVisible();
        const cdp=await context.newCDPSession(page);
        async function drag(x1,y1,x2,y2) {
            const send=(type,x,y)=>cdp.send('Input.dispatchTouchEvent',{type,touchPoints:type==='touchEnd'?[]:[{x,y,id:1}]});
            await send('touchStart',x1,y1);
            for (let i=1;i<=8;i++) { await send('touchMove',x1+(x2-x1)*i/8,y1+(y2-y1)*i/8); await page.waitForTimeout(12); }
            await send('touchEnd',x2,y2); await page.waitForTimeout(200);
        }
        await page.evaluate(()=>window.scrollTo(0,300));
        const before=await page.locator('#main-content').boundingBox();
        await drag(300,500,600,505);
        const after=await page.locator('#main-content').boundingBox();
        expect(Math.abs(after.x-before.x)).toBeLessThanOrEqual(1);
        expect(Math.abs(await page.evaluate(()=>window.scrollX))).toBeLessThanOrEqual(1);
        const top=await page.evaluate(()=>window.scrollY);
        await drag(700,700,700,350);
        expect(await page.evaluate(()=>window.scrollY)).toBeGreaterThan(top+50);
        // A long, unfocused textarea must not consume the page gesture or alter its value.
        const text=page.locator('#sk-namn'); await text.scrollIntoViewIfNeeded();
        const sample=Array.from({length:18},(_,i)=>`Testrad ${i+1}`).join('\n');
        await text.fill(sample); await text.blur();
        const textRect=await text.boundingBox();
        const beforeText=await text.evaluate(el=>({scrollTop:el.scrollTop,value:el.value}));
        const beforePage=await page.evaluate(()=>window.scrollY);
        await drag(textRect.x+textRect.width/2,textRect.y+textRect.height-15,textRect.x+textRect.width/2,textRect.y+20);
        expect(await page.evaluate(()=>window.scrollY)).toBeGreaterThan(beforePage+20);
        expect(await text.inputValue()).toBe(beforeText.value);
        expect(await text.evaluate(el=>el.scrollTop)).toBe(beforeText.scrollTop);
        await expect(text).not.toBeFocused();
        await text.focus(); await expect(text).toBeFocused();
        await expect(text).toHaveCSS('overflow-y','auto');
        await page.locator('#sk-personnr').fill('19900101-1234');
        await page.locator('#sk-telefon').fill('0701234567');
        await page.locator('#sk-regnr').fill('ABC123');
        await page.locator('#sk-modell').fill('Testbil');
        await page.locator('#sk-forsakring').fill('Testbolag');
        await page.locator('#sk-datum').fill('2026-11-03');
        await page.locator('#skade-review-open').click();
        await expect(page.locator('#skade-review')).toBeVisible();
        await page.locator('#skade-review-continue').click();
        const pad=page.locator('#skade-signature-pad'); await pad.scrollIntoViewIfNeeded();
        const rect=await pad.boundingBox();
        await drag(rect.x+30,rect.y+45,rect.x+150,rect.y+70);
        await expect(page.locator('#skade-signature-png')).toHaveValue(/^data:image\/png;base64,/);
        await cdp.detach();
    });
});
