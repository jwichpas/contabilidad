<?php

declare(strict_types=1);

/**
 * Fase 0: flujo de login Sanctum con usuario de prueba efimero.
 *
 * Por defecto solo comprueba esquema, roles y conteos con SELECT.
 * Para ejecutar: php scripts/phase0-login-rollback.php --execute
 *
 * La prueba real INSERTa un usuario, una membresia, un token y auditoria
 * dentro de UNA transaccion de la conexion operacional; SIEMPRE ROLLBACK.
 * Los IDs se asignan explicitamente y NO se consume nextval de secuencias.
 * No modifica ningun usuario/empresa preexistente ni muestra tokens,
 * contrasenas o hashes. Las consultas admin son solo SELECT.
 *
 * Se verifican simultaneamente respuestas HTTP, autenticacion por Bearer,
 * listados por usuario y escritura de audit logs.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
/** @var \Illuminate\Contracts\Console\Kernel $console */
$console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$console->bootstrap();

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$execute = in_array('--execute', $argv, true);
if (count($argv) > 2 || (count($argv) === 2 && ! $execute)) {
    fwrite(STDERR, 'Uso: php scripts/phase0-login-rollback.php [--execute]'.PHP_EOL);
    exit(2);
}

$appCfg = (array) config('database.connections.pgsql', []);
$adminCfg = (array) config('database.connections.pgsql_migrations', []);
if (! $app->environment('local')
    || trim((string) ($appCfg['url'] ?? '')) !== ''
    || trim((string) ($adminCfg['url'] ?? '')) !== ''
    || ($appCfg['host'] ?? null) !== 'aws-1-ca-central-1.pooler.supabase.com'
    || (string) ($appCfg['port'] ?? '') !== '6543'
    || ($appCfg['username'] ?? null) !== 'app_backend.bnjwxphwtkhblvhsnylj'
    || ! in_array($adminCfg['host'] ?? '', [
        'aws-1-ca-central-1.pooler.supabase.com',
        'db.bnjwxphwtkhblvhsnylj.supabase.co',
    ], true)
    || ! in_array($adminCfg['username'] ?? '', [
        'postgres.bnjwxphwtkhblvhsnylj', 'postgres',
    ], true)) {
    fwrite(STDERR, '[BLOQUEO] Esta prueba solo admite conexiones aprobadas '
        .'de contabilidad-database en APP_ENV=local.'.PHP_EOL);
    exit(2);
}

$application = DB::connection('pgsql');
$admin = DB::connection('pgsql_migrations');
$countsSql = <<<'SQL'
    SELECT
        (SELECT count(*) FROM public.companies) AS companies,
        (SELECT count(*) FROM public.users) AS users,
        (SELECT count(*) FROM public.company_user) AS memberships,
        (SELECT count(*) FROM public.personal_access_tokens) AS tokens,
        (SELECT count(*) FROM public.audit_logs) AS audits,
        (SELECT count(*) FROM public.journal_entries) AS entries
    SQL;
$baseline = null;
$txnStarted = false;
$ok = false;
$rollbackOk = false;

