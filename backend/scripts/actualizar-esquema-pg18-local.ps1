#requires -Version 5.1
<#
 Actualizacion adicional de Docker PG18 persistente, sobre 25 migraciones
 revisadas. No usa los endpoints Neon ni instala en otras bases.
 Por defecto: Plan (SOLO SELECT). Aplicar exige baseline exacto, respaldo
 local y comprobacion doble de identidad ANTES de cualquier migracion.
 No ejecuta migrate general, ni DROP, ni db:seed.

 .\scripts\actualizar-esquema-pg18-local.ps1 -Accion Plan
 .\scripts\actualizar-esquema-pg18-local.ps1 -Accion Aplicar
 .\scripts\actualizar-esquema-pg18-local.ps1 -Accion Verificar
#>
param([ValidateSet('Plan','Aplicar','Verificar')][string]$Accion='Plan')
$ErrorActionPreference='Stop'
$backend=Split-Path -Parent $PSScriptRoot
$previousLocation=Get-Location
$dockerName='contabilidad-pg18-local'
$volume='contabilidad-pg18-local-data'
$privateConfig=Join-Path $backend '.env.pg18.local'
$previousEnvironment=@{}
$success=$false
$baseline=@(
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
$updates=@(
    '2026_10_23_140000_create_company_fiscal_profiles'
    '2026_10_24_150000_create_see_reference_catalogs'
    '2026_10_24_160000_add_see_catalog_20_sunat_2024_version'
    '2026_10_24_170000_add_see_catalog_15_19_55_versions'
    '2026_10_24_180000_add_see_catalog_02_iso_4217_version'
    '2026_10_24_190000_add_see_ubigeo_inei_2026_version'
    '2026_10_24_200000_add_see_catalog_04_uncefact_country_version'
    '2026_10_24_210000_add_see_catalog_03_unece_rec20_rev17_version'
    '2026_10_24_220000_create_company_see_visibility_revisions'
    '2026_10_24_230000_add_see_catalogs_26_27_sunat_2017_version'
    '2026_10_24_240000_add_see_catalog_59_payment_methods_version'
    '2026_10_25_000000_add_see_catalog_06_sunat_2026_identity_version'
    '2026_10_25_010000_reconcile_ple_table_01_payment_methods'
    '2026_10_25_020000_reconcile_ple_table_02_identity_documents'
    '2026_10_25_030000_reconcile_ple_table_05_inventory_types'
    '2026_10_25_040000_version_ple_table_06_measurement_units'
    '2026_10_25_050000_version_ple_table_03_financial_institutions'
    '2026_10_25_060000_version_ple_table_12_inventory_operations'
    '2026_10_25_070000_version_ple_table_11_customs_dependencies'
    '2026_10_25_080000_version_ple_table_10_document_types'
    '2026_10_25_090000_version_sunat_table_08_book_codes'
    '2026_10_25_100000_add_ple_table_04_currency_codes'
    '2026_10_25_110000_add_ple_tables_13_14_inventory_references'
    '2026_10_25_120000_add_ple_tables_15_20_reference_editions'
    '2026_10_25_130000_add_ple_tables_21_22_reference_editions'
    '2026_10_25_140000_add_ple_table_25_historical_treaty_codes'
    '2026_10_25_150000_add_ple_table_27_economic_relationship'
    '2026_10_25_160000_add_ple_table_28_historical_net_equity'
    '2026_10_25_170000_add_ple_table_30_acquisition_classification'
    '2026_10_25_180000_add_ple_table_32_nonresident_service'
    '2026_10_25_190000_add_ple_table_33_historical_exemptions'
    '2026_10_25_200000_add_ple_table_31_historical_income_types'
    '2026_10_25_210000_add_ple_table_34_financial_rubrics'
    '2026_10_25_220000_add_ple_table_35_country_codes'
    '2026_11_12_000000_create_document_journal_modules'
    '2026_11_12_000001_create_tax_registers_and_allocations'
)

function SqlLocal([string]$sql) {
    $result=(& docker exec $dockerName psql -U postgres -d contabilidad_local -X -A -t -v ON_ERROR_STOP=1 -c $sql 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0){throw 'Fallo el SQL de verificacion del contenedor Docker local.'}
    return $result
}
function VerifyDockerIdentity {
    $os=(& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $os -ne 'linux'){throw 'Docker Linux no disponible.'}
    $label=(& docker inspect --format '{{index .Config.Labels "contabilidad.local"}}' $dockerName 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $label -ne 'pg18-dev'){throw 'Contenedor no autorizado: etiqueta inesperada.'}
    $port=(& docker port $dockerName '5432/tcp' 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $port -ne '127.0.0.1:55433'){throw 'Puerto local inesperado.'}
    $mount=(& docker inspect --format '{{range .Mounts}}{{.Name}}|{{.Destination}}{{end}}' $dockerName 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $mount -ne ($volume+'|/var/lib/postgresql')){throw 'Volumen persistente inesperado.'}
    $db=SqlLocal "SELECT current_database(),current_user,current_setting('server_version_num')::integer"
    if($db -notmatch '^contabilidad_local[|]postgres[|]18[0-9]{4}$'){throw 'PostgreSQL 18 local no verificado.'}
    $rls=SqlLocal @'
SELECT count(*)
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public'
  AND c.relname IN ('company_user','journal_entries','journal_entry_lines','company_subdiaries','journal_post_idempotency')
  AND c.relrowsecurity AND c.relforcerowsecurity
'@
    if($rls -ne '5'){throw 'Los cinco controles FORCE RLS esperados no estan presentes.'}
}
function ReadMigrationNames {
    $raw=SqlLocal 'SELECT migration FROM public.migrations ORDER BY migration'
    if([string]::IsNullOrWhiteSpace($raw)){return @()}
    return @($raw -split '[\r\n]+' | Where-Object { $_ -ne '' })
}
function CheckMigrationInventory {
    $applied=@(ReadMigrationNames)
    $missingBase=@($baseline | Where-Object { $_ -notin $applied })
    $unexpected=@($applied | Where-Object { $_ -notin $baseline -and $_ -notin $updates })
    $done=@($updates | Where-Object { $_ -in $applied })
    $pending=@($updates | Where-Object { $_ -notin $applied })
    if($missingBase.Count -gt 0 -or $unexpected.Count -gt 0) {
        throw 'El historial no coincide con las migraciones revisadas; no modificar automaticamente.'
    }
    Write-Host ('[ESTADO] Base inicial 25/25 | adicionales '+$done.Count+'/'+$updates.Count+' | pendientes '+$pending.Count)
    return @{applied=$applied;done=$done;pending=$pending}
}
function ReadPrivateConfig {
    if(-not (Test-Path -LiteralPath $privateConfig)){throw 'Falta backend/.env.pg18.local; no continuar.'}
    $values=@{}
    foreach($line in (Get-Content -LiteralPath $privateConfig)){
        if($line.Trim() -eq '' -or $line.StartsWith('#')){continue}
        $pair=$line -split '=',2
        if($pair.Count -ne 2 -or $pair[0] -notmatch '^[A-Z][A-Z0-9_]*$' -or $values.ContainsKey($pair[0])){
            throw 'Archivo local privado mal formado o con claves duplicadas.'
        }
        $values[$pair[0]]=$pair[1]
    }
    foreach($pair in @(
        @('DB_HOST','127.0.0.1'),@('DB_PORT','55433'),
        @('DB_DATABASE','contabilidad_local'),@('DB_MIGRATIONS_HOST','127.0.0.1'),
        @('DB_MIGRATIONS_PORT','55433'),@('DB_MIGRATIONS_DATABASE','contabilidad_local'),
        @('DB_MIGRATIONS_USERNAME','postgres'),@('DB_MIGRATIONS_SSLMODE','disable'),
        @('DB_USERNAME','app_backend'),@('DB_SSLMODE','disable')
    )){
        if($values[$pair[0]] -cne $pair[1]){throw ('Configuracion Docker inesperada para '+$pair[0])}
    }
    if([string]::IsNullOrWhiteSpace($values['DB_MIGRATIONS_PASSWORD']) -or
       [string]::IsNullOrWhiteSpace($values['DB_PASSWORD'])) {
        throw 'Faltan credenciales privadas locales; no continuar.'
    }
    return $values
}
function SetProcessEnv([hashtable]$values) {
    foreach($key in $values.Keys) {
        if(-not $previousEnvironment.ContainsKey($key)) {
            $previousEnvironment[$key]=[Environment]::GetEnvironmentVariable($key,'Process')
        }
        [Environment]::SetEnvironmentVariable($key,[string]$values[$key],'Process')
    }
}
function CheckUpdated([hashtable]$inventory) {
    if($inventory.applied.Count -ne 61 -or $inventory.pending.Count -ne 0) {
        throw 'Aun no estan las 61 migraciones portables revisadas.'
    }
    $tables=SqlLocal @'
SELECT
 (SELECT CASE WHEN to_regclass('public.company_fiscal_profiles') IS NULL THEN 0 ELSE 1 END),
 (SELECT CASE WHEN to_regclass('public.see_catalogs') IS NULL THEN 0 ELSE 1 END),
 (SELECT CASE WHEN to_regclass('public.accounting_document_entries') IS NULL THEN 0 ELSE 1 END),
 (SELECT CASE WHEN to_regclass('public.accounting_tax_register_entries') IS NULL THEN 0 ELSE 1 END),
 (SELECT CASE WHEN to_regclass('public.accounting_tax_register_allocations') IS NULL THEN 0 ELSE 1 END)
'@
    if($tables -ne '1|1|1|1|1') {
        throw 'Faltan tablas clave de catalogos o registros de Compras/Ventas.'
    }
    Write-Host '[PASS] 61 migraciones portables y tablas de Compras/Ventas presentes en Docker.'
}
function BackupLocal {
    $backupRoot=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'Contabilidad\Backups'
    if([string]::IsNullOrWhiteSpace($backupRoot)){throw 'No se determino directorio privado para copia local.'}
    New-Item -ItemType Directory -Force -Path $backupRoot | Out-Null
    $stamp=(Get-Date).ToString('yyyyMMdd-HHmmss')
    $name='contabilidad_local_pre_upgrade_'+$stamp+'.dump'
    $backupFile=Join-Path $backupRoot $name
    if(Test-Path -LiteralPath $backupFile){throw 'Ya existe un respaldo con ese nombre.'}
    $tmp='/tmp/conta-local-upgrade-'+[guid]::NewGuid().ToString('N')+'.dump'
    try {
        & docker exec $dockerName pg_dump -U postgres -d contabilidad_local -Fc -f $tmp 1>$null 2>$null
        if($LASTEXITCODE -ne 0){throw 'No se pudo crear pg_dump local.'}
        & docker cp ($dockerName+':'+$tmp) $backupFile 1>$null 2>$null
        if($LASTEXITCODE -ne 0){throw 'No se pudo guardar pg_dump fuera del contenedor.'}
    } finally {
        & docker exec $dockerName rm -f -- $tmp 1>$null 2>$null
    }
    if(-not (Test-Path -LiteralPath $backupFile) -or
        (Get-Item -LiteralPath $backupFile).Length -lt 1024) {
        throw 'Respaldo local ausente o demasiado pequeno: migracion bloqueada.'
    }
    Write-Host ('[RESPALDO] Copia local creada antes de migrar en: '+$backupFile)
}

try {
    Set-Location $backend
    if($baseline.Count -ne 25 -or $updates.Count -ne 36){throw 'Inventario revisado inconsistente.'}
    VerifyDockerIdentity
    $inventory=CheckMigrationInventory

    if($Accion -eq 'Plan') {
        if($inventory.pending.Count -eq 0) {
            Write-Host '[PLAN] Las 36 migraciones adicionales ya estan registradas.'
        } else {
            foreach($migration in $inventory.pending){Write-Host ('[PENDIENTE] '+$migration)}
        }
        Write-Host '[PLAN] Solo consultas SELECT; sin cambios en Docker o Neon.'
        $success=$true
    } elseif($Accion -eq 'Verificar') {
        CheckUpdated $inventory
        $success=$true
    } else {
        if($inventory.pending.Count -ne 36 -or $inventory.done.Count -ne 0 -or
            $inventory.applied.Count -ne 25){
            throw 'La aplicacion exige exactamente las 25 migraciones iniciales. Estado parcial: detener y revisar.'
        }
        if(-not (Test-Path 'vendor/autoload.php') -or (Test-Path 'bootstrap/cache/config.php')){
            throw 'Falta vendor o hay bootstrap/cache/config.php. No ejecutar Artisan.'
        }
        $phpVersion=(& php -r 'echo PHP_VERSION;' | Out-String).Trim()
        if($LASTEXITCODE -ne 0 -or $phpVersion -notmatch '^8\.5\.'){
            throw 'Se requiere PHP 8.5 para la migracion local.'
        }
        foreach($migration in $updates){
            if(-not (Test-Path -LiteralPath ('database/migrations/'+$migration+'.php'))) {
                throw ('Archivo de migracion no presente: '+$migration)
            }
        }
        $secret=ReadPrivateConfig
        SetProcessEnv @{
            APP_ENV='testing';APP_DEBUG='false'
            DB_CONNECTION='pgsql';DB_URL='';DATABASE_URL=''
            DB_HOST='127.0.0.1';DB_PORT='55433';DB_DATABASE='contabilidad_local'
            DB_USERNAME='postgres';DB_PASSWORD=$secret['DB_MIGRATIONS_PASSWORD']
            DB_SSLMODE='disable';DB_EMULATE_PREPARES='false'
            DB_MIGRATIONS_URL='';DB_MIGRATIONS_HOST='127.0.0.1'
            DB_MIGRATIONS_PORT='55433';DB_MIGRATIONS_DATABASE='contabilidad_local'
            DB_MIGRATIONS_USERNAME='postgres';DB_MIGRATIONS_PASSWORD=$secret['DB_MIGRATIONS_PASSWORD']
            DB_MIGRATIONS_SSLMODE='disable'
            CACHE_STORE='array';SESSION_DRIVER='array';QUEUE_CONNECTION='sync'
            NEON_TEST_BRANCH_NAME='';NEON_TEST_BRANCH_HOST=''
        }
        $guardOutput=(& php scripts/verificar-actualizacion-pg18-local.php 2>&1 | Out-String).Trim()
        if($LASTEXITCODE -ne 0 -or
            $guardOutput -cne '[PASS] Laravel solo apunta al Docker PG18 local con 25 migraciones y RLS'){
            throw 'El preflight Laravel local no paso; ninguna migracion iniciada.'
        }
        Write-Host '[PASS] Conexion Laravel administrativa comprobada exclusivamente sobre Docker local.'
        BackupLocal
        foreach($migration in $updates) {
            Write-Host ('[MIGRACION] '+$migration)
            # No imprimir excepciones SQL ni credenciales de Laravel/Artisan.
            $output=(& php artisan migrate --database=pgsql_migrations ('--path=database/migrations/'+$migration+'.php') --force --no-interaction 2>&1 | Out-String)
            if($LASTEXITCODE -ne 0 -or $output -match '(?im)\bFAIL\b'){
                throw ('Fallo al aplicar: '+$migration+'. Conservar el respaldo; revisar antes de reintentar.')
            }
            $count=SqlLocal ("SELECT count(*) FROM public.migrations WHERE migration='"+$migration+"'")
            if($count -ne '1'){throw ('No se registro la migracion: '+$migration)}
            Write-Host '[PASS] Migracion registrada.'
        }
        CheckUpdated (CheckMigrationInventory)
        Write-Host '[PASS] Datos existentes conservados; .env remoto no fue modificado.'
        $success=$true
    }
} catch {
    Write-Host ('[BLOQUEO] '+$_.Exception.Message)
    Write-Host '[SEGURIDAD] Nunca ejecutar migrate general, migrate:fresh ni eliminar volumen.'
} finally {
    foreach($key in $previousEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($key,$previousEnvironment[$key],'Process')
    }
    Set-Location $previousLocation
}
if(-not $success){exit 1}
