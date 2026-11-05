import assert from 'node:assert/strict';
import test from 'node:test';
import { QueryClient } from '@tanstack/vue-query';

const createClient = () => new QueryClient({
  defaultOptions: { queries: { retry: false, gcTime: 60_000 } },
});

test('TanStack reutiliza la lectura de un maestro y la invalida tras modificarlo', async () => {
  const client = createClient();
  let requests = 0;
  const read = () => client.fetchQuery({
    queryKey: ['cost-center-master', 101, '', 'active', 1],
    queryFn: async () => ({ version: ++requests }),
    staleTime: 60_000,
  });

  try {
    assert.deepEqual(await read(), { version: 1 });
    assert.deepEqual(await read(), { version: 1 });
    assert.equal(requests, 1, 'la segunda lectura no debe repetir el GET');

    await client.invalidateQueries({
      queryKey: ['cost-center-master', 101], refetchType: 'none',
    });
    assert.deepEqual(await read(), { version: 2 });
    assert.equal(requests, 2, 'una mutación debe forzar una lectura nueva');
  } finally {
    client.clear();
  }
});

test('una caché por empresa y filtro nunca se reutiliza para otra empresa', async () => {
  const client = createClient();
  let requests = 0;
  const read = (tenant, search) => client.fetchQuery({
    queryKey: ['entity-directory', tenant, search, 'all', 1],
    queryFn: async () => ({ tenant, search, requestNumber: ++requests }),
    staleTime: 120_000,
  });

  try {
    assert.equal((await read(101, '')).tenant, 101);
    assert.equal((await read(202, '')).tenant, 202);
    assert.equal((await read(101, 'credito')).search, 'credito');
    assert.equal((await read(101, '')).requestNumber, 1);
    assert.equal(requests, 3, 'cada empresa/filtro requiere su propia consulta');
    assert.equal(client.getQueryData(['entity-directory', 202, '', 'all', 1]).tenant, 202);
  } finally {
    client.clear();
  }
});

test('al cerrar la sesión se descarta toda información empresarial de la caché', async () => {
  const client = createClient();
  const key = ['company-subdiaries', 101, 'all'];
  let requests = 0;
  const fetchRows = () => client.fetchQuery({
    queryKey: key,
    queryFn: async () => [{ code: String(++requests) }],
    staleTime: 60_000,
  });

  await fetchRows();
  assert.deepEqual(client.getQueryData(key), [{ code: '1' }]);
  client.clear();
  assert.equal(client.getQueryData(key), undefined);
  assert.deepEqual(await fetchRows(), [{ code: '2' }]);
  client.clear();
});

test('los datos financieros con frescura cero se consultan en cada acceso', async () => {
  const client = createClient();
  let calls = 0;
  const fetchSettings = () => client.fetchQuery({
    queryKey: ['company-catalog', 101, 'settings'],
    queryFn: async () => ({ version: ++calls }),
    staleTime: 0,
  });
  try {
    assert.deepEqual(await fetchSettings(), { version: 1 });
    assert.deepEqual(await fetchSettings(), { version: 2 });
  } finally {
    client.clear();
  }
});