try {
    $appRole = $application->selectOne('SELECT current_user AS role_name, current_database() AS db');
    $adminRole = $admin->selectOne('SELECT current_user AS role_name, current_database() AS db');
    if ($appRole?->role_name !== 'app_backend' || $appRole?->db !== 'postgres'
        || $adminRole?->role_name !== 'postgres' || $adminRole?->db !== 'postgres') {
        throw new RuntimeException('La identidad real de PostgreSQL no coincide con la configuracion esperada.');
    }

    $baseline = array_map('intval', (array) $admin->selectOne($countsSql));
    echo '[PREFLIGHT] Conteos: ', json_encode($baseline, JSON_THROW_ON_ERROR), PHP_EOL;
    if ($baseline['companies'] !== 1 || $baseline['users'] !== 1
        || $baseline['memberships'] !== 1) {
        throw new RuntimeException('Se esperaba una sola empresa, usuario y membresia de desarrollo.');
    }

    $existing = $admin->selectOne(<<<'SQL'
        SELECT cu.company_id
        FROM public.company_user cu
        JOIN public.companies c ON c.id=cu.company_id
        WHERE cu.is_active IS TRUE AND c.is_active IS TRUE LIMIT 1
        SQL);
    if ($existing === null) {
        throw new RuntimeException('No hay empresa con membresia activa para el usuario sintetico.');
    }
    $companyId = (int) $existing->company_id;

    // Confirma RLS efectivo antes de realizar prueba de autenticacion.
    $rls = $admin->selectOne(<<<'SQL'
        SELECT c.relrowsecurity, c.relforcerowsecurity
        FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname='company_user'
        SQL);
    if ($rls === null || ! $rls->relrowsecurity || ! $rls->relforcerowsecurity) {
        throw new RuntimeException('RLS de company_user no esta activo y forzado.');
    }

    if (! $execute) {
        echo '[OK] Preflight solo lectura. No se crearon usuarios, tokens ni auditorias.', PHP_EOL;
        echo '[PENDIENTE] Ejecutar solo en desarrollo: '
            .'php scripts/phase0-login-rollback.php --execute', PHP_EOL;
        exit(0);
    }

    // Evita persistencia de rate limiting/cache/sesion fuera de la transaccion.
    // Las solicitudes HTTP de prueba utilizaran almacenamiento solo en memoria.
    config([
        'cache.default' => 'array',
        'cache.limiter' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
    ]);

    $userId = random_int(110000000000, 119999999999);
    $membershipId = random_int(120000000000, 129999999999);
    $tokenId = random_int(130000000000, 139999999999);
    $auditIdBase = random_int(140000000000, 149999999000);
    $auditCounter = 0;
    $password = bin2hex(random_bytes(24));
    $email = 'f0-login-'.bin2hex(random_bytes(8)).'@example.invalid';

    $application->beginTransaction();
    $txnStarted = true;

    // Como Eloquent/Sanctum normalmente usa BIGSERIAL, fijamos los IDs
    // solo para esta ejecucion, antes del INSERT, sin consumir secuencias.
    $tokenModelClass = get_class((new User())->tokens()->getRelated());
    $tokenModelClass::creating(static function ($model) use ($tokenId): void {
        $model->setAttribute('id', $tokenId);
    });
    AuditLog::creating(static function (AuditLog $model) use ($auditIdBase, &$auditCounter): void {
        $auditCounter++;
        $model->setAttribute('id', $auditIdBase + $auditCounter);
    });

    $application->insert(<<<'SQL'
        INSERT INTO public.users
           (id, name, email, password, current_company_id, created_at, updated_at)
        VALUES (?::bigint, 'F0 Usuario Temporal (ROLLBACK)', ?, ?, NULL, now(), now())
        SQL, [$userId, $email, Hash::make($password)]);

    $application->selectOne('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $companyId]);
    $application->insert(<<<'SQL'
        INSERT INTO public.company_user
            (id, company_id, user_id, role, is_active, created_at, updated_at)
        VALUES (?::bigint, ?::bigint, ?::bigint, 'AUDITOR', TRUE, now(), now())
        SQL, [$membershipId, $companyId, $userId]);
    $application->update(
        'UPDATE public.users SET current_company_id = ?::bigint WHERE id = ?::bigint',
        [$companyId, $userId]
    );

    // Simula la peticion sin contexto SQL previo.
    $application->selectOne("SELECT set_config('app.current_company_id', '', true)");
    $application->selectOne("SELECT set_config('app.current_user_id', '', true)");

    /** @var HttpKernel $http */
    $http = $app->make(HttpKernel::class);
    $request = Request::create(
        '/api/v1/auth/login',
        'POST',
        ['email' => $email, 'password' => $password, 'device_name' => 'phase0-rollback']
    );
    $request->headers->set('Accept', 'application/json');
    $response = $http->handle($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('El login sintetico no devolvio HTTP 200 (recibido: '
            .$response->getStatusCode().').');
    }

    $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $token = $data['data']['token'] ?? null;
    $companies = $data['data']['companies'] ?? null;
    if (! is_string($token) || $token === ''
        || ! is_array($companies)
        || ! in_array($companyId, array_map('intval', array_column($companies, 'id')), true)) {
        throw new RuntimeException('Login no devolvio token y empresa autorizada.');
    }
    unset($data, $password, $request, $response);

    $tokenCount = (int) $application->selectOne(
        'SELECT count(*) AS total FROM public.personal_access_tokens WHERE tokenable_id = ?::bigint',
        [$userId]
    )->total;
    if ($tokenCount !== 1) {
        throw new RuntimeException('La emision de Sanctum no creo exactamente un token transitorio.');
    }
    echo '[CHECK] Login 200: token Sanctum y empresa autorizada obtenidos sin imprimir secretos.', PHP_EOL;

    $application->selectOne("SELECT set_config('app.current_company_id', '', true)");
    $application->selectOne("SELECT set_config('app.current_user_id', '', true)");
    $authedRequest = Request::create('/api/v1/tenant/companies', 'GET');
    $authedRequest->headers->set('Accept', 'application/json');
    $authedRequest->headers->set('Authorization', 'Bearer '.$token);
    $authedResponse = $http->handle($authedRequest);
    if ($authedResponse->getStatusCode() !== 200) {
        throw new RuntimeException('La llamada por token Bearer no devolvio HTTP 200.');
    }
    $authedData = json_decode((string) $authedResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
    if (! in_array($companyId, array_map('intval', array_column($authedData['data'] ?? [], 'id')), true)) {
        throw new RuntimeException('Bearer no permitio consultar la empresa propia.');
    }
    unset($token, $authedRequest, $authedResponse, $authedData);
    echo '[CHECK] Bearer autentica GET /tenant/companies de la empresa autorizada.', PHP_EOL;

    $application->selectOne('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $companyId]);
    $successAudit = (int) $application->selectOne(
        "SELECT count(*) AS total FROM public.audit_logs WHERE user_id = ?::bigint AND event_type = 'AUTH_LOGIN'",
        [$userId]
    )->total;
    if ($successAudit !== 1) {
        throw new RuntimeException('No se registro exactamente una auditoria AUTH_LOGIN.');
    }
    echo '[CHECK] Auditoria AUTH_LOGIN creada dentro de la transaccion.', PHP_EOL;
    $ok = true;
} catch (RuntimeException $e) {
    fwrite(STDERR, '[BLOQUEO] '.$e->getMessage().PHP_EOL);
} catch (Throwable $e) {
    // No imprimir excepciones SQL: pueden incluir campos privados o secretos.
    fwrite(STDERR, '[ERROR] La prueba de login no se completo ('
        .preg_replace('/[^A-Z0-9]/', '', (string) $e->getCode()).'). '
        .'No divulgar respuestas HTTP ni tokens; revisar en local.'.PHP_EOL);
} finally {
    if ($txnStarted) {
        try {
            if ($application->transactionLevel() > 0) {
                $application->rollBack();
            }
            $rollbackOk = $application->transactionLevel() === 0;
        } catch (Throwable) {
            fwrite(STDERR, '[ALERTA] No fue posible confirmar ROLLBACK.'.PHP_EOL);
            $rollbackOk = false;
        }
    }

    if ($baseline !== null) {
        try {
            $after = array_map('intval', (array) $admin->selectOne($countsSql));
            echo '[VERIFY] Conteos finales: ', json_encode($after, JSON_THROW_ON_ERROR), PHP_EOL;
            if ($baseline !== $after) {
                fwrite(STDERR, '[ALERTA] Los conteos cambiaron; detener validaciones.'.PHP_EOL);
                $ok = false;
            }
        } catch (Throwable) {
            fwrite(STDERR, '[ALERTA] No se pudieron comparar los conteos finales.'.PHP_EOL);
            $ok = false;
        }
    }

    if ($execute) {
        $ok = $ok && $rollbackOk;
        echo $ok ? '[PASS] Login+Bearer+auditoria con ROLLBACK confirmado.'.PHP_EOL
            : '[FAIL] Login no aprobado; revisar antes de reintentar.'.PHP_EOL;
    }
}
exit($ok ? 0 : 1);
