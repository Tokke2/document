'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');

const source = fs.readFileSync('assets/table_sort.js', 'utf8');
const html = `<!doctype html><html><body>
<table class="file-table" data-sortable>
  <thead><tr>
    <th data-sort-key="folder" data-sort-type="text" aria-sort="none"><button type="button" class="file-sort-button">Mapp</button></th>
    <th data-sort-key="date" data-sort-type="date" aria-sort="none"><button type="button" class="file-sort-button">Datum</button></th>
    <th data-sort-key="size" data-sort-type="number" aria-sort="none"><button type="button" class="file-sort-button">Storlek</button></th>
  </tr></thead>
  <tbody>
    <tr class="file-row" data-folder="B" data-date="2026-01-05" data-size="10.5"></tr>
    <tr class="file-row" data-folder="A" data-date="2026-03-01" data-size="2048"></tr>
    <tr class="file-row" data-folder="C" data-date="2026-01-05" data-size="3"></tr>
  </tbody>
</table>
</body></html>`;

async function main() {
    const dom = new JSDOM(html, { url: 'https://example.test/files', runScripts: 'outside-only' });
    const { window } = dom;
    window.eval(source);
    const table = window.document.querySelector('table');
    if (table.getAttribute('data-sortable-attached') !== '1') {
        await new Promise((resolve) => window.addEventListener('load', resolve, { once: true }));
    }

    const rows = () => Array.from(table.tBodies[0].rows).map((row) => row.getAttribute('data-folder'));
    const header = (key) => table.querySelector(`th[data-sort-key="${key}"]`);
    const click = (key) => header(key).querySelector('button').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

    assert.equal(table.getAttribute('data-sortable-attached'), '1', 'Table auto-attaches');
    window.portalTableSort.attach(table);
    assert.equal(table.getAttribute('data-sortable-attached'), '1', 'Second attach is a no-op');

    // Numeric column: first click sorts descending (largest first).
    click('size');
    assert.deepEqual(rows(), ['A', 'B', 'C'], 'Size sorts descending with Swedish number parsing');
    assert.equal(header('size').getAttribute('aria-sort'), 'descending');
    assert.equal(header('folder').getAttribute('aria-sort'), 'none', 'Other headers reset');

    click('size');
    assert.deepEqual(rows(), ['C', 'B', 'A'], 'Second click toggles to ascending');
    assert.equal(header('size').getAttribute('aria-sort'), 'ascending');

    // Date column defaults to newest first; equal dates keep the order they
    // had in the previous sort (stability relative to the current view).
    click('date');
    assert.deepEqual(rows(), ['A', 'C', 'B'], 'Dates sort newest first and ties stay stable');
    assert.equal(header('date').getAttribute('aria-sort'), 'descending');

    // Text column sorts ascending on first click using Swedish collation.
    click('folder');
    assert.deepEqual(rows(), ['A', 'B', 'C'], 'Folder names sort A–C');
    assert.equal(header('folder').getAttribute('aria-sort'), 'ascending');
    assert.equal(header('date').getAttribute('aria-sort'), 'none', 'Previous sort indicator cleared');

    console.log('PASS: sortable table columns (folder/date/size, aria-sort, stable ties).');
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
