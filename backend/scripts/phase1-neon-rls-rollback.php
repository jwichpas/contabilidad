<?php

declare(strict_types=1);

/**
 * F1.8 — Prueba opt-in de RLS real A/B en Neon contabilidad-dev PG18.
 *
 * Por defecto solo muestra informacion. --probe=contabilidad-dev ejecuta
 * INSERT/UPDATE/DELETE TEMPORALES dentro de una sola transaccion app_backend
 * que termina SIEMPRE en ROLLBACK, incluidos errores.
 *
 * No prueba HTTP; los tests Pest HTTP siguen en el Docker efimero por defecto.
 */
$backend = dirname(__DIR__);
$expectedHost = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan', '--probe=contabilidad-dev'], true)) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase1-neon-rls-rollback.php [--plan|--probe=contabilidad-dev]\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] Solo Neon contabilidad-dev con rol app_backend limitado.\n";
    echo "[PLAN] Crea 2 empresas y 2 areas ficticias dentro de una transaccion.\n";
    echo "[PLAN] Comprueba visibilidad A/B, INSERT ajeno rechazado, UPDATE/DELETE ajenos en cero.\n";
    echo "[PLAN] Comprueba que no se vean areas/audit_logs sin tenant.\n";
    echo "[PLAN] Ejecuta ROLLBACK obligatorio y SELECT final de limpieza.\n";
    echo "[DRY-RUN] No se abrio ninguna conexion SQL.\n";
    exit(0);
}

