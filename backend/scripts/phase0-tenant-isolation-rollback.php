<?php

declare(strict_types=1);

/**
 * F0 - aislamiento RLS entre empresa real y empresa ficticia TRANSACCIONAL.
 *
 * Modo por defecto: SOLO PREFLIGHT (sin escritura).
 * Ejecucion deliberada: php scripts/phase0-tenant-isolation-rollback.php --execute
 *
 * Requiere UNA empresa de desarrollo, conexion operativa app_backend y
 * conexion administrativa postgres (esta ultima SOLAMENTE SELECT).
 *
 * --execute realiza INSERTs exclusivamente dentro de UNA transaccion pgsql,
 * con IDs explicitos (no consume nextval de secuencias), y hace ROLLBACK SIEMPRE.
 * No crea migraciones, tablas ni empresas persistentes, no ejecuta DELETE,
 * no toca registros preexistentes ni hace llamadas HTTP externas.
 *
 * ADVERTENCIA: probar escrituras en una transaccion no es identico a no escribir.
 * Un rollback deshace las filas, pero no garantiza revertir efectos externos
 * futuros de triggers ni estados de procesos fuera de PostgreSQL.
 */

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$execute = in_array('--execute', $argv, true);
if (count($argv) > 2 || (count($argv) === 2 && ! $execute)) {
    fwrite(STDERR, 'Uso: php scripts/phase0-tenant-isolation-rollback.php [--execute]'.PHP_EOL);
    exit(2);
}

$operationalConfig = (array) config('database.connections.pgsql', []);
$adminConfig = (array) config('database.connections.pgsql_migrations', []);
$operationalHost = (string) ($operationalConfig['host'] ?? '');
$operationalUser = (string) ($operationalConfig['username'] ?? '');
$adminHost = (string) ($adminConfig['host'] ?? '');
$adminUser = (string) ($adminConfig['username'] ?? '');
$operationalUrl = trim((string) ($operationalConfig['url'] ?? ''));
$adminUrl = trim((string) ($adminConfig['url'] ?? ''));

if ($operationalUrl !== '' || $adminUrl !== ''
    || $operationalHost !== 'aws-1-ca-central-1.pooler.supabase.com'
    || $operationalUser !== 'app_backend.bnjwxphwtkhblvhsnylj'
    || ! in_array($adminHost, [
        'aws-1-ca-central-1.pooler.supabase.com',
        'db.bnjwxphwtkhblvhsnylj.supabase.co',
    ], true)
    || ! in_array($adminUser, ['postgres.bnjwxphwtkhblvhsnylj', 'postgres'], true)) {
    fwrite(STDERR, '[BLOQUEO] El proyecto o las credenciales configuradas no corresponden '
        .'a las conexiones aprobadas para contabilidad-database DEV.'.PHP_EOL);
    exit(2);
}

$application = DB::connection('pgsql');
$admin = DB::connection('pgsql_migrations');

$countsSql = <<<'SQL'
    SELECT
      (SELECT count(*) FROM public.companies) AS companies,
      (SELECT count(*) FROM public.users) AS users,
      (SELECT count(*) FROM public.company_user) AS memberships,
      (SELECT count(*) FROM public.accounting_accounts) AS accounts,
      (SELECT count(*) FROM public.journal_entries) AS entries,
      (SELECT count(*) FROM public.journal_entry_lines) AS lines
    SQL;

$baseline = null;
$createdInTxn = false;
$passed = false;
$rollbackSucceeded = false;

