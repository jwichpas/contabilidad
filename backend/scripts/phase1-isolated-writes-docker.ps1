#requires -Version 5.1
<#
  F1.4 - PostgreSQL 18 temporal para validar aislamiento real de escrituras.
  Exclusivamente Docker local; no ejecuta ningun SQL contra Supabase.
  - Levanta PostgreSQL 18 (por defecto) o 17 con puerto host 127.0.0.1.
  - Selecciona las migraciones de esquema una a una SIN mover archivos.
  - Corre migraciones de ESQUEMA Laravel y SQL DML A/B con ROLLBACK.
  - Corre pruebas Pest HTTP de roles en PostgreSQL efimero como app_backend.
  - Restaura variables de entorno y elimina el contenedor SIEMPRE.

  Invocar desde backend/:
    .\scripts\phase1-isolated-writes-docker.ps1
    .\scripts\phase1-isolated-writes-docker.ps1 -PostgresVersion 17
#>
param(
    [ValidateSet('17','18')]
    [string]$PostgresVersion = '18'
)

$ErrorActionPreference = 'Stop'

$backend = Split-Path -Parent $PSScriptRoot
$root = Split-Path -Parent $backend
$dockerName = 'f1-write-' + ([Guid]::NewGuid().ToString('N')).Substring(0,12)
$junitPath = Join-Path ([IO.Path]::GetTempPath()) ($dockerName + '-junit.xml')
$adminPassword = 'f1-' + [Guid]::NewGuid().ToString('N')
$appPassword = 'app-f1-' + [Guid]::NewGuid().ToString('N')
$guardToken = [Guid]::NewGuid().ToString('N')
$supabaseOnly = @(
    '2026_10_19_000000_restrict_supabase_data_api_grants.php',
    '2026_10_19_010000_add_company_economic_profile.php',
    '2026_10_20_000000_rotate_app_backend_credential.php',
    '2026_10_20_010000_scope_company_listing_to_authenticated_user.php',
    '2026_10_20_235900_create_dev_company_b_via_laravel.php'
)
$runContainer = $false
$passed = $false
$initialLocation = Get-Location
$originalEnv = @{}

function Invoke-NativeChecked {
    param([string]$Program, [string[]]$Arguments)
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw ($Program + ' termino con codigo ' + $LASTEXITCODE)
    }
}

function Set-TestEnvironment {
    param([hashtable]$Values)
    foreach ($key in $Values.Keys) {
        if (-not $originalEnv.ContainsKey($key)) {
            $originalEnv[$key] = [Environment]::GetEnvironmentVariable($key,'Process')
        }
        [Environment]::SetEnvironmentVariable($key,[string]$Values[$key],'Process')
    }
}

