import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

// Budget for the initial entry, not for every lazy-loaded module.
// Baseline after code splitting: ~196 kB raw / ~65 kB gzip.
const MAX_ENTRY_BYTES = 250_000;
const MAX_ENTRY_GZIP_BYTES = 90_000;

const dist = resolve(dirname(fileURLToPath(import.meta.url)), '../dist');
const html = readFileSync(resolve(dist, 'index.html'), 'utf8');
const scripts = [...html.matchAll(/<script(?=[^>]*\btype="module")[^>]*\bsrc="([^"]+\.js)"[^>]*>/g)];
assert.equal(scripts.length, 1, 'El build debe generar exactamente un JavaScript de entrada.');

const src = scripts[0][1].replace(/^\.?\//, '');
assert.match(src, /^assets\/[\w-]+\.js$/, 'El JavaScript de entrada debe estar en dist/assets.');
const code = readFileSync(resolve(dist, src));
const rawBytes = code.byteLength;
const gzipBytes = gzipSync(code).byteLength;

console.log(`JS inicial: ${(rawBytes / 1000).toFixed(2)} kB; gzip: ${(gzipBytes / 1000).toFixed(2)} kB`);
assert.ok(rawBytes <= MAX_ENTRY_BYTES,
  `El JS inicial supera ${MAX_ENTRY_BYTES / 1000} kB: ${rawBytes / 1000} kB.`);
assert.ok(gzipBytes <= MAX_ENTRY_GZIP_BYTES,
  `El JS inicial comprimido supera ${MAX_ENTRY_GZIP_BYTES / 1000} kB: ${gzipBytes / 1000} kB.`);
