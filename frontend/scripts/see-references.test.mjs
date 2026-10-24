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
    queryClient.setQueryData(['see-catalogs'], [{ number: '02', name: 'Monedas', item_count: 30, classification: 'test' }]);
    queryClient.setQueryData(['see-catalog', '02'], {
      data: rows, meta: { total: 30, current_page: 1, last_page: 1, classification: 'test' },
    });
    const html = await renderToString(createSSRApp(View, { getAccessToken: () => null })
      .use(VueQueryPlugin, { queryClient }));
    assert.match(html, /CODE-25/);
    assert.doesNotMatch(html, /CODE-26/);
    assert.match(html, /1–25 de 30/);
    assert.match(html, /role="combobox"/);
  } finally {
    await server.close();
  }
});
