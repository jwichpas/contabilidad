<?php

declare(strict_types=1);

/**
 * F0 — prueba controlada de RLS desde la conexión operacional app_backend.
 *
 * Ejecutar desde backend/:
 *   php scripts/phase0-audit-tenant-read-only.php
 *
 * Una única transacción PostgreSQL READ ONLY. Sin INSERT, UPDATE, DELETE,
 * seeders, migraciones ni SET ROLE. Las configuraciones set_config(..., true)
 * son locales a la transacción y se revierten al hacer ROLLBACK.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$success = false;

try {
    $db->beginTransaction();
    $db->statement('SET TRANSACTION READ ONLY');

    $identity = $db->selectOne('SELECT current_user AS role_name, current_database() AS database_name');
    if ($identity === null || $identity->role_name !== 'app_backend'
        || $identity->database_name !== 'postgres') {
        throw new \RuntimeException('La conexion no usa el rol operativo app_backend de PostgreSQL.');
    }
    echo '[OK] Conexion operacional autenticada como app_backend.', PHP_EOL;

    $company = $db->selectOne('SELECT id FROM public.companies ORDER BY id LIMIT 1');
    if ($company === null) {
        throw new \RuntimeException('No existe una empresa de desarrollo para verificar visibilidad RLS.');
    }
    $companyId = (int) $company->id;

    $read = static function () use ($db, $companyId): array {
        $account = $db->selectOne(<<<'SQL'
            SELECT count(*) AS total,
                   count(*) FILTER (WHERE company_id <> ?::bigint) AS other_company_rows
            FROM public.accounting_accounts
            SQL, [$companyId]);
        $ledger = $db->selectOne(<<<'SQL'
            SELECT count(*) AS total,
                   count(*) FILTER (WHERE company_id <> ?::bigint) AS other_company_rows
            FROM public.journal_entries
            SQL, [$companyId]);
        return [
            'accounts' => (int) $account->total,
            'accounts_other' => (int) $account->other_company_rows,
            'entries' => (int) $ledger->total,
            'entries_other' => (int) $ledger->other_company_rows,
        ];
    };

    // La configuración vacía simula una solicitud sin empresa activa.
    $db->selectOne("SELECT set_config('app.current_company_id', '', true)");
    $withoutCompany = $read();
    echo '[CHECK] Sin contexto: cuentas=', $withoutCompany['accounts'],
         ', asientos=', $withoutCompany['entries'], PHP_EOL;
    if ($withoutCompany['accounts'] !== 0 || $withoutCompany['entries'] !== 0) {
        throw new \RuntimeException('RLS permitio filas sin empresa activa.');
    }

    // Identificador imposible como control negativo; no requiere escribir una empresa falsa.
    $db->selectOne("SELECT set_config('app.current_company_id', '-1', true)");
    $invalidCompany = $read();
    echo '[CHECK] Empresa inexistente: cuentas=', $invalidCompany['accounts'],
         ', asientos=', $invalidCompany['entries'], PHP_EOL;
    if ($invalidCompany['accounts'] !== 0 || $invalidCompany['entries'] !== 0) {
        throw new \RuntimeException('RLS permitio filas de empresa ajena.');
    }

    // Con contexto real: debe haber datos, todos vinculados al mismo company_id.
    $db->selectOne("SELECT set_config('app.current_company_id', ?, true)", [(string) $companyId]);
    $validCompany = $read();
    echo '[CHECK] Empresa activa: cuentas=', $validCompany['accounts'],
         ', asientos=', $validCompany['entries'], PHP_EOL;
    if ($validCompany['accounts'] < 1 || $validCompany['entries'] < 1) {
        throw new \RuntimeException(
            'No se encontraron registros de referencia para comprobar la visibilidad autorizada.'
        );
    }
    if ($validCompany['accounts_other'] !== 0 || $validCompany['entries_other'] !== 0) {
        throw new \RuntimeException('RLS expuso registros de otra empresa.');
    }

    $success = true;
} catch (\RuntimeException $error) {
    fwrite(STDERR, '[BLOQUEO] '.$error->getMessage().PHP_EOL);
} catch (\Throwable) {
    // Evitar imprimir DSN, SQL o password desde PDO/QueryException.
    fwrite(STDERR, '[ERROR] La auditoria read-only no se completo.'
        .' Revise conexion pgsql y permisos de los objetos; no se alteraron datos.'.PHP_EOL);
} finally {
    try {
        if ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
    } catch (\Throwable) {
        fwrite(STDERR, '[WARN] No fue posible confirmar el cierre de la transaccion local.'.PHP_EOL);
        $success = false;
    }
}

if ($success) {
    echo '[PASS] RLS fail-closed comprobado con SELECT;'
        .' sin cambios persistentes en PostgreSQL.', PHP_EOL;
    exit(0);
}
exit(1);