try {
    $adminIdentity = $admin->selectOne('SELECT current_user AS role_name, current_database() AS database_name');
    $appIdentity = $application->selectOne('SELECT current_user AS role_name, current_database() AS database_name');
    if ($adminIdentity?->role_name !== 'postgres' || $adminIdentity?->database_name !== 'postgres'
        || $appIdentity?->role_name !== 'app_backend' || $appIdentity?->database_name !== 'postgres') {
        throw new RuntimeException('Conexiones incorrectas: se requiere postgres (lectura) y app_backend (operativa).');
    }

    $baseline = (array) $admin->selectOne($countsSql);
    $baseline = array_map('intval', $baseline);
    echo '[PREFLIGHT] Linea base: ', json_encode($baseline, JSON_THROW_ON_ERROR), PHP_EOL;

    if ($baseline['companies'] !== 1 || $baseline['users'] !== 1
        || $baseline['memberships'] !== 1 || $baseline['accounts'] < 1
        || $baseline['entries'] < 1) {
        throw new RuntimeException('Linea base inesperada. No se usara esta prueba con registros distintos.');
    }

    $principal = $admin->selectOne(<<<'SQL'
        SELECT cu.company_id, cu.user_id
        FROM public.company_user cu
        JOIN public.companies c ON c.id = cu.company_id
        JOIN public.users u ON u.id = cu.user_id
        WHERE cu.is_active IS TRUE AND c.is_active IS TRUE
        ORDER BY cu.company_id LIMIT 1
        SQL);
    if ($principal === null) {
        throw new RuntimeException('No hay membresia activa de la empresa original.');
    }
    $companyA = (int) $principal->company_id;
    $userA = (int) $principal->user_id;

    $preExistingAccount = $admin->selectOne(
        'SELECT id FROM public.accounting_accounts WHERE company_id = ?::bigint ORDER BY id LIMIT 1',
        [$companyA]
    );
    if ($preExistingAccount === null) {
        throw new RuntimeException('La empresa original no tiene cuentas de referencia.');
    }
    $originalAccountId = (int) $preExistingAccount->id;

    $rls = $admin->select(<<<'SQL'
        SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relname IN ('accounting_accounts', 'company_user')
          AND c.relkind = 'r'
        SQL);
    if (count($rls) !== 2) {
        throw new RuntimeException('No se encontraron las dos tablas RLS de prueba.');
    }
    foreach ($rls as $item) {
        if (! $item->relrowsecurity || ! $item->relforcerowsecurity) {
            throw new RuntimeException('RLS ENABLE/FORCE no esta activo en '.$item->relname);
        }
    }

    if (! $execute) {
        echo '[OK] Preflight de solo lectura. No se escribio ningun registro.', PHP_EOL;
        echo '[PENDIENTE] Para probar dos empresas transaccionales: '
            .'php scripts/phase0-tenant-isolation-rollback.php --execute', PHP_EOL;
        exit(0);
    }

    $companyB = random_int(3000000000, 8000000000);
    $accountB = random_int(9000000001, 18000000000);
    $membershipB = random_int(18000000001, 27000000000);
    $testTaxId = '299'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

    $application->beginTransaction();
    $createdInTxn = true;
    // Limita el contexto exclusivamente a esta transaccion y esta conexion.
    $application->selectOne("SELECT set_config('app.current_company_id', '', true)");
    $application->selectOne("SELECT set_config('app.current_user_id', '', true)");

    $application->insert(<<<'SQL'
        INSERT INTO public.companies
            (id, tax_id, business_name, legal_address, is_active, created_at, updated_at)
        VALUES (?::bigint, ?, 'F0 EMPRESA FICTICIA (ROLLBACK)', 'Dirección ficticia F0', TRUE, now(), now())
        SQL, [$companyB, $testTaxId]);

    // A no debe ver datos de B.
    $application->selectOne('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $companyA]);
    $aAccounts = (int) $application->selectOne(
        'SELECT count(*) AS total FROM public.accounting_accounts WHERE id = ?::bigint',
        [$originalAccountId]
    )->total;
    if ($aAccounts !== 1) {
        throw new RuntimeException('Empresa A no puede leer su cuenta existente.');
    }

    // B no debe ver los datos de A antes ni despues de crear una cuenta propia.
    $application->selectOne('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $companyB]);
    $aVisibleInB = (int) $application->selectOne(
        'SELECT count(*) AS total FROM public.accounting_accounts WHERE id = ?::bigint',
        [$originalAccountId]
    )->total;
    if ($aVisibleInB !== 0) {
        throw new RuntimeException('Fuga: B puede leer una cuenta de A.');
    }

    $application->insert(<<<'SQL'
        INSERT INTO public.accounting_accounts
            (id, company_id, code, name, level, path, account_type, balance_type, is_active,
             created_at, updated_at)
        VALUES (?::bigint, ?::bigint, 'F0PROBE', 'Cuenta temporal F0', 1, 'F0PROBE',
                'ASSET', 'DEBIT', TRUE, now(), now())
        SQL, [$accountB, $companyB]);

    $bOwnAccounts = (int) $application->selectOne(
        'SELECT count(*) AS total FROM public.accounting_accounts WHERE id = ?::bigint',
        [$accountB]
    )->total;
    if ($bOwnAccounts !== 1) {
        throw new RuntimeException('Empresa B no puede leer su cuenta de prueba.');
    }

    // Sin membresia de A en B, un endpoint protegido debe rechazar el acceso.
    $unauthorizedMembership = $application->selectOne(<<<'SQL'
        SELECT 1 AS allowed FROM public.company_user cu
        JOIN public.companies c ON c.id = cu.company_id
        WHERE cu.company_id = ?::bigint
          AND cu.user_id = ?::bigint
          AND cu.is_active IS TRUE AND c.is_active IS TRUE
        LIMIT 1
        SQL, [$companyB, $userA]);
    if ($unauthorizedMembership !== null) {
        throw new RuntimeException('Un usuario de A aparecio autorizado en B sin membresia.');
    }

    $application->selectOne('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $companyA]);
    $bVisibleInA = (int) $application->selectOne(
        'SELECT count(*) AS total FROM public.accounting_accounts WHERE id = ?::bigint',
        [$accountB]
    )->total;
    if ($bVisibleInA !== 0) {
        throw new RuntimeException('Fuga: A puede leer una cuenta de B.');
    }

    $updated = $application->update(
        'UPDATE public.accounting_accounts SET name = name WHERE id = ?::bigint',
        [$accountB]
    );
    if ($updated !== 0) {
        throw new RuntimeException('Fuga: A pudo modificar una cuenta de B.');
    }

    // Crear una membresia B (solo dentro de esta transaccion) comprueba el SELECT
    // multiempresa por usuario, sin conceder privilegios globales ni SET ROLE.
    $application->selectOne('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $companyB]);
    $application->insert(<<<'SQL'
        INSERT INTO public.company_user
            (id, company_id, user_id, role, is_active, created_at, updated_at)
        VALUES (?::bigint, ?::bigint, ?::bigint, 'AUDITOR', TRUE, now(), now())
        SQL, [$membershipB, $companyB, $userA]);

    $application->selectOne("SELECT set_config('app.current_company_id', '', true)");
    $application->selectOne('SELECT set_config(?, ?, true)', ['app.current_user_id', (string) $userA]);
    $listing = $application->select(<<<'SQL'
        SELECT company_id
        FROM public.company_user
        WHERE user_id = ?::bigint AND is_active IS TRUE
        ORDER BY company_id
        SQL, [$userA]);
    $ids = array_map(static fn (object $row): int => (int) $row->company_id, $listing);
    sort($ids);
    $expected = [$companyA, $companyB];
    sort($expected);
    if ($ids !== $expected) {
        throw new RuntimeException('La politica SELECT no devolvio ambas membresias del usuario.');
    }

    $application->selectOne("SELECT set_config('app.current_user_id', '', true)");
    $noContext = (int) $application->selectOne(
        'SELECT count(*) AS total FROM public.company_user'
    )->total;
    if ($noContext !== 0) {
        throw new RuntimeException('company_user no cerro el acceso al limpiar ambos contextos.');
    }

    echo '[PASS] Empresas A/B: no hay lectura ni UPDATE cruzados, membresia valida, '
        .'listado transversal y RLS fail-closed.', PHP_EOL;
    $passed = true;
} catch (RuntimeException $e) {
    fwrite(STDERR, '[BLOQUEO] '.$e->getMessage().PHP_EOL);
} catch (Throwable) {
    fwrite(STDERR, '[ERROR] Fallo SQL o de conexion durante la prueba; '
        .'no se muestran sentencias SQL ni credenciales.'.PHP_EOL);
} finally {
    // rollback obligatorio aun si alguna asercion falla.
    if ($createdInTxn) {
        try {
            if ($application->transactionLevel() > 0) {
                $application->rollBack();
            }
            $rollbackSucceeded = $application->transactionLevel() === 0;
        } catch (Throwable) {
            fwrite(STDERR, '[ALERTA] No se confirmo el cierre transaccional. '
                .'Detener pruebas y auditar los conteos por conexion administrativa.'.PHP_EOL);
            $rollbackSucceeded = false;
        }
    }

    if ($baseline !== null) {
        try {
            $after = array_map('intval', (array) $admin->selectOne($countsSql));
            echo '[VERIFY] Conteos finales: ', json_encode($after, JSON_THROW_ON_ERROR), PHP_EOL;
            if ($after !== $baseline) {
                fwrite(STDERR, '[ALERTA] Los conteos han cambiado. No ejecutar mas pruebas.'.PHP_EOL);
                $passed = false;
            }
        } catch (Throwable) {
            fwrite(STDERR, '[ALERTA] No se pudo verificar la linea base final.'.PHP_EOL);
            $passed = false;
        }
    }

    if ($execute) {
        $passed = $passed && $rollbackSucceeded;
        echo $passed ? '[OK] ROLLBACK confirmado; no quedan filas de prueba.'.PHP_EOL
            : '[FAIL] Prueba no aprobada; revise antes de continuar.'.PHP_EOL;
    }
}

exit($passed ? 0 : 1);
