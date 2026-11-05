import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { join, dirname } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const tokens = readFileSync(join(here, '../src/assets/design-tokens.css'), 'utf8');
const entry = readFileSync(join(here, '../src/main.ts'), 'utf8');
const styles = readFileSync(join(here, '../src/assets/main.css'), 'utf8');

const light = tokens.match(/:root\s*\{([\s\S]*?)\n\}/)?.[1];
const dark = tokens.match(/:root\[data-theme="dark"\],\s*\.dark\s*\{([\s\S]*?)\n\}/)?.[1];

function hex(section, variable) {
  assert.ok(section, `Bloque de tema ausente: ${variable}`);
  const match = section.match(new RegExp(`^\\s*${variable}:\\s*(#[a-fA-F0-9]{6});`, 'm'));
  assert.ok(match, `Falta token hexadecimal ${variable}`);
  return match[1];
}

function luminance(color) {
  const rgb = [1, 3, 5].map((offset) => {
    const channel = parseInt(color.slice(offset, offset + 2), 16) / 255;
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
}

function contrast(colorA, colorB) {
  const a = luminance(colorA);
  const b = luminance(colorB);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

describe('Design tokens Fase 1', () => {
  it('declara paleta clara y oscura con alias estables', () => {
    for (const theme of [light, dark]) {
      for (const token of [
        '--bg', '--side', '--surf', '--line', '--line-strong',
        '--ink', '--mut', '--brand', '--brandtx', '--accent',
        '--accent-text', '--focus-color', '--pos', '--warn', '--neg',
      ]) {
        hex(theme, token);
      }
    }
  });

  it('asegura contraste de texto principal y secundario', () => {
    for (const theme of [light, dark]) {
      assert.ok(contrast(hex(theme, '--ink'), hex(theme, '--surf')) >= 4.5);
      assert.ok(contrast(hex(theme, '--mut'), hex(theme, '--surf')) >= 4.5);
    }
  });

  it('asegura contraste de texto en botones primarios y de acento', () => {
    for (const theme of [light, dark]) {
      assert.ok(contrast(hex(theme, '--brand'), hex(theme, '--brandtx')) >= 4.5);
      assert.ok(contrast(hex(theme, '--accent'), '#ffffff') >= 4.5);
      assert.ok(contrast(hex(theme, '--accent-text'), hex(theme, '--surf')) >= 4.5);
    }
  });

  it('carga los tokens antes del CSS global y evita duplicar paletas', () => {
    assert.ok(entry.indexOf("import './assets/design-tokens.css';") < entry.indexOf("import './assets/main.css';"));
    assert.ok(!/^:root\s*\{/m.test(styles), 'main.css no debe redefinir la paleta');
    assert.match(styles, /--color-border-strong:\s*var\(--line-strong\)/);
  });
});
