import assert from 'node:assert/strict';
import test from 'node:test';
import { createServer } from 'vite';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';

test('SEE view renders cached catalog rows with client-side page size 25', async () => {
  const server = await createServer({
    optimizeDeps: { noDiscovery: true },
    server: { middlewareMode: true, preTransformRequests: false },
    appType: 'custom',
  });
  try {
    const { default: View } = await server.ssrLoadModule('/src/modules/accounting/views/SeeReferencesView.vue');
    const queryClient = new QueryClient();
    const rows = Array.from({ length: 30 }, (_, i) => ({
      code: `CODE-${i + 1}`, description: `Moneda ${i + 1}`, details: {},
      source_row: i + 1, is_ambiguous: false,
    }));
    queryClient.setQueryData(['see-catalogs'], [{ number: '02', name: 'Monedas', item_count: 30,
      classification: 'ISO_4217_LIST_ONE_2026_09_17_REFERENCE', latest_version: '2026-09-17-list-one',
      historical_item_count: 30, available_versions: [{ version: '2026-09-17-list-one',
        classification: 'ISO_4217_LIST_ONE_2026_09_17_REFERENCE', item_count: 30,
        source_url: 'https://www.six-group.com/example.xml', name: 'Monedas' }] }]);
    queryClient.setQueryData(['see-catalog', '02', 'latest'], {
      data: rows, meta: { total: 30, current_page: 1, last_page: 1,
        classification: 'ISO_4217_LIST_ONE_2026_09_17_REFERENCE', version: '2026-09-17-list-one',
        source_url: 'https://www.six-group.com/example.xml' },
    });
    queryClient.setQueryData(['see-company-visibility', 1], {
      currencies: rows.map(row => row.code), catalogs: { '03': null, '04': null },
    });
    const html = await renderToString(createSSRApp(View, {
      getAccessToken: () => null, activeCompanyId: 1, canManage: true,
    })
      .use(VueQueryPlugin, { queryClient }));
    assert.match(html, /CODE-25/);
    assert.doesNotMatch(html, /CODE-26<!--\]-->/);
    assert.match(html, /1–25 de 30/);
    assert.match(html, /role="combobox"/);
    assert.match(html, /Lista ISO 4217/);
    assert.match(html, /Archivo recibido/);
  } finally {
    await server.close();
  }
});

test('SUNAT PLE view renders cached rows separately from SEE with fixed-width columns', async () => {
  const server = await createServer({
    optimizeDeps: { noDiscovery: true },
    server: { middlewareMode: true, preTransformRequests: false },
    appType: 'custom',
  });
  try {
    const { default: View } = await server.ssrLoadModule('/src/modules/accounting/views/SunatReferencesView.vue');
    const queryClient = new QueryClient();
    queryClient.setQueryData(['sunat-reference', 'ple', '01'], [
      { code: '001', description: 'Depósito en cuenta', details: '' },
    ]);
    const html = await renderToString(createSSRApp(View, { getAccessToken: () => null })
      .use(VueQueryPlugin, { queryClient }));
    assert.match(html, /Depósito en cuenta/);
    assert.match(html, /Tablas PLE\/SIRE/);
    assert.match(html, /w-\[712px\] table-fixed/);
    assert.match(html, /1–1 de 1/);
    assert.doesNotMatch(html, /Catálogos SEE y Ubigeo/);
  } finally {
    await server.close();
  }
});
