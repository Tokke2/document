'use strict';
// Regression test for the skadeblankett field-help affordance: a small "?"
// button per customer field that toggles a "what and why" panel.
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

const fieldIds = ['sk-namn', 'sk-personnr', 'sk-telefon', 'sk-epost', 'sk-regnr', 'sk-modell',
    'sk-matarstallning', 'sk-forsakring', 'sk-datum', 'sk-plats', 'sk-skadenr',
    'sk-photo-damage', 'sk-photo-front'];

(async () => {
    // 1. Markup: every customer field carries one ?-button wired to a hidden
    //    "what and why" panel; the button sits in the label row after the label.
    const svHtml = render(false, 'sv');
    const sv = load(svHtml, 'https://example.test/skadeblankett-kund.php');
    const {w, d} = sv;
    const buttons = d.querySelectorAll('.skade-help-btn');
    assert.equal(buttons.length, fieldIds.length, 'every customer field must get one help button');
    for (const id of fieldIds) {
        const btn = d.getElementById(`${id}-why-btn`);
        assert.ok(btn, `${id} help button must exist`);
        assert.equal(btn.tagName, 'BUTTON', 'help affordance must be a button');
        assert.equal(btn.getAttribute('type'), 'button', 'help button must not submit the form');
        assert.equal(btn.getAttribute('aria-expanded'), 'false', 'panel starts collapsed');
        assert.equal(btn.getAttribute('aria-controls'), `${id}-why`, 'button must control its panel');
        assert.equal(btn.getAttribute('aria-label'), 'Varför detta fält', 'Swedish accessible name');
        assert.equal(btn.textContent.trim(), '?', 'button shows a question mark');
        const row = btn.closest('.skade-label-row');
        assert.ok(row, 'button must live in the label row');
        const label = row.querySelector(`label[for="${id}"]`);
        assert.ok(label, 'label stays inside the same row');
        assert.ok(label.nextElementSibling === btn, 'button directly follows the label');
        const panel = d.getElementById(`${id}-why`);
        assert.ok(panel, `${id} help panel must exist`);
        assert.equal(panel.hidden, true, 'panel starts hidden');
        assert.match(panel.textContent, /Vad:/, 'Swedish panel states what the field is');
        assert.match(panel.textContent, /Varför:/, 'Swedish panel states why it is needed');
    }
    assert.deepEqual(sv.errors, [], 'customer render must not raise jsdom errors');

    // 2. Toggle: clicking the ? reveals the panel and flips aria-expanded;
    //    clicking again hides it.
    const nameBtn = d.getElementById('sk-namn-why-btn');
    const namePanel = d.getElementById('sk-namn-why');
    nameBtn.click();
    assert.equal(namePanel.hidden, false, 'click must reveal the panel');
    assert.equal(nameBtn.getAttribute('aria-expanded'), 'true', 'click must expand the button');
    nameBtn.click();
    assert.equal(namePanel.hidden, true, 'second click must hide the panel');
    assert.equal(nameBtn.getAttribute('aria-expanded'), 'false', 'second click must collapse the button');

    // 3. Escape closes an open panel and returns focus to its button.
    d.getElementById('sk-regnr-why-btn').click();
    assert.equal(d.getElementById('sk-regnr-why').hidden, false, 'panel open before Escape');
    d.getElementById('sk-regnr-why-btn').dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
    assert.equal(d.getElementById('sk-regnr-why').hidden, true, 'Escape must close the panel');
    assert.equal(d.activeElement, d.getElementById('sk-regnr-why-btn'), 'focus must return to the button');

    // 4. English render: panels and accessible names switch language without
    //    leaking the Swedish wording.
    const enHtml = render(false, 'en');
    const en = load(enHtml, 'https://example.test/skadeblankett-kund.php?lang=en');
    assert.equal(en.d.querySelectorAll('.skade-help-btn').length, fieldIds.length, 'English keeps all buttons');
    const enPanel = en.d.getElementById('sk-namn-why');
    assert.match(enPanel.textContent, /What:/, 'English panel states what the field is');
    assert.match(enPanel.textContent, /Why:/, 'English panel states why it is needed');
    assert.doesNotMatch(enPanel.textContent, /Varför:/, 'Swedish wording must not leak into English');
    assert.equal(en.d.getElementById('sk-namn-why-btn').getAttribute('aria-label'), 'Why this field', 'English accessible name');
    assert.deepEqual(en.errors, [], 'English render must not raise jsdom errors');

    // 5. Internal/admin render: no help buttons, no panels.
    const adminHtml = render(true, 'sv');
    assert.equal((adminHtml.match(/class="skade-help-btn"/g) || []).length, 0, 'admin markup must not contain help buttons');
    assert.doesNotMatch(adminHtml, /class="skade-why" id=/, 'admin markup must not contain help panels');

    console.log('PASS: field-help buttons, toggle, Escape, English panels and admin absence.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
