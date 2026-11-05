#requires -Version 5.1
<#
  Installer for the explicitly named, pre-created local PostgreSQL 18 Docker.
  NEVER reads remote DB credentials, never runs general migrations, and
  refuses a nonempty database. Plan is the default and makes NO changes.

  .\scripts\instalar-esquema-pg18-local.ps1 -Accion Plan
  .\scripts\instalar-esquema-pg18-local.ps1 -Accion Instalar
  .\scripts\instalar-esquema-pg18-local.ps1 -Accion Verificar
#>
param([ValidateSet('Plan','Instalar','Verificar')][string]$Accion='Plan')
$ErrorActionPreference='Stop'
$backend=Split-Path -Parent $PSScriptRoot
$previous=Get-Location
$name='contabilidad-pg18-local'
$volume='contabilidad-pg18-local-data'
$privateConfig=Join-Path $backend '.env.pg18.local'
$previousEnv=@{}
$success=$false
function SetLocalEnv([hashtable]$values){
    foreach($key in $values.Keys){
        if(-not $previousEnv.ContainsKey($key)){
            $previousEnv[$key]=[Environment]::GetEnvironmentVariable($key,'Process')
        }
        [Environment]::SetEnvironmentVariable($key,[string]$values[$key],'Process')
    }
}
function RunDocker([string[]]$parameters){
    & docker @parameters
    if($LASTEXITCODE -ne 0){throw 'Falló el comando de comprobación PostgreSQL local.'}
}
function SqlResult([string]$sql){
    $out=(& docker exec $name psql -U postgres -d contabilidad_local -X -A -t -F '|' -v ON_ERROR_STOP=1 -c $sql 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0){throw 'Falló la consulta de comprobación en el Docker local.'}
    return $out
}
function ReadPrivateConfig {
    if(-not (Test-Path -LiteralPath $privateConfig)){
        throw 'No existe backend/.env.pg18.local. Primero crear PostgreSQL con el script anterior.'
    }
    $values=@{}
    foreach($line in (Get-Content -LiteralPath $privateConfig)){
        if($line -eq '' -or $line.StartsWith('#')){continue}
        $parts=$line -split '=',2
        if($parts.Count -ne 2 -or $parts[0] -notmatch '^[A-Z][A-Z0-9_]*$'){
            throw 'Archivo privado PostgreSQL local mal formado.'
        }
        if($values.ContainsKey($parts[0])){throw 'Hay claves repetidas en el archivo local.'}
        $values[$parts[0]]=$parts[1]
    }
    return $values
}
function CheckContainer {
    $info=(& docker container inspect --format '{{index .Config.Labels "contabilidad.local"}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $info -ne 'pg18-dev'){
        throw 'Falta el contenedor local con la etiqueta original.'
    }
    $port=(& docker port $name '5432/tcp' 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $port -ne '127.0.0.1:55433'){
        throw 'El puerto de PostgreSQL no coincide con 127.0.0.1:55433.'
    }
    $mount=(& docker inspect --format '{{range .Mounts}}{{.Name}}|{{.Destination}}{{end}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $mount -ne ($volume+'|/var/lib/postgresql')){
        throw 'No coincide el volumen persistente esperado de PostgreSQL 18.'
    }
    $server=SqlResult "SELECT current_database(), current_user, current_setting('server_version_num')::integer"
    if($server -notmatch '^contabilidad_local[|]postgres[|]18[0-9]{4}$'){
        throw 'La identidad PostgreSQL no corresponde a la base local 18.'
    }
}
function ShowState {
    $state=SqlResult @'
SELECT
 (SELECT count(*) FROM pg_catalog.pg_class c
  JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='public' AND c.relkind IN ('r','p'))::integer,
 (SELECT count(*) FROM pg_catalog.pg_roles WHERE rolname='app_backend')::integer,
 (SELECT CASE WHEN to_regclass('public.migrations') IS NULL THEN 0 ELSE 1 END)::integer
'@
    Write-Host ('[ESTADO] Tablas public | roles app_backend | tabla migrations = '+$state)
    return $state
}
function VerifyInstalled {
    $result=SqlResult @'
SELECT
 (SELECT count(*) FROM public.migrations)::integer,
 (SELECT count(*) FROM public.currencies)::integer,
 (SELECT CASE WHEN rolcanlogin AND NOT rolsuper AND NOT rolbypassrls
  THEN 1 ELSE 0 END FROM pg_catalog.pg_roles WHERE rolname='app_backend')::integer,
 (SELECT CASE WHEN has_table_privilege('app_backend','public.currencies','INSERT')
  THEN 1 ELSE 0 END)::integer,
 (SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='public'
  AND c.relname IN ('company_user','journal_entries','journal_entry_lines','company_subdiaries','journal_post_idempotency')
  AND c.relrowsecurity AND c.relforcerowsecurity)::integer
'@
    if($result -ne '25|3|1|0|5'){
        throw 'Las verificaciones de esquema, monedas, rol restringido o FORCE RLS no aprobaron.'
    }
    Write-Host '[PASS] 25 migraciones, 3 monedas, app_backend limitado y 5 tablas FORCE RLS.'
}
try {
    Set-Location $backend
    $os=(& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $os -ne 'linux'){throw 'Docker Desktop Linux no está disponible.'}
    $local=ReadPrivateConfig
    if(($local['DB_HOST'] -ne '127.0.0.1') -or
       ($local['DB_PORT'] -ne '55433') -or
       ($local['DB_DATABASE'] -ne 'contabilidad_local') -or
       ($local['DB_MIGRATIONS_HOST'] -ne '127.0.0.1') -or
       ($local['DB_MIGRATIONS_PORT'] -ne '55433') -or
       ($local['DB_MIGRATIONS_DATABASE'] -ne 'contabilidad_local') -or
       ($local['DB_MIGRATIONS_USERNAME'] -ne 'postgres') -or
       ($local['DB_MIGRATIONS_SSLMODE'] -ne 'disable') -or
       [string]::IsNullOrWhiteSpace($local['DB_MIGRATIONS_PASSWORD'])){
        throw 'El archivo privado no corresponde a la instalación PG18 local esperada.'
    }
    CheckContainer
    if($Accion -eq 'Verificar'){
        VerifyInstalled
        $success=$true
    }elseif($Accion -eq 'Plan'){
        $state=ShowState
        if($state -eq '0|0|0'){
            Write-Host '[PLAN] Base vacía: lista para 25 migraciones revisadas.'
            Write-Host '[PLAN] No se realizó ninguna escritura ni conexión remota.'
        }else{
            Write-Host '[PLAN] La base no está vacía: no volver a instalar. Use -Accion Verificar.'
        }
        $success=$true
    }else{
        if(-not (Test-Path -LiteralPath 'vendor/autoload.php') -or
            (Test-Path -LiteralPath 'bootstrap/cache/config.php')){
            throw 'Falta vendor o hay bootstrap/cache/config.php; se bloquea instalación.'
        }
        $php=(& php -r 'echo PHP_VERSION;' | Out-String).Trim()
        if($LASTEXITCODE -ne 0 -or $php -notmatch '^8[.]5[.]'){throw 'Se requiere PHP 8.5.'}
        $state=ShowState
        if($state -ne '0|0|0' -or $local.ContainsKey('DB_APP_BACKEND_ROLE_PASSWORD')){
            throw 'La base no está vacía o existe una instalación anterior. No se sobrescribe.'
        }
        $rolePassword='app-'+[Guid]::NewGuid().ToString('N')
        $installToken=[Guid]::NewGuid().ToString('N')
        # Guard is created via docker exec on the identity-verified LOCAL
        # container, before any Laravel call. Remote DB is never a target.
        $createGuard="CREATE TABLE public.local_pg18_install_guard(token text NOT NULL); INSERT INTO public.local_pg18_install_guard VALUES ('$installToken'); CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN;"
        RunDocker @('exec',$name,'psql','-U','postgres','-d','contabilidad_local',
            '-X','-v','ON_ERROR_STOP=1','-c',$createGuard)
        SetLocalEnv @{
            APP_ENV='testing';APP_DEBUG='false'
            APP_KEY=([Guid]::NewGuid().ToString('N'))
            DB_CONNECTION='pgsql';DB_HOST='127.0.0.1';DB_PORT='55433'
            DB_DATABASE='contabilidad_local';DB_USERNAME='postgres'
            DB_PASSWORD=$local['DB_MIGRATIONS_PASSWORD']
            DB_URL='';DATABASE_URL='';DB_SSLMODE='disable';DB_EMULATE_PREPARES='false'
            DB_MIGRATIONS_URL='';DB_MIGRATIONS_HOST='127.0.0.1';DB_MIGRATIONS_PORT='55433'
            DB_MIGRATIONS_DATABASE='contabilidad_local';DB_MIGRATIONS_USERNAME='postgres'
            DB_MIGRATIONS_PASSWORD=$local['DB_MIGRATIONS_PASSWORD']
            DB_MIGRATIONS_SSLMODE='disable';DB_APP_BACKEND_ROLE_PASSWORD=$rolePassword
            LOCAL_PG18_INSTALL_TOKEN=$installToken;CACHE_STORE='array'
            SESSION_DRIVER='array';QUEUE_CONNECTION='sync'
        }
        # Verify Laravel's *resolved* connection against the local guard.
        # Sensitive driver errors and configuration values are never printed.
        $phpCheck=@'
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$a=(array)config("database.connections.pgsql_migrations",[]);
$t=(array)config("database.connections.pgsql",[]);
if (!app()->environment("testing") || config("database.default")!=="pgsql"
 || ($a["host"]??"")!=="127.0.0.1" || ($t["host"]??"")!=="127.0.0.1"
 || (string)($a["port"]??"")!=="55433" || (string)($t["port"]??"")!=="55433"
 || ($a["database"]??"")!=="contabilidad_local" || ($t["database"]??"")!=="contabilidad_local"
 || ($a["username"]??"")!=="postgres" || ($t["username"]??"")!=="postgres"
 || trim((string)($a["url"]??""))!=="" || trim((string)($t["url"]??""))!==""
 || !\App\Support\Database\LocalPg18InstallGuard::approved()){exit(73);}
echo "[PASS] Laravel solo puede usar PostgreSQL 18 local autorizado\n";
'@
        $result=(& php -r $phpCheck 2>&1 | Out-String).Trim()
        if($LASTEXITCODE -ne 0 -or $result -notmatch '^[PASS] Laravel solo puede usar PostgreSQL 18 local autorizado'){
            throw 'Laravel no superó el preflight de identidad local; no se migró.'
        }
        Write-Host '[PASS] Preflight Laravel local confirmado.'
        # Preserve the newly created role password privately even if a later
        # migration fails. A failed, partially migrated persistent DB is NEVER
        # deleted or silently retried.
        [IO.File]::AppendAllText($privateConfig,
            ([Environment]::NewLine+'DB_APP_BACKEND_ROLE_PASSWORD='+$rolePassword+[Environment]::NewLine),
            (New-Object System.Text.UTF8Encoding($false)))
        $migrations=@(
            'migrations/0001_01_00_000000_setup_extensions_and_app_backend_role'
            'migrations/0001_01_01_000000_create_companies_table'
            'migrations/0001_01_01_000001_create_users_and_company_user_tables'
            'migrations/0001_01_02_000000_create_accounting_accounts_table'
            'migrations/0001_01_02_000001_create_cost_centers_and_cash_flow_areas_tables'
            'migrations/0001_01_02_000002_create_currencies_and_sunat_tables'
            'migrations/0001_01_02_000003_create_accounting_standards_and_rubrics_tables'
            'migrations/0001_01_02_000004_create_company_settings_areas_and_entities_tables'
            'migrations/0001_01_03_000000_create_fiscal_periods_and_exchange_rates_tables'
            'migrations/0001_01_04_000000_create_journal_entries_tables'
            'migrations/0001_01_05_000000_create_accounting_account_templates_table'
            'migrations/0001_01_06_000000_create_audit_logs_table'
            'migrations/0001_01_07_000000_create_company_currencies_table'
            'migrations/0001_01_08_000000_optimize_rls_policies_and_tenant_function'
            'migrations/2026_10_21_010000_harden_postgres_function_search_path'
            'migrations/2026_10_21_020000_close_global_audit_log_read_leak'
            'portable_migrations/2026_10_22_110000_create_accounting_destination_rules_v2'
            'portable_migrations/2026_10_22_120000_add_journal_line_cost_centers'
            'portable_migrations/2026_10_22_130000_create_journal_analytic_allocations'
            'portable_migrations/2026_10_22_140000_create_journal_post_idempotency'
            'migrations/2026_10_24_080000_add_journal_line_entities'
            'migrations/2026_10_24_100000_add_journal_line_areas'
            'migrations/2026_10_24_120000_add_account_requires_company_area'
            'migrations/2026_10_24_130000_add_journal_entry_subdiary'
            'migrations/2026_10_24_140000_create_company_subdiaries'
        )
        if($migrations.Count -ne 25){throw 'Cantidad inesperada de migraciones.'}
        foreach($m in $migrations){
            $path='database/'+$m+'.php'
            if(-not (Test-Path -LiteralPath $path)){throw ('Falta archivo de migración: '+$m)}
            Write-Host ('[MIGRACION] '+$m)
            # Capture and suppress raw output: errors may include credentials
            # embedded in SQL. Only the migration name is safe to share.
            $migrationArguments=@('artisan','migrate','--database=pgsql_migrations',
                ('--path='+$path),'--force','--no-interaction')
            $stdout=(& php @migrationArguments 2>&1 | Out-String)
            if($LASTEXITCODE -ne 0 -or $stdout -match '(?im)\bFAIL\b'){
                throw ('Migración detenida: '+$m+'. No repetir sobre base parcial.')
            }
            $base=[IO.Path]::GetFileNameWithoutExtension($path)
            $recorded=SqlResult ("SELECT count(*) FROM public.migrations WHERE migration='"+$base+"'")
            if($recorded -ne '1'){throw ('No se registró correctamente: '+$m)}
        }
        $seed=@'
INSERT INTO public.currencies
 (code,sunat_code,name,symbol,is_base_currency,created_at,updated_at)
VALUES ('PEN','1','Soles','S/',TRUE,now(),now()),
 ('USD','2','Dolares Americanos','$',FALSE,now(),now()),
 ('EUR','9','Euros',chr(8364),FALSE,now(),now());
'@
        $null=SqlResult $seed
        VerifyInstalled
        [IO.File]::AppendAllText($privateConfig,
            ([Environment]::NewLine+'DB_USERNAME=app_backend'+[Environment]::NewLine+
              'DB_PASSWORD='+$rolePassword+[Environment]::NewLine+
              'DB_CONNECTION=pgsql'+[Environment]::NewLine+
              'DB_URL='+[Environment]::NewLine+'DATABASE_URL='+[Environment]::NewLine+
              'DB_SSLMODE=disable'+[Environment]::NewLine+
              'DB_EMULATE_PREPARES=false'+[Environment]::NewLine),
            (New-Object System.Text.UTF8Encoding($false)))
        Write-Host '[PASS] Esquema PostgreSQL 18 local instalado sin alterar .env remoto.'
        Write-Host '[PASS] Credenciales app_backend guardadas en archivo privado (no compartir).'
        Write-Host '[PENDIENTE] Crear empresa y usuario sintéticos; después medir HTTP.'
        $success=$true
    }
}catch{
    Write-Host ('[FAIL] '+$_.Exception.Message)
    Write-Host '[SEGURIDAD] Si hubo migraciones, la base persistente NO fue eliminada ni reiniciada.'
}finally{
    foreach($key in $previousEnv.Keys){
        [Environment]::SetEnvironmentVariable($key,$previousEnv[$key],'Process')
    }
    Set-Location $previous
}
if(-not $success){exit 1}
