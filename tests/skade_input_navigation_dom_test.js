'use strict';
// Regression test for the skadeblankett customer inputs: numeric keypads for
// personal number and phone (letters stripped), Enter moving focus to the next
// required field instead of submitting, and a larger signature pad with a
// pencil baseline.
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {JSDOM, VirtualConsole} = require('jsdom');

function render(internal, language) {
    const php = `require "tests/php/skade_customer_render_fixture.php";
echo base64_encode(render_customer_skade(false, ${internal ? 'true' : 'false'}, [], false, false, [], '${language || 'sv'}'));`;
    const out = execFileSync('npx', ['--yes', '@php-wasm/cli', '-r', php], {encoding: 'utf8'}).trim();
    return Buffer.from(out, 'base64').toString('utf8');
}

function load(html, url) {
    const errors = [];
    const virtual = new VirtualConsole();
    virtual.on('jsdomError', (error) => errors.push(String(error)));
    const dom = new JSDOM(`<!doctype html><html><body class="skade-kiosk-mode">${html}</body></html>`, {
        url,
        runScripts: 'dangerously',
        virtualConsole: virtual,
        beforeParse(window) {
            window.matchMedia = window.matchMedia || function () {
                return {matches: false, media: '', addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}};
            };
            window.fetch = undefined;
        },
    });
    return {dom, d: dom.window.document, errors, w: dom.window};
}

function pressEnter(window, element) {
    element.focus();
    const event = new window.KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true});
    element.dispatchEvent(event);
    return event;
}

(async () => {
    // 1. Numeric keypads: personal number and phone both ask for digits only.
    const svHtml = render(false, 'sv');
    const sv = load(svHtml, 'https://example.test/skadeblankett-kund.php');
    const {w, d} = sv;
    const personnr = d.getElementById('sk-personnr');
    const telefon = d.getElementById('sk-telefon');
    assert.ok(personnr && telefon, 'personal number and phone fields exist');
    assert.equal(personnr.getAttribute('inputmode'), 'numeric', 'personal number opens the numeric keypad');
    assert.equal(telefon.getAttribute('inputmode'), 'numeric', 'phone opens the numeric keypad');
    assert.notEqual(personnr.getAttribute('inputmode'), 'text', 'personal number must not open the text keypad');

    // 2. Typing letters into those fields strips them; digits and separators stay.
    personnr.value = 'ABC19900101-1234';
    personnr.dispatchEvent(new w.Event('input', {bubbles: true}));
    assert.equal(personnr.value, '19900101-1234', 'letters are stripped from the personal number');
    telefon.value = '+46abc701 234 56';
    telefon.dispatchEvent(new w.Event('input', {bubbles: true}));
    assert.equal(telefon.value, '+46701 234 56', 'letters are stripped from the phone number, separators stay');

    // 3. Enter moves to the next required field and never submits the form.
    let event = pressEnter(w, personnr);
    assert.equal(d.activeElement, telefon, 'Enter on personal number focuses the next required field');
    assert.equal(event.defaultPrevented, true, 'Enter must not trigger the implicit form submit');

    const regnr = d.getElementById('sk-regnr');
    pressEnter(w, telefon);
    assert.equal(d.activeElement, regnr, 'Enter on phone skips the optional email field to the next required field');

    // Enter on the last required field moves to the review action.
    const skadedatum = d.getElementById('sk-datum');
    const reviewOpen = d.getElementById('skade-review-open');
    assert.ok(skadedatum && reviewOpen, 'last required field and review button exist');
    pressEnter(w, skadedatum);
    assert.equal(d.activeElement, reviewOpen, 'Enter on the last required field focuses the review button');
    assert.deepEqual(sv.errors, [], 'customer render must not raise jsdom errors');

    // 4. Signature pad: taller backing store, taller box, pencil baseline.
    const canvas = d.getElementById('skade-signature-pad');
    assert.ok(canvas, 'signature canvas exists');
    assert.ok(Number(canvas.getAttribute('height')) >= 460, 'signature backing store grew taller');
    assert.equal(canvas.getAttribute('width'), '1600', 'signature backing store width stays stable');
    const box = w.getComputedStyle(canvas);
    assert.ok(parseFloat(box.minHeight) >= 180, 'signature box has a taller minimum height');
    assert.match(svHtml, /\.skade-signature-canvas \{[^}]*background-image:linear-gradient\(/, 'signature box shows a pencil baseline');
    assert.match(svHtml, /body\.skade-kiosk-mode \.skade-signature-canvas \{ height:240px; min-height:240px; \}/, 'kiosk mode grew the signature box too');

    // 5. Internal/admin render: no Enter override (admin keeps plain submit).
    const adminHtml = render(true, 'sv');
    assert.doesNotMatch(adminHtml, /event\.key !== 'Enter'/, 'admin markup must not override Enter');
    console.log('PASS: numeric keypads, Enter-to-required navigation and larger signature pad.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
