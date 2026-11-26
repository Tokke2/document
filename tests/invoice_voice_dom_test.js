'use strict';
// Simulated DOM and speech API; a real microphone/browser test is still needed.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const voice = require('../assets/invoice_voice.js');
const output = execFileSync('npx', ['--yes', '@php-wasm/cli', '-r', 'require "tests/php/invoice_assistance_render_regression.php"; echo "VOICE_HTML:".base64_encode(render_invoice(true,false,[],"sv"));'], { encoding: 'utf8' });
const html = Buffer.from(output.split('VOICE_HTML:')[1], 'base64').toString('utf8');
function setup() {
    const dom = new JSDOM(html, { url: 'https://example.test/?tab=invoice', runScripts: 'outside-only', pretendToBeVisual: true });
    const win = dom.window;
    let current;
    class Recognition {
        constructor() { current = this; }
        start() {}
        stop() { if (this.onend) this.onend(); }
        abort() { if (this.onend) this.onend(); }
    }
    win.matchMedia = () => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {} });
    win.fetch = () => Promise.resolve({ ok: true, json: async () => ({ ok: true }) });
    win.confirm = () => false;
    win.SpeechRecognition = Recognition;
    win.InvoiceVoice = voice;
    for (const script of win.document.querySelectorAll('script:not([src])')) win.eval(script.textContent);
    const get = (selector) => win.document.querySelector(selector);
    function input(selector, value) { const el = get(selector); el.value = value; el.dispatchEvent(new win.Event('input', { bubbles: true })); }
    return { dom, win, get, input, engine: () => current };
}
function final(text) { const result = [{ transcript: text }]; result.isFinal = true; return result; }
{
    const c = setup();
    c.input('#voice-transcript', 'Byte av vindruta, två timmar, 850 kronor');
    c.get('#voice-apply').click();
    assert.equal(c.get('.invoice-item-name').value, '', 'Changed transcript must be parsed before application');
    c.get('#voice-parse').click();
    assert.equal(c.get('#voice-name').value, 'Byte av vindruta');
    assert.equal(c.get('#voice-unit').value, 'tim');
    assert.equal(c.get('.invoice-item-name').value, '', 'Parsing only changes the preview');
    c.get('#voice-apply').click();
    assert.equal(c.get('.invoice-item-name').value, 'Byte av vindruta');
    assert.equal(c.get('.invoice-item-price').value, '850');
    c.get('#voice-apply').click();
    assert.equal(c.win.document.querySelectorAll('.invoice-item-name').length, 1, 'Duplicate application needs confirmation');
    c.get('.invoice-item-name').value = 'Manuellt ändrad';
    c.get('#voice-undo').click();
    assert.equal(c.get('.invoice-item-name').value, 'Manuellt ändrad', 'Undo must not overwrite manual changes');
    c.dom.window.close();
}
{
    const c = setup();
    c.input('#voice-transcript', 'Däck, två paket, 100 kronor'); c.get('#voice-parse').click();
    assert.equal(c.get('#voice-unit').value, '', 'Unknown unit is not guessed');
    c.get('#voice-apply').click();
    assert.equal(c.get('.invoice-item-name').value, '', 'Cannot apply without an explicit unit');
    c.get('#voice-unit').value = 'st'; c.get('#voice-qty').value = '2'; c.get('#voice-price').value = '100'; c.get('#voice-apply').click();
    assert.equal(c.get('.invoice-item-name').value, 'Däck');
    c.get('#voice-undo').click();
    assert.equal(c.get('.invoice-item-name').value, '', 'Undo restores reused empty row');
    c.dom.window.close();
}
{
    const c = setup(); c.get('#voice-start').click();
    const engine = c.engine();
    c.input('#voice-name', 'Ändrat medan mikrofonen lyssnar');
    engine.onresult({ resultIndex: 0, results: [final('2st Glasreparationer 750 kr st')] });
    engine.onend();
    assert.equal(c.get('#voice-name').value, 'Ändrat medan mikrofonen lyssnar', 'Live edits to the proposal cannot be overwritten by late speech');
    assert.equal(c.get('.invoice-item-name').value, '');
    c.dom.window.close();
}
{
    const c = setup(); c.get('#voice-start').click();
    const engine = c.engine();
    assert.equal(engine.lang, 'sv-SE');
    assert.equal(c.get('#voice-start').disabled, true);
    engine.onresult({ resultIndex: 0, results: [final('Byte av vindruta, två timmar,')] });
    engine.onresult({ resultIndex: 1, results: [final('Byte av vindruta, två timmar,'), final('850 kronor')] });
    assert.equal(c.get('#voice-transcript').value, '', 'No partial result is applied while recording');
    c.get('#voice-stop').click();
    assert.equal(c.get('#voice-name').value, 'Byte av vindruta');
    assert.equal(c.get('#voice-price').value, '850');
    assert.equal(c.get('.invoice-item-name').value, '', 'Speech only updates the preview');
    assert.equal(c.get('#voice-start').disabled, false);
    c.dom.window.close();
}
{
    const c = setup(); c.get('#voice-start').click(); const engine = c.engine();
    c.get('#voice-cancel').click();
    engine.onresult({ results: [final('Byte, två timmar, 850 kronor')] }); engine.onend();
    assert.equal(c.get('#voice-transcript').value, '', 'Late results after cancellation are discarded');
    assert.equal(c.get('#voice-start').disabled, false);
    c.dom.window.close();
}
{
    const c = setup(); c.get('#voice-start').click(); const engine = c.engine();
    engine.onresult({ results: [final('Byte, två timmar, 850 kronor')] });
    c.input('#voice-transcript', 'Manuellt förslag'); engine.onend();
    assert.equal(c.get('#voice-transcript').value, 'Manuellt förslag', 'Recognition must not overwrite text edited while recording');
    assert.match(c.get('#voice-status').textContent, /ändrades/);
    c.dom.window.close();
}
{
    const c = setup(); c.get('#voice-start').click(); const engine = c.engine();
    engine.onerror({ error: 'not-allowed' }); engine.onend();
    assert.match(c.get('#voice-status').textContent, /blockerad/);
    assert.equal(c.get('#voice-start').disabled, false);
    c.dom.window.close();
}
{
    const c = setup(); c.get('#voice-start').click(); const engine = c.engine();
    engine.onresult({ results: [final('Byte, två timmar, 850 kronor')] });
    c.get('#voice-target').value = 'customer';
    c.get('#voice-target').dispatchEvent(new c.win.Event('change', { bubbles: true }));
    engine.onend();
    assert.equal(c.get('#invoice-customer').value, '', 'Switching target during speech cannot populate a different field');
    assert.equal(c.get('#voice-transcript').value, '');
    c.dom.window.close();
}
{
    const c = setup(); c.get('#voice-start').click(); const engine = c.engine();
    engine.onresult({ results: [final('a'.repeat(1001))] }); engine.onend();
    assert.equal(c.get('#voice-transcript').value, '', 'Overlong speech is rejected, not truncated into a proposal');
    assert.match(c.get('#voice-status').textContent, /för lång/);
    c.dom.window.close();
}
{
    const c = setup();
    const say = text => { c.get('#voice-start').click(); const e = c.engine(); e.onresult({ results: [final(text)] }); e.onend(); };
    say('2st Glasreparationer 750 kr st');
    assert.equal(c.get('#voice-name').value, 'Glasreparationer');
    assert.equal(c.get('#voice-qty').value, '2');
    assert.equal(c.get('#voice-unit').value, 'st');
    assert.equal(c.get('#voice-price').value, '750');
    assert.equal(c.get('.invoice-item-name').value, '', 'Recognized text is only a proposal');
    say('nästa');
    assert.equal(c.win.document.querySelectorAll('.invoice-item-name').length, 1, 'Do not abandon an unapproved proposal');
    assert.match(c.get('#voice-status').textContent, /inte använt/);
    c.get('#voice-apply').click();
    assert.equal(c.get('.invoice-item-name').value, 'Glasreparationer');
    say('nästa');
    let rows = c.win.document.querySelectorAll('.invoice-item-name');
    assert.equal(rows.length, 2, 'Next on the last row creates a blank row');
    assert.equal(c.win.document.activeElement, rows[1]);
    assert.equal(c.get('#voice-destination').value, '2');
    say('2st Packningar 200 kr st');
    assert.equal(rows[1].value, '', 'Next proposal also needs approval');
    c.get('#voice-apply').click();
    assert.equal(rows[0].value, 'Glasreparationer'); assert.equal(rows[1].value, 'Packningar');
    say('rad ett');
    assert.equal(c.get('#voice-destination').value, '1', 'Named row is selected as the destination');
    say('3st Nya rutor 400 kr st');
    c.get('#voice-apply').click();
    assert.equal(rows[0].value, 'Glasreparationer', 'Existing row is not overwritten without confirmation');
    c.win.confirm = () => true;
    c.get('#voice-apply').click();
    assert.equal(rows[0].value, 'Nya rutor');
    c.get('#voice-undo').click();
    assert.equal(rows[0].value, 'Glasreparationer', 'Undo restores a confirmed overwritten row');
    c.dom.window.close();
}
{
    const c = setup();
    const say = text => { c.get('#voice-start').click(); const e = c.engine(); e.onresult({ results: [final(text)] }); e.onend(); };
    say('2st Glasreparationer 750 kr st punkt');
    assert.equal(c.get('#voice-name').value, 'Glasreparationer');
    assert.equal(c.get('.invoice-item-name').value, '', 'A trailing command never approves a proposal');
    assert.match(c.get('#voice-status').textContent, /Granska/);
    c.get('#voice-apply').click();
    assert.equal(c.win.document.querySelectorAll('.invoice-item-name').length, 2, 'Trailing punkt advances only after approval');
    assert.equal(c.get('.invoice-item-name').value, 'Glasreparationer');
    say('punkt');
    assert.equal(c.win.document.querySelectorAll('.invoice-item-name').length, 3, 'Standalone punkt navigates');
    say('.');
    assert.equal(c.win.document.querySelectorAll('.invoice-item-name').length, 4, 'A recognized period also navigates');
    c.dom.window.close();
}
{
    const c = setup();
    const say = text => { c.get('#voice-start').click(); const e = c.engine(); e.onresult({ results: [final(text)] }); e.onend(); };
    assert.equal(c.get('#voice-live-commands').hidden, true, 'Live command strip starts hidden');
    c.get('#voice-start').click();
    assert.equal(c.get('#voice-live-commands').hidden, false, 'Live command strip shows while listening');
    const listening = c.engine();
    listening.onresult({ results: [final('rensa')] }); listening.onend();
    assert.equal(c.get('#voice-live-commands').hidden, true, 'Strip hides when listening stops');
    c.input('#voice-transcript', '2st Glasreparationer 750 kr st');
    c.get('#voice-parse').click();
    assert.equal(c.get('#voice-name').value, 'Glasreparationer');
    say('rensa');
    assert.equal(c.get('#voice-transcript').value, '', 'rensa clears the transcript');
    assert.equal(c.get('#voice-name').value, '', 'rensa clears the parsed proposal');
    assert.match(c.get('#voice-status').textContent, /rensades/);
    c.input('#voice-transcript', '2st Glasreparationer 750 kr st');
    c.get('#voice-parse').click();
    say('avbryt');
    assert.equal(c.get('#voice-transcript').value, '', 'avbryt clears the transcript');
    assert.match(c.get('#voice-status').textContent, /avbröts/);
    c.input('#voice-transcript', '2st Glasreparationer 750 kr st');
    c.get('#voice-parse').click();
    c.get('#voice-status').textContent = '';
    c.get('#voice-transcript').dispatchEvent(new c.win.KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true, cancelable: true }));
    assert.notEqual(c.get('#voice-status').textContent, '', 'Ctrl+Enter runs the apply handler');
    assert.equal(c.get('.invoice-item-name').value, 'Glasreparationer', 'Ctrl+Enter applies a valid proposal');
    c.dom.window.close();
}
console.log('PASS: invoice voice simulated DOM checks.');
