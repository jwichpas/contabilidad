#requires -Version 5.1
<#
 One additive RLS migration for multiempresa cash-flow presentation, ONLY
 authorized persistent Docker PG18. Never use migrate:fresh or general migrate.
 .\scripts\configurar-flujo-efectivo-pg18-local.ps1 -Accion Plan
 .\scripts\configurar-flujo-efectivo-pg18-local.ps1 -Accion Aplicar
 .\scripts\configurar-flujo-efectivo-pg18-local.ps1 -Accion Verificar
#>
param([ValidateSet('Plan','Aplicar','Verificar')][string]$Accion='Plan')
$ErrorActionPreference='Stop'
$previous=Get-Location
$backend=Split-Path -Parent $PSScriptRoot
$name='contabilidad-pg18-local'
$volume='contabilidad-pg18-local-data'
$target='2026_11_16_120000_create_company_cash_flow_presentation_revisions'
$privateConfig=Join-Path $backend '.env.pg18.local'
$priorEnv=@{}
$ok=$false
function Sql([string]$sql) {
    $output=(& docker exec $name psql -U postgres -d contabilidad_local -X -A -t -F '|' -v ON_ERROR_STOP=1 -c $sql 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0){throw 'La verificación SQL en el Docker autorizado falló.'}
    return $output
}
function Identity {
    $label=(& docker inspect --format '{{index .Config.Labels "contabilidad.local"}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $label -ne 'pg18-dev'){throw 'Contenedor no autorizado.'}
    $port=(& docker port $name '5432/tcp' 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $port -ne '127.0.0.1:55433'){throw 'Puerto Docker inesperado.'}
    $mount=(& docker inspect --format '{{range .Mounts}}{{.Name}}|{{.Destination}}{{end}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $mount -ne ($volume+'|/var/lib/postgresql')){throw 'Volumen Docker inesperado.'}
    if((Sql "SELECT current_database(),current_user,current_setting('server_version_num')::integer") -notmatch '^contabilidad_local[|]postgres[|]18[0-9]{4}$'){throw 'PostgreSQL local no coincide.'}
    $guards=Sql @'
SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relname IN ('company_user','journal_entries','journal_entry_lines')
AND c.relrowsecurity AND c.relforcerowsecurity
'@
    if($guards -ne '3'){throw 'Controles RLS originales incompletos.'}
}
function Inventory {
    $applied=@((Sql 'SELECT migration FROM public.migrations ORDER BY migration') -split '[\r\n]+' | Where-Object { $_ })
    $base=@(
        '0001_01_00_000000_setup_extensions_and_app_backend_role'
        '0001_01_01_000000_create_companies_table'
        '0001_01_01_000001_create_users_and_company_user_tables'
        '0001_01_02_000000_create_accounting_accounts_table'
        '0001_01_02_000001_create_cost_centers_and_cash_flow_areas_tables'
        '0001_01_02_000002_create_currencies_and_sunat_tables'
        '0001_01_02_000003_create_accounting_standards_and_rubrics_tables'
        '0001_01_02_000004_create_company_settings_areas_and_entities_tables'
        '0001_01_03_000000_create_fiscal_periods_and_exchange_rates_tables'
        '0001_01_04_000000_create_journal_entries_tables'
        '0001_01_05_000000_create_accounting_account_templates_table'
        '0001_01_06_000000_create_audit_logs_table'
        '0001_01_07_000000_create_company_currencies_table'
        '0001_01_08_000000_optimize_rls_policies_and_tenant_function'
        '2026_10_21_010000_harden_postgres_function_search_path'
        '2026_10_21_020000_close_global_audit_log_read_leak'
        '2026_10_22_110000_create_accounting_destination_rules_v2'
        '2026_10_22_120000_add_journal_line_cost_centers'
        '2026_10_22_130000_create_journal_analytic_allocations'
        '2026_10_22_140000_create_journal_post_idempotency'
        '2026_10_24_080000_add_journal_line_entities'
        '2026_10_24_100000_add_journal_line_areas'
        '2026_10_24_120000_add_account_requires_company_area'
        '2026_10_24_130000_add_journal_entry_subdiary'
        '2026_10_24_140000_create_company_subdiaries'
    )
    $optional=@(
        '2026_10_23_140000_create_company_fiscal_profiles'
        '2026_11_12_000000_create_document_journal_modules'
        '2026_11_12_000001_create_tax_registers_and_allocations'
    )
    if(@($base | Where-Object { $_ -notin $applied }).Count -ne 0){throw 'Falta alguna migración base del Docker PG18.'}
    $unknown=@($applied | Where-Object { $_ -notin $base -and $_ -notin $optional -and $_ -ne $target })
    if($unknown.Count -gt 0){throw 'Migraciones inesperadas: no modificar automáticamente.'}
    $done=@($optional | Where-Object { $_ -in $applied })
    if($done.Count -ne 0 -and $done.Count -ne 3){throw 'Actualización fiscal parcialmente aplicada; detener.'}
    return ($target -in $applied)
}
function PrivateEnv {
    if(-not (Test-Path -LiteralPath $privateConfig)){throw 'Falta el archivo privado local PG18.'}
    $values=@{}
    foreach($line in (Get-Content -LiteralPath $privateConfig)) {
        if($line.Trim() -eq '' -or $line.StartsWith('#')){continue}
        $pair=$line -split '=',2
        if($pair.Count -ne 2 -or $values.ContainsKey($pair[0])){throw 'Configuración privada mal formada.'}
        $values[$pair[0]]=$pair[1]
    }
    foreach($pair in @(
        @('DB_HOST','127.0.0.1'),@('DB_PORT','55433'),@('DB_DATABASE','contabilidad_local'),
        @('DB_MIGRATIONS_HOST','127.0.0.1'),@('DB_MIGRATIONS_PORT','55433'),
        @('DB_MIGRATIONS_DATABASE','contabilidad_local'),@('DB_MIGRATIONS_USERNAME','postgres'),
        @('DB_MIGRATIONS_SSLMODE','disable'))) {
        if($values[$pair[0]] -cne $pair[1]){throw ('La configuración no es Docker local: '+$pair[0])}
    }
    if([string]::IsNullOrWhiteSpace($values['DB_MIGRATIONS_PASSWORD'])){throw 'Credenciales locales no disponibles.'}
    return $values
}
function SetLocal([hashtable]$values) {
    foreach($key in $values.Keys){
        if(-not $priorEnv.ContainsKey($key)){$priorEnv[$key]=[Environment]::GetEnvironmentVariable($key,'Process')}
        [Environment]::SetEnvironmentVariable($key,[string]$values[$key],'Process')
    }
}
function Verify {
    if((Sql ("SELECT count(*) FROM public.migrations WHERE migration='"+$target+"'")) -ne '1'){throw 'La migración aún no figura aplicada.'}
    if((Sql "SELECT count(*) FROM pg_catalog.pg_class WHERE oid='public.company_cash_flow_presentation_revisions'::regclass AND relrowsecurity AND relforcerowsecurity") -ne '1'){throw 'RLS no está forzado.'}
    if((Sql "SELECT has_table_privilege('app_backend','public.company_cash_flow_presentation_revisions','SELECT'), has_table_privilege('app_backend','public.company_cash_flow_presentation_revisions','INSERT'), has_table_privilege('app_backend','public.company_cash_flow_presentation_revisions','UPDATE'),has_table_privilege('app_backend','public.company_cash_flow_presentation_revisions','DELETE')") -ne 't|t|f|f'){throw 'Permisos de tabla inmutables no son correctos.'}
    Write-Host '[PASS] Catálogo por empresa instalado, FORCE RLS y permisos de solo INSERT/SELECT.'
}
function Backup {
    $root=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'Contabilidad\Backups'
    New-Item -ItemType Directory -Force -Path $root | Out-Null
    $file=Join-Path $root ('contabilidad_pre_flujo_config_'+(Get-Date).ToString('yyyyMMdd-HHmmss')+'.dump')
    $temp='/tmp/conta-flujo-'+[guid]::NewGuid().ToString('N')+'.dump'
    try{
        & docker exec $name pg_dump -U postgres -d contabilidad_local -Fc -f $temp 1>$null 2>$null
        if($LASTEXITCODE -ne 0){throw 'No se pudo respaldar PostgreSQL.'}
        & docker cp ($name+':'+$temp) $file 1>$null 2>$null
        if($LASTEXITCODE -ne 0){throw 'No se pudo copiar el respaldo local.'}
    }finally{
        & docker exec $name rm -f -- $temp 1>$null 2>$null
    }
    if(-not (Test-Path -LiteralPath $file) -or (Get-Item -LiteralPath $file).Length -lt 1024){throw 'Respaldo inválido.'}
    Write-Host ('[RESPALDO] '+$file)
}
try {
    Set-Location $backend
    Identity
    $installed=Inventory
    $before=Sql "SELECT (SELECT count(*) FROM public.journal_entries),(SELECT count(*) FROM public.journal_entry_lines)"
    if($Accion -eq 'Plan'){
        Write-Host ('[PLAN] Migración '+$target+' | instalada='+$installed)
        Write-Host '[PLAN] Solo lectura; no se alteró el Docker ni Neon.'
        $ok=$true
    } elseif($Accion -eq 'Verificar') {
        if(-not $installed){throw 'La migración de configuración está pendiente.'}
        Verify
        $ok=$true
    }else{
        if($installed){throw 'Ya instalada. Ejecutar Verificar, no repetir Aplicar.'}
        if(-not (Test-Path -LiteralPath 'vendor/autoload.php') -or (Test-Path 'bootstrap/cache/config.php')){throw 'Laravel no listo para migrar.'}
        if(((& php -r 'echo PHP_VERSION;' | Out-String).Trim()) -notmatch '^8[.]5[.]'){throw 'Se requiere PHP 8.5.'}
        if(-not (Test-Path -LiteralPath ('database/migrations/'+$target+'.php'))){throw 'Falta la migración revisada.'}
        $config=PrivateEnv
        SetLocal @{
            APP_ENV='testing';APP_DEBUG='false'
            DB_CONNECTION='pgsql';DB_URL='';DATABASE_URL=''
            DB_HOST='127.0.0.1';DB_PORT='55433';DB_DATABASE='contabilidad_local'
            DB_USERNAME='postgres';DB_PASSWORD=$config['DB_MIGRATIONS_PASSWORD']
            DB_SSLMODE='disable';DB_EMULATE_PREPARES='false'
            DB_MIGRATIONS_URL='';DB_MIGRATIONS_HOST='127.0.0.1'
            DB_MIGRATIONS_PORT='55433';DB_MIGRATIONS_DATABASE='contabilidad_local'
            DB_MIGRATIONS_USERNAME='postgres';DB_MIGRATIONS_PASSWORD=$config['DB_MIGRATIONS_PASSWORD']
            DB_MIGRATIONS_SSLMODE='disable'
            CACHE_STORE='array';SESSION_DRIVER='array';QUEUE_CONNECTION='sync'
            NEON_TEST_BRANCH_NAME='';NEON_TEST_BRANCH_HOST=''
        }
        Backup
        $result=(& php artisan migrate --database=pgsql_migrations ('--path=database/migrations/'+$target+'.php') --force --no-interaction 2>&1 | Out-String)
        if($LASTEXITCODE -ne 0 -or $result -match '(?im)\bFAIL\b'){throw 'Falló la migración aditiva; revisar respaldo sin reintentar automáticamente.'}
        Verify
        if((Sql "SELECT (SELECT count(*) FROM public.journal_entries),(SELECT count(*) FROM public.journal_entry_lines)") -ne $before){throw 'Los conteos de movimientos cambiaron; detener e investigar.'}
        Write-Host '[PASS] Asientos y partidas contables conservados; no se consultó Neon.'
        $ok=$true
    }
}catch{
    Write-Host ('[BLOQUEO] '+$_.Exception.Message)
    Write-Host '[SEGURIDAD] No ejecutar migrate general, migrate:fresh, ni eliminar volumen.'
}finally{
    foreach($key in $priorEnv.Keys){[Environment]::SetEnvironmentVariable($key,$priorEnv[$key],'Process')}
    Set-Location $previous
}
if(-not $ok){exit 1}