if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] .env ausente o configuracion cacheada.\n");
    exit(1);
}
$seen = [];
$duplicates = [];
foreach (file($backend.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $m)) {
        continue;
    }
    $key = $m[1];
    if (str_starts_with($key, 'DB_') || str_starts_with($key, 'NEON_')
        || in_array($key, ['APP_ENV','APP_KEY','DATABASE_URL'], true)) {
        if (isset($seen[$key])) {
            $duplicates[] = $key;
        }
        $seen[$key] = true;
    }
}
if ($duplicates !== []) {
    fwrite(STDERR, '[FAIL] Variables repetidas: '.implode(', ', array_unique($duplicates)).PHP_EOL);
    exit(1);
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cfg = (array) config('database.connections.pgsql', []);
$admin = (array) config('database.connections.pgsql_migrations', []);
$password = (string) ($cfg['password'] ?? '');
$rolePassword = (string) env('DB_APP_BACKEND_ROLE_PASSWORD', '');
if (! $app->environment('local')
    || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME', '') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST', '') !== $expectedHost
    || (string) ($cfg['host'] ?? '') !== $expectedHost
    || (string) ($admin['host'] ?? '') !== $expectedHost
    || (string) ($cfg['port'] ?? '') !== '5432'
    || (string) ($admin['port'] ?? '') !== '5432'
    || (string) ($cfg['database'] ?? '') !== 'neondb'
    || (string) ($admin['database'] ?? '') !== 'neondb'
    || (string) ($cfg['username'] ?? '') !== 'app_backend'
    || (string) ($admin['username'] ?? '') !== 'neondb_owner'
    || trim((string) ($cfg['url'] ?? '')) !== ''
    || trim((string) ($admin['url'] ?? '')) !== ''
    || ! in_array((string) ($cfg['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)
    || strlen($rolePassword) < 20
    || $password === ''
    || ! hash_equals($rolePassword, $password)) {
    fwrite(STDERR, "[FAIL] Entorno inseguro: solo app_backend de Neon contabilidad-dev directo.\n");
    exit(1);
}
unset($rolePassword, $password);
$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$pdo = null;
$begun = false;
$rolledBack = false;
$passed = false;
// IDs altos ficticios elegidos sin usar nextval(): no consumir secuencias.
// PostgreSQL bigint signed y validacion de tenant en SQL aceptan estos valores.
$a = random_int(4000000000000, 4499999999999);
$b = random_int(4500000000000, 4999999999999);
$areaA = random_int(5000000000000, 5499999999999);
$areaB = random_int(5500000000000, 5999999999999);
$taxA = '20'.(string) random_int(100000000, 999999999);
$taxB = '20'.(string) random_int(100000000, 999999999);
try {
    $pdo = $db->getPdo();
    $pdo->exec('BEGIN TRANSACTION');
    $begun = true;
    $db->selectOne("SELECT set_config('statement_timeout','15000',true) AS val");

    $who = $db->selectOne(<<<'SQL'
        SELECT current_user AS actor,
               current_database() AS db,
               current_setting('server_version_num')::integer AS version,
               (SELECT count(*) FROM pg_catalog.pg_roles
                WHERE rolname=current_user AND NOT rolsuper AND NOT rolbypassrls)::integer AS safe_role
        SQL);
    if (($who->actor ?? '') !== 'app_backend' || ($who->db ?? '') !== 'neondb'
        || intdiv((int) ($who->version ?? 0), 10000) !== 18
        || (int) ($who->safe_role ?? 0) !== 1) {
        throw new \RuntimeException('Rol/conexion/version diferentes de los esperados.');
    }

    // Si hay datos reales, no hace falta acceder a ellos: IDs y RUC aleatorios,
    // todas las pruebas filtran por id y ninguna fila se persiste.
    foreach ([[$a, $taxA, 'A'], [$b, $taxB, 'B']] as [$id, $tax, $label]) {
        $db->insert(
            'INSERT INTO public.companies (id,tax_id,business_name,legal_address,is_active,created_at,updated_at)
             VALUES (?, ?, ?, ?, TRUE, now(), now())',
            [$id, $tax, 'F1.8 TEMP '.$label, 'Prueba transaccional (ROLLBACK)']
        );
    }

    $db->selectOne("SELECT set_config('app.current_company_id', ?, true) AS val", [(string) $a]);
    $db->insert(
        'INSERT INTO public.company_areas (id,company_id,code,name,created_at,updated_at)
         VALUES (?,?,?, ?,now(),now())',
        [$areaA, $a, 'F18_A', 'Area transaccional A']
    );
    $own = $db->selectOne('SELECT count(*)::integer AS n FROM public.company_areas WHERE id=?', [$areaA]);
    if ((int) ($own->n ?? 0) !== 1) {
        throw new \RuntimeException('No se ve la fila propia de A.');
    }

    $db->selectOne("SELECT set_config('app.current_company_id', ?, true) AS val", [(string) $b]);
    $db->insert(
        'INSERT INTO public.company_areas (id,company_id,code,name,created_at,updated_at)
         VALUES (?,?,?, ?,now(),now())',
        [$areaB, $b, 'F18_B', 'Area transaccional B']
    );
    if ((int) ($db->selectOne('SELECT count(*)::integer AS n FROM public.company_areas WHERE id=?', [$areaA])->n ?? 0) !== 0) {
        throw new \RuntimeException('El tenant B ve datos de A.');
    }

    $db->selectOne("SELECT set_config('app.current_company_id', ?, true) AS val", [(string) $a]);
    if ((int) ($db->selectOne('SELECT count(*)::integer AS n FROM public.company_areas WHERE id=?', [$areaB])->n ?? 0) !== 0) {
        throw new \RuntimeException('El tenant A ve datos de B.');
    }
    $updates = $db->update('UPDATE public.company_areas SET name=? WHERE id=?', ['F18_CROSS_FORBIDDEN', $areaB]);
    $deletes = $db->delete('DELETE FROM public.company_areas WHERE id=?', [$areaB]);
    if ($updates !== 0 || $deletes !== 0) {
        throw new \RuntimeException('UPDATE o DELETE interempresa afecto filas ajenas.');
    }

    // Intentar INSERT cruzado con savepoint; no romper la transaccion principal.
    $pdo->exec('SAVEPOINT f18_cross_insert');
    $blocked = false;
    try {
        $db->insert(
            'INSERT INTO public.company_areas (id,company_id,code,name,created_at,updated_at)
             VALUES (?,?,?, ?,now(),now())',
            [random_int(6000000000000, 6999999999999), $b, 'F18_X', 'No permitido']
        );
    } catch (\Throwable $error) {
        $sqlState = (string) ($error->errorInfo[0] ?? $error->getCode());
        $blocked = $sqlState === '42501'; // permission denied / RLS WITH CHECK
    } finally {
        $pdo->exec('ROLLBACK TO SAVEPOINT f18_cross_insert');
        $pdo->exec('RELEASE SAVEPOINT f18_cross_insert');
    }
    if (! $blocked) {
        throw new \RuntimeException('INSERT interempresa no fue denegado especificamente por permisos RLS.');
    }

    $db->selectOne("SELECT set_config('app.current_company_id','',true) AS val");
    $noTenantAreas = $db->selectOne(
        'SELECT count(*)::integer AS n FROM public.company_areas WHERE id IN (?,?)',
        [$areaA,$areaB]
    );
    $noTenantAudit = $db->selectOne('SELECT count(*)::integer AS n FROM public.audit_logs');
    if ((int) ($noTenantAreas->n ?? -1) !== 0 || (int) ($noTenantAudit->n ?? -1) !== 0
        || ($db->selectOne('SELECT public.app_current_company_id() AS tenant')->tenant ?? null) !== null) {
        throw new \RuntimeException('Sin tenant se filtraron datos.');
    }
    $passed = true;
    echo '[PASS] RLS real: A/B aisladas; UPDATE/DELETE cruzados 0; INSERT cruzado denegado; sin tenant no hay acceso.'.PHP_EOL;
} catch (\Throwable $error) {
    // Evitar excepciones SQL con parámetros, credenciales o datos.
    fwrite(STDERR, "[FAIL] Prueba RLS A/B no aprobada. Se ordenara ROLLBACK.\n");
} finally {
    if ($begun && $pdo !== null) {
        try {
            $pdo->exec('ROLLBACK');
            $rolledBack = true;
        } catch (\Throwable $ignored) {
            fwrite(STDERR, "[FAIL] ROLLBACK no confirmado; detener cualquier otra prueba.\n");
            $passed = false;
        }
    }
}
if ($rolledBack) {
    try {
        $remaining = $db->selectOne(
            'SELECT count(*)::integer AS n FROM public.companies WHERE id IN (?,?)',
            [$a,$b]
        );
        if ((int) ($remaining->n ?? -1) !== 0) {
            $passed = false;
            fwrite(STDERR, "[FAIL] Empresas ficticias persistieron tras ROLLBACK.\n");
        } else {
            echo '[PASS] ROLLBACK confirmado: cero empresas ficticias persistidas; IDs explicitos sin uso de secuencias.'.PHP_EOL;
        }
    } catch (\Throwable $error) {
        $passed = false;
        fwrite(STDERR, "[FAIL] No pudo confirmarse limpieza de las empresas ficticias.\n");
    }
}
$db->disconnect();
if (! $passed || ! $rolledBack) {
    exit(1);
}
echo '[PASS] Probe RLS A/B completado en Neon contabilidad-dev, sin filas de prueba persistentes.'.PHP_EOL;
