'use strict';
// Production PHP/JS with simulated recognition events, not a real microphone.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { JSDOM, VirtualConsole } = require('jsdom');
const voice = require('../assets/invoice_voice.js');
const php = 'require "tests/php/invoice_assistance_render_regression.php"; $out=[]; foreach([false,true] as $pwa) foreach(["sv","en"] as $lang) $out[]=render_invoice(false,$pwa,[],$lang); echo "FINISH_HTML:".base64_encode(json_encode($out));';
const out = execFileSync('npx', ['--yes', '@php-wasm/cli', '-r', php], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
const forms = JSON.parse(Buffer.from(out.split('FINISH_HTML:')[1], 'base64').toString());
const result = (text, isFinal = true) => { const r = [{ transcript: text }]; r.isFinal = isFinal; return r; };
function setup(html, api = 'SpeechRecognition') {
    const errors = [], vc = new VirtualConsole(); vc.on('jsdomError', e => errors.push(e));
    const dom = new JSDOM(html, { url: 'https://example.test/pwa-underlag.php', runScripts: 'outside-only', virtualConsole: vc, pretendToBeVisual: true });
    const w = dom.window, get = s => w.document.querySelector(s), engines = [];
    class Recognition {
        constructor() { engines.push(this); this.stops = 0; this.aborts = 0; this.starts = 0; }
        start() { this.starts++; }
        stop() { this.stops++; } // onend deliberately delayed to exercise races.
        abort() { this.aborts++; }
    }
    if (api) w[api] = Recognition;
    w.InvoiceVoice = voice; w.confirm = () => true;
    w.matchMedia = () => ({ matches: false, addEventListener() {}, addListener() {} });
    w.fetch = async () => ({ ok: true, json: async () => ({ ok: true }) });
    for (const script of w.document.querySelectorAll('script:not([src])')) w.eval(script.textContent);
    const start = () => { get('#invoice-open-voice').click(); return engines.at(-1); };
    const send = (engine, results, resultIndex = 0) => engine.onresult({ results, resultIndex });
    return { dom, w, get, engines, errors, start, send };
}
for (const [index, html] of forms.entries()) {
    const c = setup(html, index % 2 ? 'webkitSpeechRecognition' : 'SpeechRecognition');
    const { get, send } = c;
    const first = c.start();
    assert.equal(c.engines.length, 1, 'One tap opens the panel and starts one engine');
    assert.equal(first.starts, 1); assert.equal(first.continuous, true);
    assert.equal(get('#invoice-voice-panel').open, true);
    assert.equal(get('#invoice-open-voice').disabled, true, 'Repeated taps cannot start concurrent engines');
    assert.match(get('#voice-finish-help').textContent, /slut.*färdigt/);
    send(first, [result('Arbete två timmar 500 kronor färdigt', false)]);
    assert.equal(first.stops, 0, 'Interim stop words must not stop recognition');
    assert.equal(get('#voice-transcript').value, '');
    send(first, [result('Arbete två timmar 500 kronor. FÄRDIGT!')]);
    assert.equal(first.stops, 1, 'Final trailing command stops immediately');
    assert.equal(get('#voice-transcript').value, '', 'Wait for end before committing the proposal');
    send(first, [result('Sena ord som inte ska komma med')]);
    assert.equal(first.stops, 1);
    first.onend(); first.onend();
    assert.equal(get('#voice-transcript').value, 'Arbete två timmar 500 kronor');
    assert.equal(get('#voice-name').value, 'Arbete');
    assert.equal(get('.invoice-item-name').value, '', 'Finish does not silently apply or save a row');
    assert.equal(get('#invoice-open-voice').disabled, false);
    assert.equal(get('#voice-heard').hidden, true);

    // A command can be spoken after a pause as a separate final result.
    const separate = c.start();
    send(separate, [result('Glasbyte två stycken'), result('750 kronor styck')]);
    assert.equal(separate.stops, 0, 'Continuous recognition waits through multiple final results');
    send(separate, [result('Glasbyte två stycken'), result('750 kronor styck'), result('slut.')], 2);
    assert.equal(separate.stops, 1); separate.onend();
    assert.equal(get('#voice-transcript').value, 'Glasbyte två stycken 750 kronor styck');

    const only = c.start(), previous = get('#voice-transcript').value;
    send(only, [result('färdigt')]); assert.equal(only.stops, 1); only.onend();
    assert.equal(get('#voice-transcript').value, previous, 'A bare command leaves existing proposal intact');

    for (const text of ['Slutpris 500 kronor', 'Färdigt arbete två timmar', 'Arbete slut kontroll två timmar 500 kronor', 'Arbetsslut två timmar 500 kronor']) {
        const natural = c.start(); send(natural, [result(text)]);
        assert.equal(natural.stops, 0, 'Not a trailing whole-word command: ' + text);
        natural.onend(); assert.equal(get('#voice-transcript').value, text, 'Natural browser end keeps ordinary dictation');
    }
    const changed = c.start(); send(changed, [result('Arbete två timmar 500 kronor slut')]);
    get('#invoice-customer').value = 'Annan kund';
    get('#invoice-customer').dispatchEvent(new c.w.Event('input', { bubbles: true }));
    const unchanged = get('#voice-transcript').value; changed.onend();
    assert.equal(get('#voice-transcript').value, unchanged, 'Customer change wins over pending spoken finish');
    assert.equal(changed.aborts, 1);

    const cancelled = c.start(); send(cancelled, [result('Arbete två timmar 500 kronor färdigt')]);
    get('#voice-cancel').click(); cancelled.onend();
    assert.equal(get('#voice-transcript').value, unchanged, 'Cancel wins over pending spoken finish');

    const manual = c.start(); send(manual, [result('Arbete två timmar 700 kronor')]);
    get('#voice-stop').click(); assert.equal(manual.stops, 1); manual.onend();
    assert.equal(get('#voice-transcript').value, 'Arbete två timmar 700 kronor', 'Manual stop remains available');

    // The same trailing command is stripped for non-row destinations.
    get('#voice-target').value = 'reference'; get('#voice-target').dispatchEvent(new c.w.Event('change'));
    const reference = c.start(); send(reference, [result('Beställning 42, slut!')]); reference.onend();
    assert.equal(get('#voice-transcript').value, 'Beställning 42');
    assert.equal(get('#invoice-reference').value, '', 'Reference is still a proposal');

    const failedStop = c.start(); failedStop.stop = () => { throw Error('stop unavailable'); };
    send(failedStop, [result('Ny referens färdigt')]); assert.equal(failedStop.aborts, 1);
    failedStop.onend(); assert.equal(get('#voice-transcript').value, 'Ny referens');
    assert.deepEqual(c.errors, []); c.dom.window.close();
}
const unsupported = setup(forms[0], null);
unsupported.get('#invoice-open-voice').click();
assert.equal(unsupported.get('#invoice-voice-panel').open, true, 'Unsupported browser can still type into the panel');
assert.equal(unsupported.engines.length, 0);
assert.equal(unsupported.get('#voice-start').disabled, true);
unsupported.dom.window.close();
console.log('PASS: one-tap continuous dictation, final slut/färdigt commands, punctuation, separate segments, interim/late results, natural/manual end, preserved proposal, customer/cancel guards, stop fallback and unsupported browser (portal/PWA, sv/en, prefixed API).');
