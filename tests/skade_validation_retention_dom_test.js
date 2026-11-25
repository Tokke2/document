'use strict';
// Regression test: a forgotten required field must never clear the damage
// form. The field is marked with focus (the marker/caret), a red frame around
// the whole field and an error message, and every entered value is retained
// both for client-side validation and after a server-side 422 re-render.
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {JSDOM, VirtualConsole} = require('jsdom');

function render(post, serverError, internal, csrfFailed) {
    const encoded = Buffer.from(JSON.stringify(post || {})).toString('base64');
    const php = `require "tests/php/skade_customer_render_fixture.php";
$post = json_decode(base64_decode('${encoded}'), true);
echo base64_encode(render_customer_skade(false, ${internal ? 'true' : 'false'}, is_array($post) ? $post : [], ${serverError ? 'true' : 'false'}, ${csrfFailed ? 'true' : 'false'}));`;
    const out = execFileSync('npx', ['--yes', '@php-wasm/cli', '-r', php], {encoding: 'utf8'}).trim();
    return Buffer.from(out, 'base64').toString('utf8');
}

const filled = {
    namn_adress: 'Test Testsson\nTestgatan 1',
    person_orgnr: '19900101-1234',
    telefon: '0701234567',
    regnr: 'ABC123',
    modell: '', // forgotten required field
    forsakringsbolag: 'Testbolag',
    skadedatum: '2026-11-03',
};

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
                return { matches: false, media: '', addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} };
            };
            // The script tag for the signature pad is not fetched by jsdom.
            window.fetch = undefined;
        },
    });
    return { dom, d: dom.window.document, errors };
}

function assertMarkedAndRetained(d, label) {
    const namn = d.querySelector('#sk-namn');
    const modell = d.querySelector('#sk-modell');
    assert.equal(namn.value, filled.namn_adress, `${label}: entered name must be retained`);
    assert.equal(d.querySelector('#sk-personnr').value, filled.person_orgnr, `${label}: entered id number must be retained`);
    assert.equal(d.querySelector('#sk-regnr').value, 'ABC123', `${label}: registration number must be retained`);
    assert.equal(d.querySelector('#sk-forsakring').value, filled.forsakringsbolag, `${label}: insurance company must be retained`);
    assert.equal(modell.getAttribute('aria-invalid'), 'true', `${label}: forgotten field must be marked`);
    assert.equal(d.activeElement, modell, `${label}: marker/focus must move to the forgotten field`);
    const field = modell.closest('.skade-field');
    assert.ok(field.classList.contains('has-error'), `${label}: whole field must carry the red-frame class`);
    const error = d.getElementById('skade-error-sk-modell');
    assert.ok(error && !error.hidden && error.textContent.includes('Fyll i'), `${label}: field error text must be visible`);
    assert.equal(d.querySelector('#sk-namn').getAttribute('aria-invalid'), null, `${label}: valid fields must not be marked`);
}

// 1. Customer route: server rejected the submission (HTTP 422) because a
//    required field was forgotten. Values and marking must survive.
{
    const html = render({s: filled}, true, false);
    assert.match(html, /data-validation-error="1"/, 'server error must enable validation marking');
    // The red frame around the whole field is part of the rendered CSS.
    assert.match(html, /\.skade-field\.has-error\s*\{\s*outline:2px solid #d92d20/, 'red frame CSS must be rendered');
    const { d, errors } = load(html, 'https://example.test/skadeblankett-kund.php');
    assertMarkedAndRetained(d, 'customer/server-422');
    assert.equal(d.querySelector('#skade-sign-stage').hidden, true, 'customer must stay on the entry step');
    assert.deepEqual(errors, [], 'no jsdom errors');
}

// 2. Staff route: same forgotten field, same guarantee.
{
    const html = render({s: filled}, true, true);
    const { d, errors } = load(html, 'https://example.test/?tab=skadeblankett');
    assertMarkedAndRetained(d, 'staff/server-422');
    assert.match(html, /data-validation-error="1"/);
    assert.deepEqual(errors, [], 'no jsdom errors');
}

// 3. Client-side submit with a forgotten required field: submission is
//    blocked, nothing is cleared and the field gets focus + red frame.
{
    const html = render({}, false, false);
    const { dom, d, errors } = load(html, 'https://example.test/skadeblankett-kund.php');
    const set = (selector, value) => {
        const el = d.querySelector(selector);
        el.value = value;
        el.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
    };
    set('#sk-namn', filled.namn_adress);
    set('#sk-personnr', filled.person_orgnr);
    set('#sk-telefon', filled.telefon);
    set('#sk-regnr', 'ABC123');
    set('#sk-forsakring', filled.forsakringsbolag);
    set('#sk-datum', filled.skadedatum);
    // #sk-modell intentionally left empty.
    const form = d.querySelector('#skade-form');
    const event = new dom.window.Event('submit', { bubbles: true, cancelable: true });
    assert.equal(form.dispatchEvent(event), false, 'invalid form must not submit');
    assertMarkedAndRetained(d, 'customer/client-submit');
    assert.deepEqual(errors, [], 'no jsdom errors');
}

// 4. A CSRF failure must NOT remove the form. This used to render only an
//    error page telling the user to reload, which emptied every entered value.
{
    const html = render({s: filled}, false, false, true);
    assert.match(html, /id="skade-form"/, 'form must still render after a CSRF failure');
    assert.match(html, /Dina uppgifter är kvar i formuläret/, 'recovery message must promise retained values');
    assert.match(html, /name="skade_submission_token" value="/, 'a fresh submission token must be embedded for retry');
    const { d, errors } = load(html, 'https://example.test/skadeblankett-kund.php');
    assert.equal(d.querySelector('#sk-namn').value, filled.namn_adress, 'customer/server-CSRF: entered name must be retained');
    assert.equal(d.querySelector('#sk-modell').value, '', 'customer/server-CSRF: form state must be rendered');
    assert.equal(d.querySelector('#skade-form').getAttribute('data-validation-error'), '0');
    assert.deepEqual(errors, [], 'no jsdom errors');
}

// 5. Static guarantees: recovery messages must tell the user to retry without
//    reloading (reloading would empty the form), the damage-form tab must keep
//    rendering after a CSRF failure, and the customer PWA must never be
//    redirected away from the damage form during POST handling.
{
    const indexSource = require('node:fs').readFileSync('index.php', 'utf8');
    assert.match(indexSource, /Dina uppgifter är kvar i formuläret – tryck på Skicka igen/);
    assert.doesNotMatch(indexSource, /Öppna blanketten igen och försök\.', 'skade\.token_invalid'\)/);
    assert.match(indexSource, /\$portalCsrfFailed && \$activeTab !== 'skadeblankett'/);
    assert.match(indexSource, /\$portalCsrfFailed && !\$isSkadeCustomerMode/);
    assert.match(indexSource, /\$invoiceSetupState === 'unconfigured' && \$action !== 'invoice_first_admin_setup' &&\s*!\$portalIsScopedPwa/);
    const tabSource = require('node:fs').readFileSync('tabs/skadeblankett.php', 'utf8');
    assert.match(tabSource, /Säkerhetskontrollen misslyckades\. Dina uppgifter är kvar i formuläret/);
}

console.log('PASS: forgotten required fields get focus and a red frame while all entered values are retained (client and server paths).');
