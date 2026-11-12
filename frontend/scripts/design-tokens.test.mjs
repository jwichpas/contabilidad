import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { join, dirname } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const tokens = readFileSync(join(here, '../src/assets/design-tokens.css'), 'utf8');
const entry = readFileSync(join(here, '../src/main.ts'), 'utf8');
const styles = readFileSync(join(here, '../src/assets/main.css'), 'utf8');
const header = readFileSync(join(here, '../src/shared/layout/AppHeader.vue'), 'utf8');
const sidebar = readFileSync(join(here, '../src/shared/layout/AppSidebar.vue'), 'utf8');

const light = tokens.match(/:root\s*\{([\s\S]*?)\n\}/)?.[1];
const dark = tokens.match(/:root\[data-theme="dark"\],\s*\.dark\s*\{([\s\S]*?)\n\}/)?.[1];

/**
 * Resuelve colores semánticos var(--color-...) a los valores OKLCH suministrados
 * para evaluar contraste en sRGB sin depender del navegador.
 */
function hex(section, variable) {
  const find = (text, key) => text?.match(new RegExp('^\\s*' + key + ':\\s*([^;]+);', 'm'))?.[1]?.trim();
  const resolve = (key, depth = 0) => {
    assert.ok(depth < 5, 'Referencia circular de color: ' + key);
    const raw = find(section, key) ?? find(light, key);
    assert.ok(raw, 'Falta token: ' + key);
    const alias = raw.match(/^var\((--[\w-]+)\)$/);
    if (alias) return resolve(alias[1], depth + 1);
    if (/^#[0-9a-fA-F]{3}$/.test(raw)) return '#' + raw.slice(1).split('').map(c => c + c).join('');
    if (/^#[0-9a-fA-F]{6}$/.test(raw)) return raw;
    const match = raw.match(/^oklch\(([\d.]+)%\s+([\d.]+)\s+([\d.]+)\)$/);
    assert.ok(match, 'Color inesperado para ' + key + ': ' + raw);
    const L = Number(match[1]) / 100, C = Number(match[2]), h = Number(match[3]) * Math.PI / 180;
    const a = C * Math.cos(h), b = C * Math.sin(h);
    const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (L - 0.0894841775 * a - 1.2914855480 * b) ** 3;
    const linear = [
      4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
      -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
      -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s,
    ];
    const bytes = linear.map(value => {
      const channel = Math.min(1, Math.max(0, value));
      const rgb = channel <= 0.0031308 ? channel * 12.92 : 1.055 * channel ** (1 / 2.4) - 0.055;
      return Math.round(rgb * 255).toString(16).padStart(2, '0');
    });
    return '#' + bytes.join('');
  };
  return resolve(variable);
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

describe('Design tokens OKLCH corporativos', () => {
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

  it('conserva los valores OKLCH exactos y las familias cromaticas proporcionadas', () => {
    for (const [token, value] of [
      ['red-500', '63.7% 0.237 25.331'],
      ['green-500', '72.3% 0.219 149.579'],
      ['yellow-500', '79.5% 0.184 86.047'],
      ['blue-600', '54.6% 0.245 262.881'],
      ['violet-600', '54.1% 0.281 293.009'],
      ['zinc-800', '27.4% 0.006 286.033'],
      ['zinc-950', '14.1% 0.005 285.823'],
    ]) {
      assert.ok(tokens.includes('--color-' + token + ': oklch(' + value + ');'), token);
    }
    assert.match(light, /--focus-color: var\(--color-violet-600\)/);
    assert.match(dark, /--focus-color: var\(--color-violet-600\)/);
    assert.match(dark, /--line: var\(--color-zinc-800\)/);
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

  it('el elemento de navegación activo es legible en los dos temas', () => {
    for (const theme of [light, dark]) {
      assert.ok(contrast(hex(theme, '--nav-active'), hex(theme, '--soft')) >= 4.5);
    }
    assert.match(styles, /color:\s*var\(--nav-active\)/);
  });

  it('la navegación muestra 13 destinos y sus estados accesibles', () => {
    const modules = [
      'entities', 'fiscal-history', 'subdiaries', 'cost-centers',
      'catalog', 'journal', 'fiscal-periods', 'exchange-rates',
      'financial-statements', 'see-references', 'sunat-references',
      'company-create', 'company',
    ];
    for (const module of modules) {
      assert.ok(sidebar.includes(`currentModule === '${module}' ? 'page' : undefined`), module);
    }
    assert.match(sidebar, /app-sidebar-nav/);
    assert.match(styles, /\.app-sidebar-nav button\[aria-current="page"\]/);
  });

  it('el menú ofrece más ancho y deja visibles datos de la empresa', () => {
    assert.match(sidebar, /lg:w-\[17\.5rem\]/);
    assert.match(sidebar, /RUC: \{\{ activeCompany\.taxId \}\}/);
    assert.doesNotMatch(sidebar, />\s*Vouchers\s*<\/span>/);
    assert.doesNotMatch(sidebar, />\s*Monedas\s*<\/span>/);
    assert.match(styles, /\.app-sidebar-nav button \{/);
  });

  it('el encabezado no inventa certificación ni ejercicio fiscal activo', () => {
    assert.doesNotMatch(header, /ISO\s*27001|SGSI Activo|2026-10/);
    assert.match(header, /API verificada/);
    assert.doesNotMatch(styles, /aside\[aria-label="Menú principal del ERP Contable"\]/);
  });

  it('carga los tokens antes del CSS global y evita duplicar paletas', () => {
    assert.ok(entry.indexOf("import './assets/design-tokens.css';") < entry.indexOf("import './assets/main.css';"));
    assert.ok(!/^:root\s*\{/m.test(styles), 'main.css no debe redefinir la paleta');
    assert.match(styles, /--color-border-strong:\s*var\(--line-strong\)/);
  });
});
