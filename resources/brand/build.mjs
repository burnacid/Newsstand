// Generates Newsstand brand assets for "The stack" mark.
// Usage: node build.mjs <font.woff> <public dir> <preview dir> <blade components dir>
import fs from 'node:fs';
import path from 'node:path';
import opentype from 'opentype.js';
import { Resvg } from '@resvg/resvg-js';

const [fontPath, publicDir, previewDir, bladeDir] = process.argv.slice(2);
const brandDir = path.join(publicDir, 'brand');
fs.mkdirSync(brandDir, { recursive: true });
fs.mkdirSync(previewDir, { recursive: true });

const C = {
    e300: '#6ee7b7', e500: '#10b981', e600: '#059669', e700: '#047857', e800: '#065f46', e950: '#022c22',
    ink: '#1b1814', paper: '#fdfcf9', white: '#ffffff',
};

const fontBuf = fs.readFileSync(fontPath);
const font = opentype.parse(fontBuf.buffer.slice(fontBuf.byteOffset, fontBuf.byteOffset + fontBuf.byteLength));
const capHeight = font.tables.os2.sCapHeight / font.unitsPerEm;

// --- The mark: three stacked papers, serif N on the front sheet (64-unit box) ---
const N = (() => {
    const size = (64 * 0.4) / capHeight;
    const p = font.getPath('N', 0, 0, size);
    const bb = p.getBoundingBox();
    // Center on the front sheet (x 0..50, y 14..64 => center 25,39).
    const x = 25 - (bb.x1 + bb.x2) / 2;
    const y = 39 - (bb.y1 + bb.y2) / 2;
    return { d: p.toPathData(2), x: +x.toFixed(2), y: +y.toFixed(2) };
})();

const THEMES = {
    light: { back2: C.e300, back1: C.e500, front: C.e700, n: C.white },
    dark: { back2: C.e800, back1: C.e600, front: C.e500, n: C.e950 },
};

const SHEETS = [
    ['back2', '<rect x="14" y="0" width="50" height="50" rx="11"/>'],
    ['back1', '<rect x="7" y="7" width="50" height="50" rx="11"/>'],
    ['front', '<rect x="0" y="14" width="50" height="50" rx="11"/>'],
];
const nShape = `<path transform="translate(${N.x} ${N.y})" d="${N.d}"/>`;

const mark = ({ x = 0, y = 0, size = 64, theme = 'light' } = {}) => {
    const t = THEMES[theme];
    return `<g transform="translate(${x} ${y}) scale(${size / 64})">`
        + SHEETS.map(([k, el]) => el.replace('/>', ` fill="${t[k]}"/>`)).join('')
        + nShape.replace('/>', ` fill="${t.n}"/>`)
        + '</g>';
};

// --- Wordmark (Newsreader SemiBold, outlined) ---
function wordmark(text, fontSize, x, baseline, tracking = -0.01) {
    const scale = fontSize / font.unitsPerEm;
    const glyphs = font.stringToGlyphs(text);
    let cursor = x, d = '';
    glyphs.forEach((g, i) => {
        d += g.getPath(cursor, baseline, fontSize).toPathData(2);
        let advance = g.advanceWidth * scale;
        if (i < glyphs.length - 1) advance += font.getKerningValue(g, glyphs[i + 1]) * scale;
        cursor += advance + tracking * fontSize;
    });
    return { d, width: cursor - x - tracking * fontSize };
}

const svg = (w, h, body, bg) =>
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">${bg ? `<rect width="${w}" height="${h}" fill="${bg}"/>` : ''}${body}</svg>\n`;
const write = (name, content) => fs.writeFileSync(path.join(brandDir, name), content);
const png = (svgString, width, file) => {
    const out = new Resvg(svgString, { fitTo: { mode: 'width', value: width } }).render().asPng();
    if (file) fs.writeFileSync(file, out);
    return out;
};

// 1. Marks
write('newsstand-mark.svg', svg(64, 64, mark()));
write('newsstand-mark-dark.svg', svg(64, 64, mark({ theme: 'dark' })));

// 2. Lockups
function horizontal(theme) {
    const h = 64, gap = 18, size = 42, color = theme === 'light' ? C.ink : C.paper;
    const wm = wordmark('Newsstand', size, h + gap, h / 2 + (capHeight * size) / 2);
    return { w: Math.ceil(h + gap + wm.width + 2), h, body: mark({ theme }) + `<path d="${wm.d}" fill="${color}"/>` };
}
function stacked(theme) {
    const size = 56, markSize = 112, gap = 26, color = theme === 'light' ? C.ink : C.paper;
    const w0 = wordmark('Newsstand', size, 0, 0).width;
    const w = Math.ceil(Math.max(w0, markSize) + 8);
    const baseline = markSize + gap + capHeight * size;
    const wm = wordmark('Newsstand', size, (w - w0) / 2, baseline);
    return { w, h: Math.ceil(baseline + size * 0.25), body: mark({ x: (w - markSize) / 2, size: markSize, theme }) + `<path d="${wm.d}" fill="${color}"/>` };
}
const L = { h: horizontal('light'), hd: horizontal('dark'), s: stacked('light'), sd: stacked('dark') };
write('newsstand-logo.svg', svg(L.h.w, L.h.h, L.h.body));
write('newsstand-logo-dark.svg', svg(L.hd.w, L.hd.h, L.hd.body));
write('newsstand-logo-stacked.svg', svg(L.s.w, L.s.h, L.s.body));
write('newsstand-logo-stacked-dark.svg', svg(L.sd.w, L.sd.h, L.sd.body));