try {
    Set-Location $backend

    if (-not (Test-Path 'vendor/autoload.php') -or
        -not (Test-Path 'tests/security/phase1-real-cross-tenant-writes.sql')) {
        throw 'Faltan dependencias Composer o script RLS. Actualizar main y ejecutar composer install.'
    }
    foreach ($name in $supabaseOnly) {
        if (-not (Test-Path (Join-Path $backend ('database/migrations/' + $name)))) {
            throw ('Falta migracion Supabase exclusiva: ' + $name)
        }
    }
    if (Test-Path 'bootstrap/cache/config.php') {
        throw 'Existe bootstrap/cache/config.php; no iniciar para evitar conexiones cacheadas a Supabase.'
    }

    $phpVersion = & php -r 'echo PHP_VERSION;'
    if ($LASTEXITCODE -ne 0 -or $phpVersion -notmatch '^8\.5\.[0-9]+') {
        throw ('Requiere PHP 8.5.x: ' + $phpVersion)
    }

    $dockerOS = (& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $dockerOS -ne 'linux') {
        throw 'Docker Desktop debe ejecutar contenedores Linux.'
    }
    Write-Host ('[AISLAMIENTO] Iniciando PostgreSQL ' + $PostgresVersion + ' temporal en Docker (solo loopback).')
    Invoke-NativeChecked 'docker' @(
        'run','--detach','--name',$dockerName,
        '--publish','127.0.0.1::5432',
        '--env',('POSTGRES_PASSWORD=' + $adminPassword),
        '--env','POSTGRES_DB=contabilidad_ci',
        ('postgres:' + $PostgresVersion + '-alpine')
    )
    $runContainer = $true

    $portLine = (& docker port $dockerName '5432/tcp' | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $portLine -notmatch '^127\.0\.0\.1:(\d+)$') {
        throw ('Docker no publico exclusivamente 127.0.0.1: ' + $portLine)
    }
    $port = $Matches[1]

    $ready = $false
    for ($i=0; $i -lt 40; $i++) {
        & docker exec $dockerName pg_isready -h 127.0.0.1 -U postgres -d contabilidad_ci 1>$null 2>$null
        if ($LASTEXITCODE -eq 0) { $ready=$true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw ('PostgreSQL ' + $PostgresVersion + ' temporal no respondio.') }

    # Marca aleatoria exclusiva del contenedor. Cualquier conexion accidental a
    # Supabase fallara al no encontrar esta tabla o token.
    $guardSql = "CREATE TABLE public.f1_ci_runtime_guard (token text NOT NULL); " +
                "INSERT INTO public.f1_ci_runtime_guard(token) VALUES ('$guardToken'); " +
                "CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN;"
    Invoke-NativeChecked 'docker' @(
        'exec', $dockerName, 'psql', '-U','postgres','-d','contabilidad_ci',
        '-X','-v','ON_ERROR_STOP=1','-c',$guardSql
    )

    $adminUrl = 'pgsql://postgres:' + $adminPassword + '@127.0.0.1:' + $port + '/contabilidad_ci'
    $appUrl = 'pgsql://app_backend:' + $appPassword + '@127.0.0.1:' + $port + '/contabilidad_ci'

    Set-TestEnvironment @{
        APP_ENV='testing'
        APP_DEBUG='false'
        APP_KEY='base64:XQ9v12345678901234567890123456789012345678='
        DB_CONNECTION='pgsql'
        DB_HOST='127.0.0.1'
        DB_PORT=$port
        DB_DATABASE='contabilidad_ci'
        DB_USERNAME='postgres'
        DB_PASSWORD=$adminPassword
        DB_URL=$adminUrl
        DATABASE_URL=$adminUrl
        DB_SSLMODE='disable'
        DB_EMULATE_PREPARES='false'
        DB_MIGRATIONS_URL=$adminUrl
        DB_MIGRATIONS_HOST='127.0.0.1'
        DB_MIGRATIONS_PORT=$port
        DB_MIGRATIONS_DATABASE='contabilidad_ci'
        DB_MIGRATIONS_USERNAME='postgres'
        DB_MIGRATIONS_PASSWORD=$adminPassword
        DB_MIGRATIONS_SSLMODE='disable'
        DB_APP_BACKEND_ROLE_PASSWORD=$appPassword
        F1_GUARD_TOKEN=$guardToken
        CACHE_STORE='array'
        SESSION_DRIVER='array'
        QUEUE_CONNECTION='sync'
    }

    $guardCode = @'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection("pgsql_migrations");
$config = $db->getConfig();
if (($config["host"] ?? "") !== "127.0.0.1"
    || (string)($config["port"] ?? "") !== (string)getenv("DB_MIGRATIONS_PORT")
    || ($config["database"] ?? "") !== "contabilidad_ci"
    || ($config["username"] ?? "") !== "postgres"
    || $db->selectOne("SELECT current_database() AS db")->db !== "contabilidad_ci"
    || $db->selectOne("SELECT token FROM public.f1_ci_runtime_guard LIMIT 1")->token !== getenv("F1_GUARD_TOKEN")) {
    fwrite(STDERR,"BLOQUEO: la conexion admin no es el PostgreSQL desechable\n");
    exit(81);
}
echo "[PASS] Conexion administrativa verificada contra Docker efimero\n";
'@
    Invoke-NativeChecked 'php' @('-r',$guardCode)

    # Migrate --path=<file> usa los archivos reales versionados y NO
    # mueve ni borra migraciones de Supabase del working tree del usuario.
    $schemaMigrations = @(
        Get-ChildItem (Join-Path $backend 'database/migrations') -Filter '*.php' |
            Where-Object { $supabaseOnly -notcontains $_.Name } |
            Sort-Object Name
    )
    if ($schemaMigrations.Count -lt 14) {
        throw ('Se esperaban al menos 14 migraciones de esquema; halladas: ' + $schemaMigrations.Count)
    }
    Write-Host ('[MIGRACIONES] Aplicando ' + $schemaMigrations.Count + ' migraciones al Docker efimero.')
    foreach ($migration in $schemaMigrations) {

        $relativePath = 'database/migrations/' + $migration.Name
        Write-Host ('[MIGRACION] ' + $migration.BaseName)
        Invoke-NativeChecked 'php' @(
            'artisan','migrate','--database=pgsql_migrations',
            ('--path=' + $relativePath),'--force','--no-interaction'
        )

        # Evitar PASS falsos cuando Artisan imprime FAIL pero devuelve exit 0.
        # Debe constar exactamente una fila en la tabla migrations del Docker.
        $safeName = $migration.BaseName
        if ($safeName -notmatch '^[A-Za-z0-9_]+$') {
            throw ('Nombre de migracion inesperado: ' + $safeName)
        }
        $countSql = "SELECT count(*) FROM public.migrations WHERE migration = '$safeName'"
        $rowCount = (& docker exec $dockerName 'psql' '-U' 'postgres' '-d' 'contabilidad_ci' '-X' '-A' '-t' '-v' 'ON_ERROR_STOP=1' '-c' $countSql 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -ne 0 -or $rowCount -ne '1') {
            throw ('Migracion NO registrada en Docker: ' + $safeName + '; conteo=' + $rowCount)
        }
    }

    Write-Host '[PASS] Todas las migraciones de esquema quedaron registradas en PostgreSQL temporal.'
    Write-Host '[FUNCIONES] Validando cinco search_path fijos luego de migraciones.'
    Invoke-NativeChecked 'docker' @(
        'cp',(Join-Path $backend 'tests/security/phase1-search-path-rehearsal.sql'),
        ($dockerName + ':/tmp/f1-function-search-path.sql')
    )
    Invoke-NativeChecked 'docker' @(
        'exec','--env',('PGPASSWORD=' + $adminPassword),$dockerName,
        'psql','-h','127.0.0.1','-U','postgres','-d','contabilidad_ci',
        '-X','-v','ON_ERROR_STOP=1','-f','/tmp/f1-function-search-path.sql'
    )

    Write-Host '[RLS] Probando INSERT, UPDATE y DELETE A/B sobre tablas reales.'
    Invoke-NativeChecked 'docker' @(
        'cp',(Join-Path $backend 'tests/security/phase1-real-cross-tenant-writes.sql'),
        ($dockerName + ':/tmp/f1-write.sql')
    )
    Invoke-NativeChecked 'docker' @(
        'exec','--env',('PGPASSWORD=' + $adminPassword),$dockerName,
        'psql','-h','127.0.0.1','-U','postgres','-d','contabilidad_ci',
        '-X','-v','ON_ERROR_STOP=1','-f','/tmp/f1-write.sql'
    )

    # Validar la version realmente iniciada: nunca aceptar silenciosamente
    # una imagen distinta de la solicitada.
    $serverVersion = (& docker exec $dockerName 'psql' '-U' 'postgres' '-d' 'contabilidad_ci' '-X' '-A' '-t' '-v' 'ON_ERROR_STOP=1' '-c' 'SHOW server_version' 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $serverVersion -notmatch ('^' + [regex]::Escape($PostgresVersion) + '\.[0-9]+')) {
        throw ('Version PostgreSQL del Docker inesperada: ' + $serverVersion)
    }
    Write-Host ('[PASS] PostgreSQL efimero version ' + $serverVersion)

    # Comprobar tambien que Laravel opera exclusivamente en el contenedor
    # bajo el rol app_backend sin SUPERUSER ni BYPASSRLS.
    Invoke-NativeChecked 'docker' @(
        'exec',$dockerName,'psql','-U','postgres','-d','contabilidad_ci',
        '-X','-v','ON_ERROR_STOP=1',
        '-c','GRANT SELECT ON TABLE public.f1_ci_runtime_guard TO app_backend'
    )
    Set-TestEnvironment @{
        DB_USERNAME='app_backend'
        DB_PASSWORD=$appPassword
        DB_URL=$appUrl
        DATABASE_URL=$appUrl
        DB_EMULATE_PREPARES='true'
    }
    $appGuard = @'
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::connection("pgsql");
$c=$db->getConfig();
if (($c["host"] ?? "") !== "127.0.0.1"
    || (string)($c["port"] ?? "") !== (string)getenv("DB_PORT")
    || ($c["database"] ?? "") !== "contabilidad_ci"
    || $db->selectOne("SELECT current_user AS rol")->rol !== "app_backend"
    || $db->selectOne("SELECT token FROM public.f1_ci_runtime_guard LIMIT 1")->token !== getenv("F1_GUARD_TOKEN")) {
    fwrite(STDERR,"BLOQUEO: la conexion operativa no corresponde a Docker\n");
    exit(82);
}
echo "[PASS] Conexion app_backend sujeta a RLS en Docker efimero\n";
'@
    Invoke-NativeChecked 'php' @('-r',$appGuard)

    Write-Host '[HTTP] Ejecutando roles con escrituras de prueba solo en Docker.'
    # Algunos runners pueden imprimir FAIL y aun devolver exit 0 al invocar
    # Pest mediante Artisan. El JUnit es un segundo control obligatorio.
    Invoke-NativeChecked 'php' @(
        'artisan','test',
        'tests/Feature/Security/CompanyRoleAuthorizationTest.php',
        'tests/Feature/Security/TenantContextNestedTest.php',
        ('--log-junit=' + $junitPath)
    )
    if (-not (Test-Path -LiteralPath $junitPath)) {
        throw 'Pest no genero el reporte JUnit; NO se puede declarar PASS.'
    }
    try {
        [xml]$report = Get-Content -LiteralPath $junitPath -Raw -ErrorAction Stop
    }
    catch {
        throw ('Reporte JUnit invalido: ' + $_.Exception.Message)
    }

    $cases = @($report.SelectNodes('//testcase'))
    $failed = @($report.SelectNodes('//failure | //error'))
    $suites = @($report.SelectNodes('//testsuite'))
    $suiteHasFailures = $false
    foreach ($suite in $suites) {
        if ([int]$suite.GetAttribute('failures') -gt 0 -or
            [int]$suite.GetAttribute('errors') -gt 0) {
            $suiteHasFailures = $true
        }
    }

    # 10 tests de autorizacion + 2 tests de TenantContextNested.
    # Un reporte incompleto o vacio NO puede validar una puerta de seguridad.
    if ($cases.Count -ne 12 -or $failed.Count -ne 0 -or $suiteHasFailures) {
        throw ('Pest NO aprobado: total=' + $cases.Count +
            ', fallos/errores=' + $failed.Count +
            ', suiteConErrores=' + $suiteHasFailures +
            '. Revisar la salida de pruebas; el Docker sera eliminado.')
    }

    Write-Host '[PASS] Pest JUnit: 12 pruebas, 0 fallos/errores verificados.'

    # F1.14: DOS sesiones PostgreSQL reales contra este mismo Docker,
    # con periodo FOR UPDATE, NOWAIT 55P03 y dos asientos 10-00001/00002.
    # Se corre AL FINAL porque deja fixtures COMMIT unicamente en el
    # contenedor descartable, eliminado por el bloque finally.
    Write-Host '[CONCURRENCIA] Validando 2 conexiones contra PostgreSQL Docker.'
    Invoke-NativeChecked 'php' @('-l','scripts/phase1-journal-concurrency-docker.php')
    Invoke-NativeChecked 'php' @(
        'scripts/phase1-journal-concurrency-docker.php',
        '--probe=ephemeral-docker'
    )
    Write-Host '[PASS] F1.14 contencion FOR UPDATE 55P03 y correlativos distintos.'

    $passed = $true
    Write-Host '[PASS] F1.4: migraciones, funciones, RLS de auditoria y pruebas HTTP en PostgreSQL aislado.'
}
catch {
    Write-Host ('[FAIL] ' + $_.Exception.Message)
}
finally {
    foreach ($key in $originalEnv.Keys) {
        [Environment]::SetEnvironmentVariable($key,$originalEnv[$key],'Process')
    }
    if ($runContainer) {
        & docker rm --force $dockerName 1>$null 2>$null
        if ($LASTEXITCODE -eq 0) {
            Write-Host '[LIMPIEZA] Contenedor PostgreSQL efimero eliminado.'
        }
        else {
            Write-Host ('[WARN] No se pudo confirmar eliminacion de Docker: ' + $dockerName)
            $passed = $false
        }
    }
    if (Test-Path -LiteralPath $junitPath) {
        Remove-Item -LiteralPath $junitPath -Force -ErrorAction SilentlyContinue
    }
    Set-Location $initialLocation
}
if (-not $passed) { exit 1 }
