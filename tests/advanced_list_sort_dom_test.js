'use strict';
// Real PHP-rendered list markup and production JS; no browser microphone involved.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { JSDOM, VirtualConsole } = require('jsdom');
const source = fs.readFileSync('assets/table_sort.js', 'utf8');
function render(php, marker) {
    const output = execFileSync('npx', ['--yes', '@php-wasm/cli', '-r', php], { encoding:'utf8', maxBuffer:16 * 1024 * 1024 });
    assert.ok(output.includes(marker), output.slice(-1000));
    return JSON.parse(Buffer.from(output.split(marker)[1], 'base64').toString());
}
const invoices = render(`require "tests/php/invoice_assistance_render_regression.php";
$GLOBALS['TEST_INVOICES']=[];
foreach ([['2','Anna','2026-02-02',100],['10','Anna','2026-02-02',500],['3','Anna','2026-01-03',50],['4','Bertil','2026-03-01',70],['5','<img src=x onerror=alert(1)>','',80]] as $r) $GLOBALS['TEST_INVOICES'][]=['id'=>'id'.$r[0],'nummer'=>$r[0],'customer'=>$r[1],'date'=>$r[2],'total_sum'=>$r[3],'fortnox_invoice_number'=>'F'.$r[0],'fortnox_invoice_date'=>$r[2]];
$out=[]; foreach([false,true] as $pwa) foreach(['sv','en'] as $lang) $out[]=render_invoice(false,$pwa,['invoice_view'=>'invoice_history'],$lang);
echo 'LIST_HTML:'.base64_encode(json_encode($out));`, 'LIST_HTML:');
const archives = render(`require "tests/php/files_archive_subtabs_regression.php";
$archiveFixtureFiles=[['rel'=>'service/Jobb2.pdf','name'=>'Jobb2.pdf','date'=>'2026-02-02 12:00','size'=>10],['rel'=>'service/Jobb10.pdf','name'=>'Jobb10.pdf','date'=>'2026-02-02 12:00','size'=>500],['rel'=>'service/Tidigare.pdf','name'=>'Tidigare.pdf','date'=>'2026-01-03 12:00','size'=>50],['rel'=>'fakturaunderlag/Faktura.pdf','name'=>'Faktura.pdf','date'=>'2026-03-01 12:00','size'=>70]];
$out=[]; foreach(['sv','en'] as $portalLanguage) { $_GET=['sub'=>'all']; $_POST=[]; $_FILES=[]; ob_start(); include 'tabs/files.php'; $out[]=ob_get_clean(); }
echo 'ARCHIVE_HTML:'.base64_encode(json_encode($out));`, 'ARCHIVE_HTML:');
function setup(html) {
    const errors = [], vc = new VirtualConsole(); vc.on('jsdomError', e => errors.push(e));
    const dom = new JSDOM(html, { url:'https://example.test/pwa-underlag.php', runScripts:'outside-only', virtualConsole:vc });
    const w = dom.window, d = w.document;
    w.eval(source); w.portalTableSort.attachAll();
    for (const script of d.querySelectorAll('script:not([src])')) w.eval(script.textContent);
    const table = d.querySelector('[data-sort-advanced]');
    const controls = d.querySelector('.portal-list-controls');
    assert.ok(table && controls);
    assert.equal(table.closest('.file-table-wrap,.invoice-record-table-wrap').contains(controls), false, 'Controls stay outside horizontal scroll');
    const choose = (key, value) => { const control = controls.querySelector('[data-list-control="' + key + '"]'); control.value = value; control.dispatchEvent(new w.Event('change', { bubbles:true })); };
    const rows = () => Array.from(table.tBodies[0].rows).filter(row => !row.hasAttribute('data-sort-group'));
    const visible = () => rows().filter(row => !row.hidden);
    const groups = () => Array.from(table.querySelectorAll('[data-sort-group]'));
    const input = (selector, value) => { const field = d.querySelector(selector); field.value = value; field.dispatchEvent(new w.Event('input', { bubbles:true })); };
    return { w, d, dom, table, controls, choose, rows, visible, groups, input, errors };
}
for (const [i, html] of invoices.entries()) {
    const c = setup(html), { choose, table, rows, groups } = c;
    assert.equal(c.controls.querySelector('label').textContent, i % 2 ? 'Sort first by' : 'Sortera först på');
    const numbers = () => rows().map(row => row.dataset.number);
    choose('primary', 'customer'); choose('primary-direction', 'ascending'); choose('secondary', 'date'); choose('secondary-direction', 'descending');
    assert.deepEqual(numbers(), ['5','2','10','3','4'], 'Customer then date, stable ties');
    choose('secondary', 'total'); choose('secondary-direction', 'descending');
    assert.deepEqual(numbers(), ['5','10','2','3','4'], 'Second sort can use raw numeric totals');
    choose('primary', 'number');
    assert.deepEqual(numbers(), ['2','3','4','5','10'], 'Natural numbers, not lexical 10 before 2');
    assert.equal(table.querySelector('[data-sort-key="number"]').getAttribute('aria-sort'), 'ascending');
    table.querySelector('[data-sort-key="number"] button').click();
    assert.deepEqual(numbers(), ['10','5','4','3','2'], 'Header clicks synchronize mobile controls');
    assert.equal(c.controls.querySelector('[data-list-control="primary-direction"]').value, 'descending');
    choose('secondary', 'number');
    assert.equal(c.controls.querySelector('[data-list-control="secondary"]').value, '', 'No duplicate sorting rule');
    choose('primary', 'date'); choose('primary-direction', 'descending');
    choose('group', 'customer');
    assert.equal(groups().length, 3);
    assert.ok(groups().some(row => row.textContent.includes('<img src=x onerror=alert(1)>')));
    assert.equal(table.querySelectorAll('img').length, 0, 'Group names are text, never interpreted as markup');
    let anna = groups().find(row => row.dataset.sortGroup === 'Anna');
    anna.querySelector('button').click();
    assert.equal(anna.querySelector('button').getAttribute('aria-expanded'), 'false');
    assert.equal(c.visible().some(row => row.dataset.customer === 'Anna'), false);
    c.input('#invoice-history-search', 'Anna');
    assert.equal(c.visible().length, 0, 'Filtering does not reopen a deliberately collapsed group');
    anna = groups().find(row => row.dataset.sortGroup === 'Anna');
    assert.match(anna.textContent, /Anna \(3\)/);
    assert.equal(groups().filter(row => !row.hidden).length, 1, 'Empty filtered groups are hidden');
    anna.querySelector('button').click(); assert.equal(c.visible().length, 3);
    c.input('#invoice-history-search', 'nothing matches');
    assert.equal(groups().filter(row => !row.hidden).length, 0); assert.equal(c.visible().length, 0);
    c.input('#invoice-history-search', '');
    choose('group', 'month');
    assert.deepEqual(groups().map(row => row.dataset.sortGroup), ['2026-03','2026-02','2026-01','']);
    assert.match(groups().at(-1).textContent, i % 2 ? /Not specified/ : /Uppgift saknas/);
    assert.equal(c.visible().length, 5);
    choose('group', ''); assert.equal(groups().length, 0); assert.equal(rows().length, 5);
    assert.ok(rows().every(row => row.querySelector('a[href*="edit_id="]')), 'Row actions survive reorder and grouping');
    c.w.portalTableSort.attach(table);
    assert.equal(c.d.querySelectorAll('.portal-list-controls').length, 1, 'Attach remains idempotent');
    assert.deepEqual(c.errors, []); c.dom.window.close();
}
for (const html of archives) {
    const c = setup(html), { choose, rows, groups } = c;
    choose('primary', 'folder'); choose('primary-direction', 'ascending'); choose('secondary', 'size'); choose('secondary-direction', 'descending');
    assert.deepEqual(rows().filter(row => row.dataset.folder === 'service').map(row => row.dataset.name), ['Jobb10.pdf','Tidigare.pdf','Jobb2.pdf']);
    choose('group', 'folder');
    let service = groups().find(row => row.dataset.sortGroup === 'service');
    assert.ok(service); service.querySelector('button').click();
    c.input('#file-search', 'Jobb');
    service = groups().find(row => row.dataset.sortGroup === 'service');
    assert.match(service.textContent, /\(2\)/); service.querySelector('button').click();
    assert.equal(c.visible().length, 2, 'Opening group never exposes filtered-out files');
    c.input('#file-date-from', '2026-04-01');
    assert.equal(c.visible().length, 0); assert.equal(groups().filter(row => !row.hidden).length, 0);
    c.d.querySelector('#file-clear-filters').click();
    assert.equal(c.visible().length, 4);
    choose('group', 'month'); assert.deepEqual(groups().map(row => row.dataset.sortGroup), ['2026-03','2026-02','2026-01']);
    assert.equal(c.d.querySelector('#file-view-count').textContent, '4', 'Counters count matching files, never synthetic headings');
    choose('group', ''); assert.equal(rows().length, 4);
    assert.ok(rows().every(row => row.querySelector('a[href*="view_file="]') && row.querySelector('a[href*="download_file="]')));
    assert.deepEqual(c.errors, []); c.dom.window.close();
}
assert.match(fs.readFileSync('assets/portal.css', 'utf8'), /tr\[data-sort-collapsed\]:not\(\[data-sort-filtered="1"\]\)/, 'Print reveals collapsed matches without exposing filtered records');
console.log('PASS: multi-field sorting, synchronized mobile/header controls, collapsible customer/month/folder groups, filters/counts/actions, stable ties, safe labels and idempotence (real invoice portal/PWA and archive, sv/en).');
