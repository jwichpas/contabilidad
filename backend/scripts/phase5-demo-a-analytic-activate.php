<?php
declare(strict_types=1);

/**
 * F5: audited activation of the already-validated DEMO A analytical matrix.
 *
 * Offline default: --plan. One explicit --activate-demo-a=<64-char SHA256>
 * delegates every change to AnalyticMatrixPromotionService, which enforces:
 * DEV-only host, owner role, 227x2 coverage, exact SHA, exclusive advisory
 * lock, DRAFT->ACTIVE status and mandatory audit in the same transaction.
 *
 * This is a one-time operational command, NOT a seeder or a migration.
 * It never creates journal entries, reprocesses old expenses or touches B/C.
 */
$arg = $argv[1] ?? '--plan';
if ($argc === 2 && $arg === '--plan') {
    echo "[PLAN] Solo DEMO A 20999990011; transicion auditada DRAFT -> ACTIVE.\n";
    echo "[PLAN] SHA256 debe coincidir con el preflight. Sin ejecutar cambios.\n";
    echo "[PLAN] Servicio protegido para Neon DEV PG18, 63 migraciones.\n";
    echo "[DRY-RUN] No hay conexion SQL ni escritura.\n";
    exit(0);
}
if ($argc !== 2 || preg_match('/^--activate-demo-a=([a-f0-9]{64})$/D', $arg, $match) !== 1) {
    fwrite(STDERR, "[USO] php scripts/phase5-demo-a-analytic-activate.php --plan\n");
    fwrite(STDERR, "[USO] php scripts/phase5-demo-a-analytic-activate.php --activate-demo-a=<sha256 preflight>\n");
    exit(2);
}

$root = dirname(__DIR__);
if (! is_file($root.'/.env') || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[BLOQUEO] Falta backend/.env o existe cache de configuración.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $result = app(\App\Services\Accounting\AnalyticMatrixPromotionService::class)->transition(
        '20999990011', 3000000000201, $match[1], 'activate'
    );
    if ($result['company_id'] !== 3000000000101
        || $result['from'] !== 'DRAFT' || $result['to'] !== 'ACTIVE'
        || ! hash_equals($match[1], $result['sha256'])
        || $result['audit_id'] <= 0) {
        throw new RuntimeException('Transición terminada pero recibo inesperado; revisar audit_logs.');
    }
    echo '[PASS] DEMO A: DRAFT -> ACTIVE; matrix SHA256 '. $result['sha256'] ."\n";
    echo '[PASS] Auditoria #'.$result['audit_id'].'; version #'.$result['version_id']."\n";
    echo "[INFO] Solo afectó estado de versión y auditoría. No creó ni modificó asientos.\n";
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, '[BLOQUEO] '.$exception->getMessage()."\n");
    exit(1);
}