// 3. favicon.svg follows the browser color scheme.
const css = (theme) => Object.entries(THEMES[theme]).map(([k, v]) => `.${k}{fill:${v}}`).join('');
const faviconSvg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
<style>${css('light')}@media (prefers-color-scheme:dark){${css('dark')}}</style>
${SHEETS.map(([k, el]) => el.replace('<rect', `<rect class="${k}"`)).join('\n')}
${nShape.replace('<path', '<path class="n"')}
</svg>
`;
fs.writeFileSync(path.join(publicDir, 'favicon.svg'), faviconSvg);

// 4. favicon.ico (PNG-in-ICO, 16/32/48)
const icoSizes = [16, 32, 48];
const icoPngs = icoSizes.map((s) => png(svg(64, 64, mark()), s, path.join(previewDir, `favicon-${s}.png`)));
function ico(images, sizes) {
    const header = Buffer.alloc(6 + 16 * images.length);
    header.writeUInt16LE(0, 0); header.writeUInt16LE(1, 2); header.writeUInt16LE(images.length, 4);
    let offset = header.length;
    images.forEach((img, i) => {
        const e = 6 + i * 16, s = sizes[i];
        header.writeUInt8(s, e); header.writeUInt8(s, e + 1); header.writeUInt8(0, e + 2); header.writeUInt8(0, e + 3);
        header.writeUInt16LE(1, e + 4); header.writeUInt16LE(32, e + 6);
        header.writeUInt32LE(img.length, e + 8); header.writeUInt32LE(offset, e + 12);
        offset += img.length;
    });
    return Buffer.concat([header, ...images]);
}
fs.writeFileSync(path.join(publicDir, 'favicon.ico'), ico(icoPngs, icoSizes));

// 5. App icons on paper (iOS/Android apply their own rounding).
const appIcon = (pad) => svg(64, 64, mark({ x: 64 * pad, y: 64 * pad, size: 64 * (1 - 2 * pad) }), C.paper);
png(appIcon(0.18), 180, path.join(publicDir, 'apple-touch-icon.png'));
png(appIcon(0.18), 192, path.join(brandDir, 'icon-192.png'));
png(appIcon(0.18), 512, path.join(brandDir, 'icon-512.png'));
png(appIcon(0.26), 512, path.join(brandDir, 'icon-maskable-512.png'));

// 6. Blade component: the mark, themed with Tailwind classes (light + dark).
const TW = {
    back2: 'fill-emerald-300 dark:fill-emerald-800',
    back1: 'fill-emerald-500 dark:fill-emerald-600',
    front: 'fill-emerald-700 dark:fill-emerald-500',
    n: 'fill-white dark:fill-emerald-950',
};
const blade = `{{-- Newsstand mark ("The stack"). Generated from the brand build script; the N is Newsreader SemiBold, outlined. --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="{{ config('app.name', 'Newsstand') }}" {{ $attributes->class('shrink-0') }}>
${SHEETS.map(([k, el]) => '    ' + el.replace('<rect', `<rect class="${TW[k]}"`)).join('\n')}
    ${nShape.replace('<path', `<path class="${TW.n}"`)}
</svg>
`;
fs.writeFileSync(path.join(bladeDir, 'app-logo-icon.blade.php'), blade);

// 7. Previews
const W = 1200, H = 700;
const sheet = svg(W, H, `
  <rect width="${W / 2}" height="${H}" fill="${C.paper}"/><rect x="${W / 2}" width="${W / 2}" height="${H}" fill="${C.ink}"/>
  <g transform="translate(60 60)">${L.h.body}</g><g transform="translate(${W / 2 + 60} 60)">${L.hd.body}</g>
  <g transform="translate(${(W / 2 - L.s.w) / 2} 190)">${L.s.body}</g><g transform="translate(${W / 2 + (W / 2 - L.sd.w) / 2} 190)">${L.sd.body}</g>
  ${[128, 64, 32, 16].map((s, i) => `${mark({ x: 60 + [0, 150, 240, 300][i], y: 480 + (128 - s) / 2, size: s })}${mark({ x: W / 2 + 60 + [0, 150, 240, 300][i], y: 480 + (128 - s) / 2, size: s, theme: 'dark' })}`).join('')}
  <g transform="translate(400 488)"><image width="112" height="112" href="data:image/png;base64,${png(appIcon(0.18), 180).toString('base64')}"/></g>
`);
png(sheet, W, path.join(previewDir, 'brand-sheet.png'));
const zoom = svg(420, 140, `<rect width="420" height="140" fill="#e6e2d9"/>${icoSizes.map((s, i) => `<image x="${20 + i * 135}" y="20" width="100" height="100" image-rendering="pixelated" href="data:image/png;base64,${icoPngs[i].toString('base64')}"/>`).join('')}`);
png(zoom, 420, path.join(previewDir, 'favicon-zoom.png'));

console.log(JSON.stringify({ N: { x: N.x, y: N.y }, horizontal: [L.h.w, L.h.h], stacked: [L.s.w, L.s.h] }));
