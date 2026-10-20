<?php

declare(strict_types=1);

/**
 * Alta CONTROLADA de un usuario Laravel Sanctum en empresa ya existente.
 *
 * NO crea ni sincroniza usuarios de Supabase Authentication (auth.users).
 * NO ejecuta seeders ni migraciones. Por defecto es SOLO LECTURA.
 *
 * Preflight:
 *   php scripts/phase0-provision-erp-user.php --email=correo@dominio.tld --company-id=1 --role=ACCOUNTANT
 *
 * Escritura excepcional (solo tras aprobar rol/nombre y disponer de contraseña nueva):
 *   ERP_PROVISION_APPROVED=YES_CREATE_NEW_ERP_USER
 *   ERP_PROVISION_PASSWORD=<SECRET_FROM_LOCAL_ENV>
 *   php scripts/phase0-provision-erp-user.php --email=... --company-id=1 --role=ACCOUNTANT --apply
 *
 * No enviar contraseñas como argumentos de proceso. No imprimir hashes/tokens.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
/** @var \Illuminate\Contracts\Console\Kernel $console */
$console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$console->bootstrap();

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$options = [
    'email' => null,
    'company-id' => null,
    'role' => null,
    'display-name' => null,
];
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
        continue;
    }
    if (! preg_match('/^--(email|company-id|role|display-name)=(.+)$/', $argument, $match)
        || ! array_key_exists($match[1], $options)
        || $options[$match[1]] !== null) {
        fwrite(STDERR, '[BLOQUEO] Argumento invalido o duplicado. Revise el uso en el archivo.'.PHP_EOL);
        exit(2);
    }
    $options[$match[1]] = $match[2];
}

$email = is_string($options['email']) ? strtolower(trim($options['email'])) : '';
$role = is_string($options['role']) ? $options['role'] : '';
$companyIdRaw = (string) ($options['company-id'] ?? '');
$displayName = is_string($options['display-name'])
    ? trim($options['display-name'])
    : 'Contador de Desarrollo';

if (! filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($email) > 255
    || ! preg_match('/^[1-9][0-9]*$/', $companyIdRaw)
    || $companyIdRaw !== '1'
    || ! in_array($role, ['ACCOUNTANT', 'CHIEF_ACCOUNTANT', 'DATA_ENTRY', 'AUDITOR', 'ADMIN'], true)
    || $displayName === ''
    || strlen($displayName) > 150) {
    fwrite(STDERR, '[BLOQUEO] Email/empresa/rol/nombre invalidos. Empresa permitida: 1.'.PHP_EOL);
    exit(2);
}
$companyId = (int) $companyIdRaw;

$cfg = (array) config('database.connections.pgsql', []);
if (! $app->environment('local')
    || trim((string) ($cfg['url'] ?? '')) !== ''
    || ($cfg['host'] ?? '') !== 'aws-1-ca-central-1.pooler.supabase.com'
    || (string) ($cfg['port'] ?? '') !== '6543'
    || ($cfg['username'] ?? '') !== 'app_backend.bnjwxphwtkhblvhsnylj') {
    fwrite(STDERR, '[BLOQUEO] Solo se permite el entorno local Supabase aprobado (app_backend).'.PHP_EOL);
    exit(2);
}

$password = null;
if ($apply) {
    if (getenv('ERP_PROVISION_APPROVED') !== 'YES_CREATE_NEW_ERP_USER') {
        fwrite(STDERR, '[BLOQUEO] Falta autorizacion local ERP_PROVISION_APPROVED.'.PHP_EOL);
        exit(2);
    }
    $password = getenv('ERP_PROVISION_PASSWORD');
    if (! is_string($password)
        || strlen($password) < 16
        || strlen($password) > 128
        || ! preg_match('/[a-z]/', $password)
        || ! preg_match('/[A-Z]/', $password)
        || ! preg_match('/[0-9]/', $password)
        || ! preg_match('/[^a-zA-Z0-9]/', $password)
        || str_contains(strtolower($password), explode('@', $email)[0])) {
        fwrite(STDERR, '[BLOQUEO] Use contrasena NUEVA de 16-128 caracteres, '
            .'mayusculas, minusculas, numeros y simbolos, sin incluir el correo.'.PHP_EOL);
        exit(2);
    }
}

