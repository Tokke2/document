'use strict';
// Brand contract: one canonical windshield/bolt across all installation and
// download surfaces. No browser/font required; run: node tests/brand_assets_test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const sharp = require('sharp');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const icon = read('assets/tadega-icon.svg');
const inner = icon.replace(/^<svg\b[^>]*>/, '').replace(/<\/svg>\s*$/, '').trim();

(async () => {
    for (const id of ['windshield-frame', 'windshield-glass', 'lightning']) {
        assert.ok(icon.includes(`id="${id}"`), `Canonical mark contains ${id}`);
    }
    for (const name of ['tadega-logo.svg', 'tadega-logo-tryck.svg']) {
        assert.ok(read(`assets/${name}`).includes(inner), `${name} embeds the exact canonical mark`);
    }
    for (const [name, size] of [
        ['pwa-icon-192.png', 192], ['pwa-icon-512.png', 512],
        ['apple-touch-icon.png', 180], ['portal-icon.png', 512], ['tadega-logo-fpdf.png', 480],
    ]) {
        const file = path.join(root, 'assets', name);
        const meta = await sharp(file).metadata();
        assert.equal(meta.width, size, `${name} width`);
        assert.equal(meta.height, size, `${name} height`);
        const expected = await sharp(Buffer.from(icon), { density: 600 }).resize(size, size, { fit: 'fill' }).raw().toBuffer();
        const actual = await sharp(file).raw().toBuffer();
        assert.deepEqual(actual, expected, `${name} matches the canonical SVG, not an outdated logo`);
    }
    const ico = fs.readFileSync(path.join(root, 'favicon.ico'));
    assert.equal(ico.readUInt16LE(2), 1);
    assert.equal(ico.readUInt16LE(4), 4);
    for (const [index, size] of [16, 32, 48, 256].entries()) {
        const base = 6 + index * 16;
        assert.equal(ico[base] || 256, size, 'Favicon directory includes expected size');
        const length = ico.readUInt32LE(base + 8), offset = ico.readUInt32LE(base + 12);
        const pixels = await sharp(ico.subarray(offset, offset + length)).raw().toBuffer();
        const expected = await sharp(Buffer.from(icon), { density: 600 }).resize(size, size, { fit: 'fill' }).raw().toBuffer();
        assert.deepEqual(pixels, expected, `Favicon ${size}px uses the same mark`);
    }
    for (const file of fs.readdirSync(root).filter(p => /^manifest.*\.webmanifest$/.test(p))) {
        const manifest = JSON.parse(read(file));
        for (const entry of [...manifest.icons, ...(manifest.shortcuts || []).flatMap(s => s.icons || [])]) {
            const url = new URL(entry.src, 'https://portal.example/');
            const asset = url.searchParams.get('view_file');
            assert.match(asset, /^assets\/pwa-icon-(192|512)\.png$/);
            assert.ok(fs.existsSync(path.join(root, asset)), `${file} references an existing icon`);
            assert.equal(url.searchParams.get('v'), 'windshield-1', `${file} refreshes old installation icons`);
        }
    }
    const index = read('index.php');
    assert.ok(index.includes('width="60" height="60"'), 'Header reserves full logo space');
    assert.match(index, /<img class="brand-icon"[^>]*>[\s\S]*?<\?php if \(!\$portalIsScopedPwa && \$portalCompanyLogo/, 'Shared mark remains visible even with a custom company logo');
    const css = read('assets/portal.css');
    assert.match(css, /\.app-header \.brand-icon\s*\{\s*width:60px; height:60px; flex:0 0 60px/);
    assert.match(css, /\.app-header \.brand-icon\s*\{ width:48px; height:48px; flex-basis:48px/);
    const customer = read('skadeblankett-kund.php');
    assert.match(customer, /\$customerPublicAssets = \[\s*'assets\/tadega-icon.svg'/, 'Only the shared SVG mark is added to customer public assets');
    assert.match(read('tabs/skadeblankett.php'), /class="skade-brand-mark" src="\.\/skadeblankett-kund.php\?view_file=assets\/tadega-icon.svg/);
    console.log('PASS: shared windshield/bolt vectors, all PNG/ICO sizes, five manifests and prominent header/customer branding.');
})().catch(error => { console.error(error); process.exitCode = 1; });
