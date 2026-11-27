'use strict';
// Rebuild every raster icon from assets/tadega-icon.svg so the logo stays
// identical and sharp on all surfaces (PWA manifests, apple-touch, favicon,
// offline page, print PNG). Run: node tools/build-icons.js
const fs = require('node:fs');
const path = require('node:path');
const sharp = require('sharp');

const root = path.resolve(__dirname, '..');
const assets = path.join(root, 'assets');
const source = fs.readFileSync(path.join(assets, 'tadega-icon.svg'));

const targets = [
    ['pwa-icon-192.png', 192],
    ['pwa-icon-512.png', 512],
    ['apple-touch-icon.png', 180],
    ['portal-icon.png', 512],
    ['tadega-logo-fpdf.png', 480], // print/PDF-quality raster of the mark
];

function pngBuffer(size) {
    return sharp(source, { density: 600 })
        .resize(size, size, { fit: 'fill' })
        .png({ compressionLevel: 9, palette: false })
        .toBuffer();
}

function icoFromPngs(entries) {
    // ICO container with embedded PNGs (supported by all modern browsers).
    const header = Buffer.alloc(6 + entries.length * 16);
    header.writeUInt16LE(0, 0); // reserved
    header.writeUInt16LE(1, 2); // type: icon
    header.writeUInt16LE(entries.length, 4);
    let offset = header.length;
    entries.forEach((entry, index) => {
        const base = 6 + index * 16;
        header.writeUInt8(entry.size >= 256 ? 0 : entry.size, base);
        header.writeUInt8(entry.size >= 256 ? 0 : entry.size, base + 1);
        header.writeUInt8(0, base + 2);
        header.writeUInt8(0, base + 3);
        header.writeUInt16LE(1, base + 4); // planes
        header.writeUInt16LE(32, base + 6); // bit count
        header.writeUInt32LE(entry.data.length, base + 8);
        header.writeUInt32LE(offset, base + 12);
        offset += entry.data.length;
    });
    return Buffer.concat([header, ...entries.map((entry) => entry.data)]);
}

(async () => {
    for (const [name, size] of targets) {
        const data = await pngBuffer(size);
        fs.writeFileSync(path.join(assets, name), data);
        console.log(`assets/${name} ${size}x${size} ${data.length} B`);
    }
    const faviconEntries = [];
    for (const size of [16, 32, 48, 256]) {
        faviconEntries.push({ size, data: await pngBuffer(size) });
    }
    const ico = icoFromPngs(faviconEntries);
    fs.writeFileSync(path.join(root, 'favicon.ico'), ico);
    console.log(`favicon.ico ${ico.length} B`);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