$db = DB::connection('pgsql');
$started = false;
$committed = false;
$phase = 'preflight';
$failed = false;
try {
    $identity = $db->selectOne('SELECT current_user AS role_name, current_database() AS db_name');
    if ($identity?->role_name !== 'app_backend' || $identity?->db_name !== 'postgres') {
        throw new RuntimeException('Identidad de base de datos inesperada.');
    }

    $company = $db->selectOne(
        'SELECT id, business_name FROM public.companies WHERE id = ?::bigint AND is_active IS TRUE',
        [$companyId]
    );
    if ($company === null) {
        throw new RuntimeException('Empresa objetivo no existe o esta inactiva.');
    }
    $existing = (int) $db->selectOne(
        'SELECT count(*) AS total FROM public.users WHERE lower(email) = ?', [$email]
    )->total;
    if ($existing !== 0) {
        throw new RuntimeException('El usuario ya existe: no crear duplicados ni restablecer contrasenas.');
    }

    $baseline = (array) $db->selectOne(<<<'SQL'
        SELECT
          (SELECT count(*) FROM public.companies) AS companies,
          (SELECT count(*) FROM public.users) AS users,
          (SELECT count(*) FROM public.company_user) AS memberships,
          (SELECT count(*) FROM public.personal_access_tokens) AS tokens,
          (SELECT count(*) FROM public.journal_entries) AS entries,
          (SELECT count(*) FROM public.migrations) AS migrations
        SQL);
    $baseline = array_map('intval', $baseline);
    if ($baseline['companies'] !== 1
        || $baseline['users'] !== 1
        || $baseline['memberships'] !== 1
        || $baseline['migrations'] !== 18) {
        throw new RuntimeException('La linea base cambio; detener alta y revisar cambios concurrentes.');
    }

    $policy = $db->selectOne(<<<'SQL'
        SELECT relrowsecurity, relforcerowsecurity
        FROM pg_catalog.pg_class
        WHERE oid = 'public.company_user'::regclass
        SQL);
    if (! $policy?->relrowsecurity || ! $policy?->relforcerowsecurity) {
        throw new RuntimeException('RLS de membresias no esta habilitado y forzado.');
    }

    echo '[PREFLIGHT] Conexion app_backend y RLS forzado: OK.', PHP_EOL;
    echo '[PREFLIGHT] Empresa existente: ', (string) $company->business_name,
        ' (ID ', $companyId, ').', PHP_EOL;
    echo '[PREFLIGHT] Alta solicitada: 1 usuario nuevo, 1 membresia activa, rol ', $role, '.', PHP_EOL;
    echo '[PREFLIGHT] Conteos actuales: ', json_encode($baseline, JSON_THROW_ON_ERROR), PHP_EOL;

    if (! $apply) {
        echo '[OK] MODO SOLO LECTURA. Sin usuarios creados ni contrasenas cambiadas.', PHP_EOL;
        echo '[PENDIENTE] Autorizar nombre/rol y configurar contrasena NUEVA antes de --apply.', PHP_EOL;
        exit(0);
    }

    $phase = 'crear_usuario';
    $db->beginTransaction();
    $started = true;

    // Revalidar de forma atomica con bloqueo liviano sobre la empresa objetivo.
    $active = $db->selectOne(
        'SELECT id FROM public.companies WHERE id = ?::bigint AND is_active IS TRUE FOR SHARE',
        [$companyId]
    );
    if ($active === null) {
        throw new RuntimeException('Empresa desactivada durante el alta.');
    }
    $stillMissing = (int) $db->selectOne(
        'SELECT count(*) AS total FROM public.users WHERE lower(email) = ?', [$email]
    )->total === 0;
    if (! $stillMissing) {
        throw new RuntimeException('Ya se creo el usuario en otra solicitud.');
    }

    $user = new User();
    $user->name = $displayName;
    $user->email = $email;
    $user->password = Hash::make($password);
    $user->current_company_id = null;
    $user->save();

    $phase = 'vincular_empresa';
    // Solo el contexto local de ESTA transaccion permite INSERT bajo RLS.
    $db->selectOne(
        "SELECT set_config('app.current_company_id', ?, true)", [(string) $companyId]
    );
    $db->insert(<<<'SQL'
        INSERT INTO public.company_user
            (company_id, user_id, role, is_active, created_at, updated_at)
        VALUES (?::bigint, ?::bigint, ?, TRUE, now(), now())
        SQL, [$companyId, (int) $user->id, $role]);

    // La FK compuesta valida que current_company_id pertenezca al usuario.
    $user->current_company_id = $companyId;
    $user->save();

    $phase = 'verificar_transaccion';
    $result = $db->selectOne(<<<'SQL'
        SELECT count(*) AS linked_count
        FROM public.company_user cu
        JOIN public.users u ON u.id = cu.user_id
        WHERE cu.company_id = ?::bigint
          AND cu.user_id = ?::bigint
          AND cu.role = ?
          AND cu.is_active IS TRUE
          AND u.current_company_id = cu.company_id
        SQL, [$companyId, (int) $user->id, $role]);
    if ((int) ($result?->linked_count ?? 0) !== 1) {
        throw new RuntimeException('La verificacion transaccional de la membresia fallo.');
    }

    $after = (array) $db->selectOne(<<<'SQL'
        SELECT
          (SELECT count(*) FROM public.companies) AS companies,
          (SELECT count(*) FROM public.users) AS users,
          (SELECT count(*) FROM public.company_user) AS memberships,
          (SELECT count(*) FROM public.personal_access_tokens) AS tokens,
          (SELECT count(*) FROM public.journal_entries) AS entries,
          (SELECT count(*) FROM public.migrations) AS migrations
        SQL);
    $after = array_map('intval', $after);
    if ($after['users'] !== $baseline['users'] + 1
        || $after['memberships'] !== $baseline['memberships'] + 1
        || $after['companies'] !== $baseline['companies']
        || $after['entries'] !== $baseline['entries']
        || $after['tokens'] !== $baseline['tokens']
        || $after['migrations'] !== $baseline['migrations']) {
        throw new RuntimeException('Conteos no corresponden exclusivamente al alta prevista.');
    }

    $phase = 'commit';
    $db->commit();
    $committed = true;
    echo '[OK] Alta confirmada: usuario y membresia ', $role, ' en empresa ', $companyId, '.', PHP_EOL;
    echo '[VERIFY] Conteos posteriores: ', json_encode($after, JSON_THROW_ON_ERROR), PHP_EOL;
    echo '[INFO] Use su contrasena NUEVA en el formulario Laravel (no en Supabase Auth).', PHP_EOL;
} catch (QueryException $e) {
    $raw = (string) $e->getCode();
    $state = preg_match('/^[0-9A-Z]{5}$/', $raw) ? $raw : 'UNKNOWN';
    fwrite(STDERR, '[ERROR] Etapa='.$phase.' SQLSTATE='.$state.'; '
        .'no se muestran consultas ni contrasenas.'.PHP_EOL);
    $failed = true;
} catch (Throwable $e) {
    fwrite(STDERR, '[BLOQUEO] Etapa='.$phase.' '.(
        $e instanceof RuntimeException ? $e->getMessage() : 'Error no previsto; revisar localmente.'
    ).PHP_EOL);
    $failed = true;
} finally {
    if ($started && ! $committed && $db->transactionLevel() > 0) {
        try {
            $db->rollBack();
            echo '[VERIFY] ROLLBACK por error; no realizar reintentos ciegos.', PHP_EOL;
        } catch (Throwable) {
            fwrite(STDERR, '[ALERTA] No se pudo confirmar rollback: verificar conteos antes de seguir.'.PHP_EOL);
        }
    }
}

if ($failed) {
    exit(1);
}
