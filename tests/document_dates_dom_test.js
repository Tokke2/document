'use strict';
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { JSDOM, VirtualConsole } = require('jsdom');
function php(code) {
    return execFileSync('npx', ['--yes', '@php-wasm/cli', '-r', code], { encoding: 'utf8' });
}
for (const scoped of [false, true]) {
    const code = 'require "tests/php/invoice_assistance_render_regression.php"; $GLOBALS["TEST_INVOICES"]=[["id"=>"dated-invoice","date"=>"2026-01-03","delivery_date"=>"2026-01-02","customer"=>"Test"]]; echo "DATE_HTML:".base64_encode(render_invoice(false,' + (scoped ? 'true' : 'false') + ',["edit_id"=>"dated-invoice"]));';
    const html = Buffer.from(php(code).split('DATE_HTML:')[1], 'base64').toString('utf8');
    const errors = [];
    const vc = new VirtualConsole(); vc.on('jsdomError', e => errors.push(e));
    const dom = new JSDOM(html, { url: 'https://example.test/pwa-underlag.php', runScripts: 'outside-only', virtualConsole: vc });
    const w = dom.window, d = w.document;
    w.matchMedia = () => ({ matches: false, addListener() {}, addEventListener() {} });
    for (const script of d.querySelectorAll('script:not([src])')) w.eval(script.textContent);
    assert.equal(d.querySelector('#invoice-date').value, '2026-01-03');
    const delivery = d.querySelector('#invoice-delivery-date');
    assert.equal(delivery.value, '2026-01-02', 'Reopening restores delivery date');
    assert.equal(delivery.required, false);
    assert.equal(delivery.disabled, false);
    delivery.value = '2026-03-04';
    delivery.dispatchEvent(new w.Event('input', { bubbles: true }));
    assert.match(d.querySelector('#invoice-review-delivery-date').textContent, /2026/);
    assert.equal(new w.FormData(d.querySelector('#invoice-form')).get('i[delivery_date]'), '2026-03-04');
    assert.equal(d.querySelector('#invoice-date').value, '2026-01-03', 'Delivery date is independent of record date');
    delivery.value = ''; delivery.dispatchEvent(new w.Event('input', { bubbles: true }));
    assert.match(d.querySelector('#invoice-review-delivery-date').textContent, /Inte angivet/);
    assert.deepEqual(errors, []);
    dom.window.close();
}
const skade = php('require "tests/php/skade_customer_render_fixture.php"; echo json_encode([render_customer_skade(),render_customer_skade(false,true,["s"=>["signature_date"=>"2020-04-03"]])]);');
const [customer, staff] = JSON.parse(skade).map(html => new JSDOM(html).window.document);
assert.equal(customer.querySelector('[name="s[signature_date]"]'), null, 'Customer cannot choose signed date');
assert.equal(staff.querySelector('[name="s[signature_date]"]').value, '2020-04-03', 'Staff can choose a historical signed date');
console.log('PASS: selectable staff signed date; customer date locked; invoice delivery edit, review and submitted value in portal and PWA.');
