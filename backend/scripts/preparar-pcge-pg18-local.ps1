#requires -Version 5.1
<#
  Adds version-controlled F2 references and a NEW PCGE_2019 demonstration
  company to the existing, persistent, isolated PostgreSQL 18 Docker lab.

  .\scripts\preparar-pcge-pg18-local.ps1 -Accion Plan
  .\scripts\preparar-pcge-pg18-local.ps1 -Accion CargarReferencias
  .\scripts\preparar-pcge-pg18-local.ps1 -Accion CrearEmpresa
  .\scripts\preparar-pcge-pg18-local.ps1 -Accion Verificar

  Plan and Verificar are read-only. The two writing actions create a backup
  BEFORE calling PHP and refuse unexpected state. No Neon connections, no
  deletions, no overwrite of the old 203 synthetic benchmark accounts.
#>
param([ValidateSet('Plan','CargarReferencias','CrearEmpresa','Verificar')][string]$Accion='Plan')
$ErrorActionPreference='Stop'
$backend=Split-Path -Parent $PSScriptRoot
$prevLocation=Get-Location
$name='contabilidad-pg18-local'
$volume='contabilidad-pg18-local-data'
$secret=Join-Path $backend '.env.pg18.local'
$originalProcess=@{}
$success=$false

function SqlLocal([string]$sql) {
    $output=(& docker exec $name psql -U postgres -d contabilidad_local -X -A -t -F '|' -v ON_ERROR_STOP=1 -c $sql 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0){throw 'No se completo una consulta de verificacion Docker.'}
    return $output
}
function CheckDocker {
    $os=(& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $os -ne 'linux'){throw 'Docker Desktop Linux no disponible.'}
    $label=(& docker inspect --format '{{index .Config.Labels "contabilidad.local"}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $label -ne 'pg18-dev'){throw 'Contenedor no autorizado.'}
    $port=(& docker port $name '5432/tcp' 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $port -ne '127.0.0.1:55433'){throw 'Puerto Docker inesperado.'}
    $mount=(& docker inspect --format '{{range .Mounts}}{{.Name}}|{{.Destination}}{{end}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $mount -ne ($volume+'|/var/lib/postgresql')){throw 'Volumen Docker inesperado.'}
    $identity=SqlLocal "SELECT current_database(),current_user,current_setting('server_version_num')::integer"
    if($identity -notmatch '^contabilidad_local[|]postgres[|]18[0-9]{4}$'){throw 'La identidad PostgreSQL no coincide con Docker local.'}
    $rls=SqlLocal @'
SELECT count(*) FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relname IN
('company_user','journal_entries','journal_entry_lines','company_subdiaries','journal_post_idempotency')
AND c.relrowsecurity AND c.relforcerowsecurity
'@
    if($rls -ne '5'){throw 'Los controles RLS iniciales no estan presentes.'}
}
function InspectState {
    $result=SqlLocal @'
SELECT
(SELECT count(*) FROM public.migrations),
(SELECT count(*) FROM public.companies),
(SELECT count(*) FROM public.users),
(SELECT count(*) FROM public.accounting_accounts),
(SELECT count(*) FROM public.accounting_account_templates),
(SELECT count(*) FROM public.sunat_table_01_payment_methods),
(SELECT count(*) FROM public.exchange_rates),
(SELECT count(*) FROM public.journal_entries)
'@
    $parts=@($result -split '[|]')
    if($parts.Count -ne 8 -or $parts[0] -notin @('28','29') -or $parts[2] -ne '1' -or $parts[6] -ne '1'){
        throw 'El historial o los datos del laboratorio cambiaron; no automatizar.'
    }
    return @{
        migrations=[int]$parts[0];companies=[int]$parts[1]
        users=[int]$parts[2];accounts=[int]$parts[3]
        templates=[int]$parts[4];sunat=[int]$parts[5]
        rates=[int]$parts[6];journals=[int]$parts[7]
    }
}
function ReadLocalSecrets {
    if(-not (Test-Path -LiteralPath $secret)){throw 'Falta el archivo privado .env.pg18.local.'}
    $settings=@{}
    foreach($line in (Get-Content -LiteralPath $secret)){
        if($line.Trim() -eq '' -or $line.StartsWith('#')){continue}
        $item=$line -split '=',2
        if($item.Count -ne 2 -or $item[0] -notmatch '^[A-Z][A-Z0-9_]*$' -or $settings.ContainsKey($item[0])){
            throw 'Variables duplicadas o mal formadas en archivo privado Docker.'
        }
        $settings[$item[0]]=$item[1]
    }
    foreach($test in @(
        @('DB_HOST','127.0.0.1'), @('DB_PORT','55433'),
        @('DB_DATABASE','contabilidad_local'), @('DB_USERNAME','app_backend'),
        @('DB_SSLMODE','disable'), @('DB_MIGRATIONS_HOST','127.0.0.1'),
        @('DB_MIGRATIONS_PORT','55433'),
        @('DB_MIGRATIONS_DATABASE','contabilidad_local'),
        @('DB_MIGRATIONS_USERNAME','postgres'),
        @('DB_MIGRATIONS_SSLMODE','disable')
    )){
        if($settings[$test[0]] -cne $test[1]){throw ('Configuracion local no esperada: '+$test[0])}
    }
    if([string]::IsNullOrWhiteSpace($settings['DB_PASSWORD']) -or
       [string]::IsNullOrWhiteSpace($settings['DB_MIGRATIONS_PASSWORD'])){
        throw 'Credenciales locales incompletas.'
    }
    return $settings
}
function SetProcessEnv([hashtable]$values) {
    foreach($key in $values.Keys){
        if(-not $originalProcess.ContainsKey($key)){
            $originalProcess[$key]=[Environment]::GetEnvironmentVariable($key,'Process')
        }
        [Environment]::SetEnvironmentVariable($key,[string]$values[$key],'Process')
    }
}
function BackupLocal {
    $backupDir=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'Contabilidad\Backups'
    if([string]::IsNullOrWhiteSpace($backupDir)){throw 'Ruta de backups privados no disponible.'}
    New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
    $file=Join-Path $backupDir ('contabilidad_local_pre_pcge_'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'.dump')
    if(Test-Path -LiteralPath $file){throw 'Ya existe un backup con esta marca horaria.'}
    $tmp='/tmp/conta-pcge-'+[Guid]::NewGuid().ToString('N')+'.dump'
    try{
        & docker exec $name pg_dump -U postgres -d contabilidad_local -Fc -f $tmp 1>$null 2>$null
        if($LASTEXITCODE -ne 0){throw 'Error creando pg_dump en Docker.'}
        & docker cp ($name+':'+$tmp) $file 1>$null 2>$null
        if($LASTEXITCODE -ne 0){throw 'Error copiando pg_dump fuera del volumen.'}
    }finally{
        & docker exec $name rm -f -- $tmp 1>$null 2>$null
    }
    if(-not (Test-Path -LiteralPath $file) -or
        (Get-Item -LiteralPath $file).Length -lt 1024) {
        throw 'No existe un backup local valido: cancelado.'
    }
    Write-Host ('[RESPALDO] '+$file)
}
try{
    Set-Location $backend
    CheckDocker
    $state=InspectState
    Write-Host ('[ESTADO] Migraciones '+$state.migrations+' | empresas '+$state.companies+
        ' | usuarios '+$state.users+' | cuentas '+$state.accounts+
        ' | plantillas PCGE '+$state.templates+' | tasas '+$state.rates+
        ' | SUNAT 01 '+$state.sunat+' | asientos '+$state.journals)

    if($Accion -eq 'Plan') {
        if($state.templates -eq 0 -and $state.companies -eq 1 -and $state.accounts -eq 203){
            if($state.migrations -ne 28){
                Write-Host '[BLOQUEO] No corresponde ejecutar carga inicial en 29 migraciones; revisar ledger.'
            }elseif($state.sunat -ne 0){
                Write-Host ('[BLOQUEO] SUNAT 01 contiene '+$state.sunat+' filas. La carga inicial exige 0.')
                Write-Host '[AVISO] No borrar ni sobrescribir: confirmar la version de estos datos.'
            }else{
                Write-Host '[PASS] Requisitos visibles para iniciar la carga global F2.'
            }
            if($state.journals -gt 0){
                Write-Host ('[INFO] Hay '+$state.journals+' asientos existentes: se conservaran sin modificacion.')
            }
            Write-Host '[PLAN] Falta carga global: 3401 plantillas PCGE, SUNAT, NIIF y rubros.'
            Write-Host '[PLAN] Despues, crear una segunda empresa PCGE manteniendo laboratorio original.'
        }elseif($state.templates -eq 3401 -and $state.companies -eq 1){
            Write-Host '[PLAN] Catalogos presentes. Falta crear empresa PCGE independiente.'
        }elseif($state.templates -eq 3401 -and $state.companies -eq 2){
            Write-Host '[PLAN] Referencias y empresa PCGE ya existentes; comprobar -Accion Verificar.'
        }else{throw 'Estado inesperado, no continuar.'}
        Write-Host '[PLAN] Solo SELECT; ninguna conexion a Neon ni escrituras.'
        $success=$true
    }elseif($Accion -eq 'Verificar'){
        if($state.migrations -ne 29 -or $state.templates -ne 3401 -or
           $state.sunat -ne 20 -or $state.companies -ne 2 -or
           $state.accounts -lt 3158){
            throw 'No estan completos los catalogos o la segunda empresa de prueba.'
        }
        $summary=SqlLocal @'
SELECT
(SELECT count(*) FROM public.accounting_account_templates WHERE plan_version='PCGE_2019'),
(SELECT count(*) FROM public.accounting_account_templates WHERE plan_version='PCGE_2026'),
(SELECT count(*) FROM public.accounting_accounts WHERE company_id =
 (SELECT id FROM public.companies WHERE tax_id='20999990101')),
(SELECT count(*) FROM public.cost_centers WHERE company_id =
 (SELECT id FROM public.companies WHERE tax_id='20999990101')),
(SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname='public' AND c.relname IN
 ('company_document_journal_settings','accounting_document_entries',
  'accounting_tax_register_entries','accounting_tax_register_allocations',
  'company_fiscal_profiles') AND c.relrowsecurity AND c.relforcerowsecurity)
'@
        $parts=@($summary -split '[|]')
        if($parts.Count -ne 5 -or $parts[0] -ne '1764' -or $parts[1] -ne '1637' -or
           [int]$parts[2] -lt 2955 -or $parts[3] -ne '2' -or $parts[4] -ne '5'){
            throw 'No se confirma el PCGE, centro de costos o FORCE RLS.'
        }
        Write-Host '[PASS] Referencias PCGE 2019/2026 y demo local completo; FORCE RLS intacto.'
        Write-Host '[PASS] Empresa antigua y nueva separadas; no hubo importacion Neon.'
        $success=$true
    }else{
        if(-not (Test-Path 'vendor/autoload.php') -or
            (Test-Path 'bootstrap/cache/config.php')){
            throw 'Composer vendor no disponible o Laravel config cacheada.'
        }
        $phpVersion=(& php -r 'echo PHP_VERSION;' | Out-String).Trim()
        if($LASTEXITCODE -ne 0 -or $phpVersion -notmatch '^8\.5\.'){
            throw 'Se requiere PHP 8.5.'
        }
        $settings=ReadLocalSecrets
        if($Accion -eq 'CargarReferencias'){
            if($state.migrations -ne 28 -or $state.templates -ne 0 -or
               $state.sunat -ne 0 -or $state.companies -ne 1 -or
               $state.accounts -ne 203) {
                throw 'La carga inicial de referencias exige el laboratorio exacto de 28 migraciones.'
            }
            $user='postgres'
            $password=$settings['DB_MIGRATIONS_PASSWORD']
            $target='scripts/cargar-pcge-referencias-pg18-local.php'
        }else{
            if($state.migrations -ne 29 -or $state.templates -ne 3401 -or
               $state.sunat -ne 20 -or $state.companies -ne 1 -or
               $state.accounts -ne 203){
                throw 'La segunda empresa exige catalogos completos y laboratorio intacto.'
            }
            $user='app_backend'
            $password=$settings['DB_PASSWORD']
            $target='scripts/crear-empresa-pcge-pg18-local.php'
        }
        if(-not (Test-Path -LiteralPath $target)){throw 'Falta script local autorizado.'}
        SetProcessEnv @{
            APP_ENV='local';APP_DEBUG='false'
            DB_CONNECTION='pgsql';DB_URL='';DATABASE_URL=''
            DB_HOST='127.0.0.1';DB_PORT='55433'
            DB_DATABASE='contabilidad_local';DB_USERNAME=$user;DB_PASSWORD=$password
            DB_SSLMODE='disable';DB_EMULATE_PREPARES='false'
            DB_MIGRATIONS_URL='';DB_MIGRATIONS_HOST='127.0.0.1'
            DB_MIGRATIONS_PORT='55433';DB_MIGRATIONS_DATABASE='contabilidad_local'
            DB_MIGRATIONS_USERNAME=$user;DB_MIGRATIONS_PASSWORD=$password
            DB_MIGRATIONS_SSLMODE='disable'
            CACHE_STORE='file';SESSION_DRIVER='array';QUEUE_CONNECTION='sync'
            NEON_TEST_BRANCH_NAME='';NEON_TEST_BRANCH_HOST=''
        }
        BackupLocal
        & php $target
        if($LASTEXITCODE -ne 0){throw ('Carga abortada: '+$target+'. No repetir sin inspeccionar el estado.')}
        Write-Host '[PASS] Proceso local terminado; .env principal no fue modificado.'
        $success=$true
    }
}catch{
    Write-Host ('[BLOQUEO] '+$_.Exception.Message)
    Write-Host '[SEGURIDAD] No ejecutar db:seed general ni migrate:fresh. Preservar backups.'
}finally{
    foreach($key in $originalProcess.Keys){
        [Environment]::SetEnvironmentVariable($key,$originalProcess[$key],'Process')
    }
    Set-Location $prevLocation
}
if(-not $success){exit 1}
