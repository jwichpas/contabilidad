<?php

declare(strict_types=1);

/**
 * Preflight estático para Fase 0. No necesita Laravel, Composer, ni conexión a BD.
 * Comprueba salvaguardas documentales y configuración; NO ejecuta pruebas contables.
 * Uso: php backend/scripts/phase0-preflight.php [--json]
 */
$backend = dirname(__DIR__);
$repository = dirname($backend);
$checks = [];
$warnings = [];

$read = static function (string $path): string {
    $content = @file_get_contents($path);
    return $content === false ? '' : $content;
};

$assert = static function (string $id, bool $passed, string $detail) use (&$checks): void {
    $checks[] = ['id' => $id, 'passed' => $passed, 'detail' => $detail];
};

$phpunit = $read($backend.'/phpunit.xml');
$databaseConfig = $read($backend.'/config/database.php');
$artisanAudit = $read($backend.'/app/Console/Commands/AuditPhaseZero.php');
$pestBootstrap = $read($backend.'/tests/Pest.php');
$plan = $read($repository.'/README-PLAN-MAESTRO.md');
$policy = $read($repository.'/docs/ARQUITECTURA-BD-LARAVEL.md');
$migrations = glob($backend.'/database/migrations/*.php') ?: [];

$assert('master_plan', $plan !== '' && str_contains($plan, 'FASE 0'), 'README-PLAN-MAESTRO.md presente');
$assert('laravel_schema_policy', $policy !== '' && str_contains($policy, 'migraciones Laravel'), 'Política de migraciones Laravel presente');
$assert('migration_files', count($migrations) >= 14, sprintf('%d archivos de migración Laravel encontrados', count($migrations)));
$assert(
    'phpunit_without_embedded_database_endpoint',
    $phpunit !== '' && preg_match('/<env\s+name="DB_(?:PASSWORD|HOST|PORT|USERNAME|DATABASE)"\s+value="[^"]*"/i', $phpunit) !== 1,
    'PHPUnit no contiene contraseña, host, usuario, puerto o BD fijados en elementos env'
);
$assert(
    'separate_admin_connection',
    str_contains($databaseConfig, "'url' => env('DB_MIGRATIONS_URL')") &&
    !str_contains($databaseConfig, "'url' => env('DB_MIGRATIONS_URL', env('DATABASE_URL'))"),
    'Conexión de migraciones no hereda DATABASE_URL del tráfico de aplicación'
);
$assert(
    'artisan_audit_read_only',
    str_contains($artisanAudit, 'SET TRANSACTION READ ONLY') &&
    str_contains($artisanAudit, "DB::connection('pgsql_migrations')") &&
    str_contains($artisanAudit, 'not_in_repository'),
    'Comando F0 requiere transacción de solo lectura y compara migraciones'
);
$assert(
    'artisan_audit_target',
    str_contains($artisanAudit, 'bnjwxphwtkhblvhsnylj'),
    'Comando F0 verifica el identificador del proyecto Supabase de desarrollo'
);
$assert(
    'pest_transaction_convention',
    str_contains($pestBootstrap, 'DatabaseTransactions::class'),
    'La suite Pest declara rollback transaccional (no garantiza ausencia de efectos externos)'
);

$roleMigration = $read($backend.'/database/migrations/0001_01_00_000000_setup_extensions_and_app_backend_role.php');
if (str_contains($roleMigration, 'app_backend_local_secret')) {
    $warnings[] = 'La migración inicial incluye una contraseña de fallback predecible para app_backend: remediar en Fase 1 sin reescribir migraciones aplicadas.';
}
$tenantTest = $read($backend.'/tests/Feature/Tenant/TenantIsolationTest.php');
if (str_contains($tenantTest, 'SET ROLE')) {
    $warnings[] = 'Las pruebas de aislamiento usan SET ROLE: verificar RESET ROLE y seguridad de la conexión antes de ejecutarlas sobre la base compartida.';
}
$currencyTest = $read($backend.'/tests/Feature/CompanyCurrencyConfigurationTest.php');
if (str_contains($currencyTest, '->delete()')) {
    $warnings[] = 'Las pruebas de moneda ejecutan DELETE: revisar su contención transaccional y posibles efectos secundarios antes de correr la suite.';
}

$failed = count(array_filter($checks, static fn (array $check): bool => !$check['passed']));
$result = [
    'phase' => 'F0',
    'kind' => 'STATIC_PREFLIGHT',
    'database_contacted' => false,
    'passed' => count($checks) - $failed,
    'failed' => $failed,
    'checks' => $checks,
    'warnings' => $warnings,
    'status' => 'PREFLIGHT_ONLY_NOT_PHASE_APPROVAL',
];
if (in_array('--json', $argv, true)) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} else {
    foreach ($checks as $check) {
        echo $check['passed'] ? '[OK] ' : '[FAIL] ', $check['id'], ': ', $check['detail'], PHP_EOL;
    }
    foreach ($warnings as $warning) {
        echo '[WARN] ', $warning, PHP_EOL;
    }
    printf("Resultado F0: %d OK, %d fallidas. No se ha conectado a PostgreSQL.\n", $result['passed'], $failed);
}
exit($failed === 0 ? 0 : 1);
