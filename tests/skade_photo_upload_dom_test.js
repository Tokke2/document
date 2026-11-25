'use strict';
// Regression test for the two optional skadeblankett photos (damage + front
// with visible registration plate): markup wiring, retention after a server
// 422, data-invalid-photos marking, client-side bad-type handling, the stubbed
// capture pipeline (FileReader → Image → canvas), review status and progress.
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {JSDOM, VirtualConsole} = require('jsdom');

function render(post, serverError, internal, invalidPhotos, language) {
    const post64 = Buffer.from(JSON.stringify(post || {})).toString('base64');
    const invalid64 = Buffer.from(JSON.stringify(invalidPhotos || [])).toString('base64');
    const lang = language === 'en' ? 'en' : 'sv';
    const php = `require "tests/php/skade_customer_render_fixture.php";
$post = json_decode(base64_decode('${post64}'), true);
$invalidPhotos = json_decode(base64_decode('${invalid64}'), true);
echo base64_encode(render_customer_skade(false, ${internal ? 'true' : 'false'}, is_array($post) ? $post : [], ${serverError ? 'true' : 'false'}, false, is_array($invalidPhotos) ? $invalidPhotos : [], '${lang}'));`;
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
            // The script tag for the signature pad is not fetched by jsdom.
            window.fetch = undefined;
            // jsdom has no image decoder or canvas backend: stub both so the
            // capture pipeline (new Image() + canvas.toDataURL) can run.
            window.Image = class {
                set src(value) {
                    this._src = value;
                    this.naturalWidth = 800;
                    this.naturalHeight = 600;
                    queueMicrotask(() => { if (typeof this.onload === 'function') this.onload(); });
                }
                get src() { return this._src; }
            };
            window.HTMLCanvasElement.prototype.getContext = function () {
                return {drawImage() {}};
            };
            window.HTMLCanvasElement.prototype.toDataURL = function () {
                return 'data:image/jpeg;base64,' + 'A'.repeat(1000);
            };
        },
    });
    return {dom, d: dom.window.document, errors, w: dom.window};
}

const dataUri = 'data:image/jpeg;base64,' + 'A'.repeat(200);
const filled = {
    namn_adress: 'Test Testsson\nTestgatan 1',
    person_orgnr: '19900101-1234',
    telefon: '0701234567',
    regnr: 'ABC123',
    modell: 'Testbil',
    forsakringsbolag: 'Testbolag',
    skadedatum: '2026-11-03',
};

const set = (w, d, id, value) => {
    const field = d.getElementById(id);
    field.value = value;
    field.dispatchEvent(new w.Event('input', {bubbles: true}));
    return field;
};

