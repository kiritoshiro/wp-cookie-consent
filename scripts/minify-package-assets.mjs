#!/usr/bin/env node
/*
 * Minifies the JavaScript and CSS inside a staged release package.
 *
 *   node scripts/minify-package-assets.mjs <staged plugin directory>
 *
 * Every .js and .css file under assets/ that is not already *.min.* is
 * rewritten in place: scripts with SWC (whitespace, syntax and local names
 * only; top-level names stay global and no newer syntax is introduced),
 * styles with lightningcss for the browsers listed below. Every result is
 * parsed once more. The repository keeps the readable sources; only the ZIP
 * gets the small files. Both tools are pinned in package-lock.json.
 */
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { Script } from 'node:vm';
import { minifySync } from '@swc/core';
import { transform } from 'lightningcss';

// Oldest browsers the styles must keep working in; lightningcss adds the
// vendor prefixes they need and never uses syntax they lack.
const version = (major) => major << 16;
const targets = {
    chrome: version(90),
    edge: version(90),
    firefox: version(88),
    safari: version(14),
    ios_saf: version(14),
    samsung: version(15),
};

const plugin = process.argv[2];
const assets = plugin && join(plugin, 'assets');
if (!assets || !existsSync(assets)) {
    console.error('Usage: node scripts/minify-package-assets.mjs <staged plugin directory>');
    process.exit(2);
}

const walk = (dir) => readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const file = join(dir, entry.name);
    if (entry.isDirectory()) return walk(file);
    return /\.(js|css)$/.test(entry.name) && !/\.min\.(js|css)$/.test(entry.name) ? [file] : [];
});

let before = 0;
let after = 0;
const files = walk(assets);
for (const file of files) {
    const name = relative(plugin, file).replace(/\\/g, '/');
    const source = readFileSync(file, 'utf8');
    let code;
    if (file.endsWith('.css')) {
        code = transform({ filename: name, code: Buffer.from(source), minify: true, targets }).code.toString();
        // Parse check: lightningcss throws on invalid CSS.
        transform({ filename: name, code: Buffer.from(code), targets });
    } else {
        code = minifySync(source, {
            module: false,
            compress: { toplevel: false },
            mangle: { toplevel: false },
            format: { comments: 'some' },
        }).code;
        // Parse check only; nothing is executed.
        new Script(code, { filename: name });
    }
    writeFileSync(file, code);
    before += Buffer.byteLength(source);
    after += Buffer.byteLength(code);
}
if (!files.length) {
    console.error('No JavaScript or CSS found under assets/');
    process.exit(1);
}
console.log(`Minified ${files.length} files: ${before} → ${after} bytes.`);
