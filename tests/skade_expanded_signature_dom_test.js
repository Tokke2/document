'use strict';
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { JSDOM, VirtualConsole } = require('jsdom');
const php = 'require "tests/php/skade_customer_render_fixture.php"; $out=[]; foreach([false,true] as $internal) foreach(["sv","en"] as $lang) $out[]=render_customer_skade(false,$internal,[],false,false,[],$lang); echo base64_encode(json_encode($out));';
const forms = JSON.parse(Buffer.from(execFileSync('npx', ['--yes', '@php-wasm/cli', '-r', php], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }).trim(), 'base64').toString());
for (const html of forms) {
    let opts, clears = 0, resizes = 0, hasInk = false;
    const errors = [], vc = new VirtualConsole(); vc.on('jsdomError', e => errors.push(e));
    const dom = new JSDOM(html, { url: 'https://example.test/skadeblankett-kund.php', runScripts: 'dangerously', virtualConsole: vc, pretendToBeVisual: true,
        beforeParse(w) {
            w.HTMLCanvasElement.prototype.toDataURL = () => 'data:image/png;base64,TEST';
            const context = { getImageData() {
                const data = new Uint8ClampedArray(160 * 80 * 4);
                if (hasInk) { const pos = (30 * 160 + 30) * 4; data[pos] = 25; data[pos + 3] = 255; }
                return { data };
            }, drawImage() {} };
            w.HTMLCanvasElement.prototype.getContext = () => context;
            w.TadegaSignaturePad = { attach(canvas, options) {
                opts = options; canvas.width = 160; canvas.height = 80; canvas.style.touchAction = 'none';
                canvas.getBoundingClientRect = () => ({ width: 320, height: 160 });
                return { context, resize() { resizes++; }, clear() { clears++; hasInk = false; }, setBaseImage() {} };
            } };
        }
    });
    const w = dom.window, d = w.document;
    const canvas = d.getElementById('skade-signature-pad'), png = d.getElementById('skade-signature-png');
    const expand = d.getElementById('skade-signature-expand'), card = canvas.closest('.skade-signature');
    const initialStyle = canvas.getAttribute('style');
    hasInk = true; opts.onEnd(); const saved = png.value;
    assert.equal(saved, 'data:image/png;base64,TEST');
    assert.equal(expand.hidden, false);
    expand.click();
    assert.equal(card.getAttribute('role'), 'dialog'); assert.equal(card.getAttribute('aria-modal'), 'true');
    assert.equal(d.activeElement, canvas); assert.equal(png.value, saved);
    assert.equal(d.getElementById('skade-signature-pad'), canvas, 'Same canvas remains in the form');
    assert.equal(d.querySelectorAll('#skade-signature-pad').length, 1);
    assert.equal(d.getElementById('skade-form').contains(canvas), true);
    assert.ok(resizes > 0); assert.equal(clears, 0);
    assert.equal(parseFloat(canvas.style.width) / parseFloat(canvas.style.height), 2, 'Original signature proportions preserved');
    d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    assert.equal(d.activeElement, expand, 'Tab stays inside signing dialog');
    d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    assert.equal(card.hasAttribute('aria-modal'), false); assert.equal(d.activeElement, expand);
    assert.equal(canvas.getAttribute('style'), initialStyle); assert.equal(png.value, saved); assert.equal(clears, 0);
    expand.click(); d.getElementById('skade-signature-clear').click(); assert.equal(clears, 1); assert.equal(png.value, '');
    hasInk = true; opts.onEnd(); expand.click(); assert.equal(png.value, saved, 'New ink survives done/back');
    expand.click(); w.dispatchEvent(new w.Event('resize')); assert.equal(png.value, saved);
    const submit = new w.Event('submit', { bubbles: true, cancelable: true });
    d.getElementById('skade-form').dispatchEvent(submit);
    assert.equal(submit.defaultPrevented, true, 'Fullscreen cannot accidentally submit the whole form');
    assert.equal(card.hasAttribute('aria-modal'), false);
    const email = d.getElementById('sk-epost'), group = email.closest('.skade-more-fields');
    assert.ok(group); assert.equal(group.open, false);
    assert.equal(d.getElementById('sk-namn').closest('details'), null, 'Required customer details stay visible');
    assert.equal(d.getElementById('sk-modell').closest('details'), null, 'Required vehicle model stays visible');
    email.value = 'invalid-email'; email.dispatchEvent(new w.Event('invalid', { cancelable: true }));
    assert.equal(group.open, true, 'Invalid optional field opens its group');
    assert.equal(new w.FormData(d.getElementById('skade-form')).get('s[signature_png]'), saved);
    assert.deepEqual(errors, []);
    w.close();
}
console.log('PASS: expanded signature keeps canvas, ink, proportions and form values; clear, Done, Escape, resize, focus trap, submit guard and optional-field reveal (staff/customer, Swedish/English).');