(async () => {
    // 1. Markup: hidden data-URI inputs mirror the signature input, file inputs
    //    are optional and carry target/key hooks, section joins the entry step.
    const html = render({}, false, false);
    assert.match(html, /data-invalid-photos="/, 'form must expose data-invalid-photos');
    assert.match(html, /name="form_version" value="skade-customer-v4-photos"/, 'form_version must be v4');
    assert.match(html, /<section class="skade-card" aria-labelledby="skade-photos-heading"[^>]*data-skade-step="entry"/, 'photo section must join the customer entry step');
    assert.match(html, /<input type="file" id="sk-photo-damage" accept="image\/jpeg,image\/png"[^>]*data-photo-target="sk-photo-damage-value"[^>]*data-photo-key="photo_damage"/, 'damage file input must wire target and key');
    assert.match(html, /<input type="file" id="sk-photo-front" accept="image\/jpeg,image\/png"[^>]*data-photo-target="sk-photo-front-value"[^>]*data-photo-key="photo_front"/, 'front file input must wire target and key');
    assert.match(html, /<input type="hidden" name="s\[photo_damage\]" id="sk-photo-damage-value"/, 'damage hidden input must post s[photo_damage]');
    assert.match(html, /<input type="hidden" name="s\[photo_front\]" id="sk-photo-front-value"/, 'front hidden input must post s[photo_front]');
    assert.doesNotMatch(html, /id="sk-photo-damage"[^>]*\srequired/, 'damage file input must not be required');
    assert.doesNotMatch(html, /id="sk-photo-front"[^>]*\srequired/, 'front file input must not be required');
    assert.doesNotMatch(html, /id="sk-photo-damage"[^>]*\sname="/, 'file inputs must not post directly (no name)');

    // 2. Retention: a server-422 re-render keeps both photo values in the
    //    hidden inputs and rehydrates the previews.
    const retained = render({s: Object.assign({}, filled, {photo_damage: dataUri, photo_front: dataUri})}, true, false);
    const retention = load(retained, 'https://example.test/skadeblankett-kund.php');
    assert.equal(retention.d.getElementById('sk-photo-damage-value').value, dataUri, 'server re-render must retain the damage photo');
    assert.equal(retention.d.getElementById('sk-photo-front-value').value, dataUri, 'server re-render must retain the front photo');
    const damagePreview = retention.d.getElementById('sk-photo-damage-preview');
    assert.equal(damagePreview.hidden, false, 'retained photo must show its preview');
    assert.equal(damagePreview.querySelector('img').getAttribute('src'), dataUri, 'preview must show the retained photo');
    assert.equal(retention.errors.length, 0, 'retention render must not raise jsdom errors');

    // 3. Server-side photo rejection: data-invalid-photos marks the file input
    //    even though the retained value passes the client format checks, and
    //    the entered data stays.
    const rejected = render({s: Object.assign({}, filled, {photo_damage: dataUri})}, true, false, ['photo_damage']);
    assert.match(rejected, /data-invalid-photos="photo_damage"/, 'rejected photo key must reach the form attribute');
    const rejection = load(rejected, 'https://example.test/skadeblankett-kund.php');
    const damageInput = rejection.d.getElementById('sk-photo-damage');
    assert.equal(damageInput.getAttribute('aria-invalid'), 'true', 'server-rejected photo must be marked');
    const damageError = rejection.d.getElementById('skade-error-sk-photo-damage');
    assert.ok(damageError && !damageError.hidden && /Bilden kunde inte användas/.test(damageError.textContent), 'photo error text must be visible');
    assert.equal(rejection.d.getElementById('sk-photo-damage-value').value, dataUri, 'rejected photo value must be retained for correction');
    assert.equal(rejection.d.getElementById('sk-namn').value, filled.namn_adress, 'other fields must survive the photo rejection');
    assert.deepEqual(rejection.errors, [], 'rejection render must not raise jsdom errors');

    // 4. Client-side bad type: the file selection is cleared, the previous
    //    photo stays, and the field is marked once validation has run.
    const badType = load(rejected, 'https://example.test/skadeblankett-kund.php');
    const {w, d} = badType;
    const textFile = new w.File(['not an image'], 'notes.txt', {type: 'text/plain'});
    Object.defineProperty(damageInput, 'files', {value: [textFile], configurable: true});
    damageInput.dispatchEvent(new w.Event('change', {bubbles: true}));
    assert.equal(d.getElementById('sk-photo-damage-value').value, dataUri, 'a bad file must not discard the previous photo');
    assert.equal(damageInput.value, '', 'the bad file selection must be cleared');
    assert.equal(damageInput.getAttribute('aria-invalid'), 'true', 'the photo field must stay marked');
    assert.match(d.getElementById('skade-error-sk-photo-damage').textContent, /JPG eller PNG/, 'bad type must explain the accepted formats');

    // 5. Capture pipeline (stubbed FileReader/Image/canvas): a real selection
    //    lands in the hidden input and the review reports "Bild tillagd".
    const fresh = load(render({}, false, false), 'https://example.test/skadeblankett-kund.php');
    const fw = fresh.w, fd = fresh.d;
    assert.equal(fw.document.getElementById('sk-photo-damage-preview').hidden, true, 'no photo means no preview');
    Object.defineProperty(fd.getElementById('sk-photo-damage'), 'files', {
        value: [new fw.File(['jpeg-bytes'], 'damage.jpg', {type: 'image/jpeg'})],
        configurable: true,
    });
    fd.getElementById('sk-photo-damage').dispatchEvent(new fw.Event('change', {bubbles: true}));
    await new Promise((resolve) => setTimeout(resolve, 100));
    const capturedValue = fd.getElementById('sk-photo-damage-value').value;
    assert.match(capturedValue, /^data:image\/jpeg;base64,/, 'capture must store a JPEG data URI');
    assert.equal(fd.getElementById('sk-photo-damage-preview').hidden, false, 'captured photo must show its preview');
    assert.equal(fd.getElementById('sk-photo-damage').getAttribute('aria-invalid'), null, 'successful capture must clear any marking');
    assert.deepEqual(fresh.errors, [], 'capture must not raise jsdom errors');
    // Review status goes to #skade-review-extra; the short list stays at 3.
    set(fw, fd, 'sk-namn', filled.namn_adress);
    set(fw, fd, 'sk-personnr', filled.person_orgnr);
    set(fw, fd, 'sk-telefon', filled.telefon);
    set(fw, fd, 'sk-regnr', filled.regnr);
    set(fw, fd, 'sk-modell', filled.modell);
    set(fw, fd, 'sk-forsakring', filled.forsakringsbolag);
    set(fw, fd, 'sk-datum', filled.skadedatum);
    fd.getElementById('skade-review-open').click();
    assert.equal(fd.getElementById('skade-review').hidden, false, 'review must open with a photo attached');
    assert.equal(fd.getElementById('skade-review-list').querySelectorAll('.skade-review-item').length, 3, 'the short review list must keep exactly 3 items');
    assert.match(fd.getElementById('skade-review-extra').textContent, /Bild tillagd/, 'review must report the attached photo');
    // Remove button clears photo, preview and marking.
    fd.querySelector('[data-photo-remove="sk-photo-damage"]').click();
    assert.equal(fd.getElementById('sk-photo-damage-value').value, '', 'remove must clear the photo');
    assert.equal(fd.getElementById('sk-photo-damage-preview').hidden, true, 'remove must hide the preview');
    fd.getElementById('skade-review-back').click();
    assert.match(fd.getElementById('skade-review-extra').textContent, /Inge bild/, 'review must report the removed photo');

    // 6. Progress: empty photos never block 100 %; a broken optional photo
    //    caps it at 99 % with the optional-fields hint.
    const progress = load(render({}, false, false), 'https://example.test/skadeblankett-kund.php');
    const pw = progress.w, pd = progress.d;
    set(pw, pd, 'sk-namn', filled.namn_adress);
    set(pw, pd, 'sk-personnr', filled.person_orgnr);
    set(pw, pd, 'sk-telefon', filled.telefon);
    set(pw, pd, 'sk-regnr', filled.regnr);
    set(pw, pd, 'sk-modell', filled.modell);
    set(pw, pd, 'sk-forsakring', filled.forsakringsbolag);
    set(pw, pd, 'sk-datum', filled.skadedatum);
    set(pw, pd, 'skade-signature-png', 'data:image/png;base64,TEST');
    const confirmed = pd.getElementById('skade-signature-confirmed');
    confirmed.checked = true;
    confirmed.dispatchEvent(new pw.Event('change', {bubbles: true}));
    const track = pd.getElementById('skade-progress-track');
    assert.equal(track.getAttribute('aria-valuenow'), '100', 'empty optional photos must not block 100 %');
    assert.doesNotMatch(pd.getElementById('skade-progress-text').textContent, /Kontrollera valfria fält/, 'no optional hint with clean photos');
    // A broken optional photo caps progress at 99 % with the optional hint.
    // Re-dispatch on the signature input (outside the entry step) so the
    // update runs without legitimately unchecking the confirmation.
    const progressInput = pd.getElementById('sk-photo-damage');
    progressInput.dataset.photoError = 'Bilden kunde inte användas. Välj en JPG eller PNG på 200–4000 pixlar och högst 550 kB.';
    set(pw, pd, 'skade-signature-png', 'data:image/png;base64,TEST');
    assert.equal(track.getAttribute('aria-valuenow'), '99', 'a broken optional photo must cap progress at 99 %');
    assert.match(pd.getElementById('skade-progress-text').textContent, /Kontrollera valfria fält/, 'progress must hint at the optional photo');
    assert.deepEqual(progress.errors, [], 'progress checks must not raise jsdom errors');

    // 7. English language: photo section labels, help texts, remove button,
    //    server-rejection photo error and required-field validation render in
    //    English; the Swedish strings must not leak into the English page.
    const english = render({}, false, false, [], 'en');
    assert.match(english, /Damage photo/, 'English label for the damage photo');
    assert.match(english, /Photo of the vehicle front where the registration number is visible/, 'English label for the front photo');
    assert.match(english, /\(optional\)/, 'English optional hint');
    assert.match(english, /JPG or PNG, max 20 MB\. The image is scaled down and stored with the form\./, 'English help text for the damage photo');
    assert.match(english, /JPG or PNG, max 20 MB\. The registration number must be visible in the photo\./, 'English help text for the front photo');
    assert.match(english, /Remove photo/, 'English remove button');
    assert.doesNotMatch(english, />Bild på skada</, 'Swedish damage label must not appear in English mode');
    assert.doesNotMatch(english, />Ta bort bild</, 'Swedish remove button must not appear in English mode');
    // Server rejection + empty required fields in English.
    const engRejected = render({s: Object.assign({}, filled, {photo_damage: dataUri})}, true, false, ['photo_damage'], 'en');
    const engDom = load(engRejected, 'https://example.test/skadeblankett-kund.php?lang=en');
    const engPhotoError = engDom.d.getElementById('skade-error-sk-photo-damage');
    assert.ok(engPhotoError && /The photo could not be used\. Choose a JPG or PNG of 200–4000 pixels and at most 550 kB\./.test(engPhotoError.textContent), 'English photo rejection message must show');
    assert.deepEqual(engDom.errors, [], 'English rejection render must not raise jsdom errors');
    // Empty required fields in English (separate render without filled data).
    const engEmpty = render({}, true, false, [], 'en');
    const engEmptyDom = load(engEmpty, 'https://example.test/skadeblankett-kund.php?lang=en');
    const engNameError = engEmptyDom.d.getElementById('skade-error-sk-namn');
    assert.ok(engNameError && /Fill in this field\./.test(engNameError.textContent), 'English required-field message must show');
    assert.doesNotMatch(engNameError.textContent, /Fyll i det här fältet/, 'Swedish validation message must not appear in English mode');
    assert.deepEqual(engEmptyDom.errors, [], 'English empty render must not raise jsdom errors');

    console.log('PASS: photo markup, retention, server rejection, bad type, capture pipeline, review status, progress and English language.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
