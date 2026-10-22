#requires -Version 5.1
<#
  F2.3h: combined ACTIVE + app_backend FORCE RLS through actual Laravel HTTP,
  entirely within one disposable PostgreSQL 18 Docker container.
  Run from backend: .\scripts\phase2-active-rls-docker.ps1
  This script NEVER connects to Neon and ALWAYS deletes its container.
#>
$ErrorActionPreference='Stop'
$backend=Split-Path -Parent $PSScriptRoot
$oldLocation=Get-Location
$containerName='f23h-'+([Guid]::NewGuid().ToString('N')).Substring(0,12)
$adminPassword='pg-'+[Guid]::NewGuid().ToString('N')
$appPassword='app-'+[Guid]::NewGuid().ToString('N')
$guardToken=[Guid]::NewGuid().ToString('N')
$runContainer=$false
$success=$false
$originalEnv=@{}
function Set-F23hEnv {
    param([hashtable]$Values)
    foreach($key in $Values.Keys){
        if(-not $originalEnv.ContainsKey($key)){
            $originalEnv[$key]=[Environment]::GetEnvironmentVariable($key,'Process')
        }
        [Environment]::SetEnvironmentVariable($key,[string]$Values[$key],'Process')
    }
}
function Invoke-F23h {
    param(
        [Parameter(Mandatory=$true)][string]$Program,
        [Parameter(Mandatory=$true)][string[]]$Arguments
    )
    # No usar $Args: es una variable automatica de PowerShell que contiene
    # argumentos sin enlazar, y en este wrapper resultaba vacia.
    if($Arguments.Count -eq 0){throw "Invocacion sin argumentos: $Program"}
    & $Program @Arguments
    if($LASTEXITCODE -ne 0){throw "$Program fallo: codigo $LASTEXITCODE"}
}
try{
    Set-Location $backend
    if(-not (Test-Path 'vendor/autoload.php') -or (Test-Path 'bootstrap/cache/config.php')){
        throw 'Requiere vendor de Composer y ausencia de config cacheada.'
    }
    if(-not (Test-Path 'scripts/phase2-active-rls-docker.php')){
        throw 'Falta script F2.3h; ejecutar git pull.'
    }
    $phpVersion=(& php -r 'echo PHP_VERSION;' | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $phpVersion -notmatch '^8\.5\.'){
        throw "PHP 8.5 requerido. Actual=$phpVersion"
    }
    $os=(& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $os -ne 'linux'){
        throw 'Docker Desktop debe estar encendido con contenedores Linux.'
    }
    Write-Host '[AISLAMIENTO] PostgreSQL 18 en Docker temporal; puerto solo 127.0.0.1.'
    Invoke-F23h -Program 'docker' -Arguments @('run','--detach','--name',$containerName,
        '--publish','127.0.0.1::5432','--env',('POSTGRES_PASSWORD='+$adminPassword),
        '--env','POSTGRES_DB=contabilidad_ci','postgres:18-alpine')
    $runContainer=$true
    $portLine=(& docker port $containerName '5432/tcp' | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $portLine -notmatch '^127\.0\.0\.1:(\d{2,5})$'){
        throw "Puerto Docker no seguro: $portLine"
    }
    $port=$Matches[1]
    if($port -eq '5432'){throw 'Puerto Docker no es dinámico.'}
    $ready=$false
    for($i=0;$i -lt 45;$i++){
        & docker exec $containerName pg_isready -U postgres -d contabilidad_ci -h 127.0.0.1 1>$null 2>$null
        if($LASTEXITCODE -eq 0){$ready=$true;break}
        Start-Sleep -Seconds 1
    }
    if(-not $ready){throw 'PostgreSQL Docker no respondió.'}
    $guardSql="CREATE TABLE public.f23h_guard (token text NOT NULL); "+
        "INSERT INTO public.f23h_guard(token) VALUES ('$guardToken'); "+
        'CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN;'
    Invoke-F23h -Program 'docker' -Arguments @('exec',$containerName,'psql','-U','postgres',
        '-d','contabilidad_ci','-X','-v','ON_ERROR_STOP=1','-c',$guardSql)
    Set-F23hEnv @{
        APP_ENV='testing';APP_DEBUG='false'
        APP_KEY='base64:XQ9v12345678901234567890123456789012345678='
        DB_CONNECTION='pgsql';DB_HOST='127.0.0.1';DB_PORT=$port
        DB_DATABASE='contabilidad_ci';DB_USERNAME='postgres'
        DB_PASSWORD=$adminPassword;DB_URL='';DATABASE_URL=''
        DB_SSLMODE='disable';DB_EMULATE_PREPARES='true'
        DB_MIGRATIONS_HOST='127.0.0.1';DB_MIGRATIONS_PORT=$port
        DB_MIGRATIONS_DATABASE='contabilidad_ci';DB_MIGRATIONS_USERNAME='postgres'
        DB_MIGRATIONS_PASSWORD=$adminPassword;DB_MIGRATIONS_URL=''
        DB_MIGRATIONS_SSLMODE='disable'
        DB_APP_BACKEND_ROLE_PASSWORD=$appPassword
        F23H_GUARD_TOKEN=$guardToken
        CACHE_STORE='array';SESSION_DRIVER='array';QUEUE_CONNECTION='sync'
    }
    $migrations=@(
        'database/migrations/0001_01_00_000000_setup_extensions_and_app_backend_role.php'
        'database/migrations/0001_01_01_000000_create_companies_table.php'
        'database/migrations/0001_01_01_000001_create_users_and_company_user_tables.php'
        'database/migrations/0001_01_02_000000_create_accounting_accounts_table.php'
        'database/migrations/0001_01_02_000001_create_cost_centers_and_cash_flow_areas_tables.php'
        'database/migrations/0001_01_02_000002_create_currencies_and_sunat_tables.php'
        'database/migrations/0001_01_02_000003_create_accounting_standards_and_rubrics_tables.php'
        'database/migrations/0001_01_02_000004_create_company_settings_areas_and_entities_tables.php'
        'database/migrations/0001_01_03_000000_create_fiscal_periods_and_exchange_rates_tables.php'
        'database/migrations/0001_01_04_000000_create_journal_entries_tables.php'
        'database/migrations/0001_01_05_000000_create_accounting_account_templates_table.php'
        'database/migrations/0001_01_06_000000_create_audit_logs_table.php'
        'database/migrations/0001_01_07_000000_create_company_currencies_table.php'
        'database/migrations/0001_01_08_000000_optimize_rls_policies_and_tenant_function.php'
        'database/migrations/2026_10_21_010000_harden_postgres_function_search_path.php'
        'database/migrations/2026_10_21_020000_close_global_audit_log_read_leak.php'
        'database/portable_migrations/2026_10_22_110000_create_accounting_destination_rules_v2.php'
        'database/portable_migrations/2026_10_22_120000_add_journal_line_cost_centers.php'
        'database/portable_migrations/2026_10_22_130000_create_journal_analytic_allocations.php'
        'database/portable_migrations/2026_10_22_140000_create_journal_post_idempotency.php'
    )
    foreach($migration in $migrations){
        if(-not (Test-Path $migration)){throw "Falta $migration"}
    }
    Write-Host ('[MIGRACIONES] '+$migrations.Count+' migraciones reales en el contenedor desechable.')
    foreach($migration in $migrations){
        Invoke-F23h -Program 'php' -Arguments @('artisan','migrate','--database=pgsql_migrations',
            ('--path='+$migration),'--force','--no-interaction')
        $id=[IO.Path]::GetFileNameWithoutExtension($migration)
        $check="SELECT count(*) FROM public.migrations WHERE migration='$id'"
        $rows=(& docker exec $containerName psql -U postgres -d contabilidad_ci -X -A -t -v ON_ERROR_STOP=1 -c $check | Out-String).Trim()
        if($LASTEXITCODE -ne 0 -or $rows -ne '1'){throw "Migración no registrada: $id"}
    }
    Invoke-F23h -Program 'docker' -Arguments @('exec',$containerName,'psql','-U','postgres','-d','contabilidad_ci',
        '-X','-v','ON_ERROR_STOP=1','-c','GRANT SELECT ON public.f23h_guard TO app_backend')
    Invoke-F23h -Program 'php' -Arguments @('-l','scripts/phase2-active-rls-docker.php')
    Invoke-F23h -Program 'php' -Arguments @('scripts/phase2-active-rls-docker.php','--probe=ephemeral-docker')
    Write-Host '[PASS] F2.3h: HTTP ACTIVE con rol app_backend y FORCE RLS verificados en Docker.'
    $success=$true
}catch{
    Write-Host ('[FAIL] F2.3h: '+$_.Exception.Message)
}finally{
    foreach($key in $originalEnv.Keys){
        [Environment]::SetEnvironmentVariable($key,$originalEnv[$key],'Process')
    }
    if($runContainer){
        # No mostrar una limpieza exitosa si el contenedor nunca existio.
        $found=(& docker container inspect --format '{{.Id}}' $containerName 2>$null | Out-String).Trim()
        if($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($found)){
            $success=$false
            Write-Host ('[WARN] No existe el contenedor registrado para limpiar: '+$containerName)
        }else{
            & docker rm --force $containerName 1>$null 2>$null
            if($LASTEXITCODE -eq 0){Write-Host '[LIMPIEZA] PostgreSQL Docker descartable eliminado.'}
            else{$success=$false;Write-Host ('[WARN] No se pudo eliminar '+$containerName)}
        }
    }
    Set-Location $oldLocation
}
if(-not $success){exit 1}
