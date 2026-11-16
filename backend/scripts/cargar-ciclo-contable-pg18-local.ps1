#requires -Version 5.1
<#
  Laboratorio contable F5/F7: 14 asientos ficticios persistentes en Docker,
  usando la ruta HTTP POST real de Laravel (no INSERT SQL de diarios).

  .\scripts\cargar-ciclo-contable-pg18-local.ps1 -Accion Plan
  .\scripts\cargar-ciclo-contable-pg18-local.ps1 -Accion Simular
  .\scripts\cargar-ciclo-contable-pg18-local.ps1 -Accion Aplicar
  .\scripts\cargar-ciclo-contable-pg18-local.ps1 -Accion Verificar

  Plan/Verificar: lectura. Simular: transaccion revertida.
  Aplicar: backup local obligatorio + POST + COMMIT solo si pasa.
  No usa ni modifica Neon, .env, usuarios comerciales ni otros tenants.
#>
param([ValidateSet('Plan','Simular','Aplicar','Verificar')][string]$Accion='Plan')
$ErrorActionPreference='Stop'
$backend=Split-Path -Parent $PSScriptRoot
$oldDir=Get-Location
$container='contabilidad-pg18-local'
$volume='contabilidad-pg18-local-data'
$settingsFile=Join-Path $backend '.env.pg18.local'
$original=@{}
$ok=$false

function SqlDocker([string]$sql){
  $result=(& docker exec $container psql -U postgres -d contabilidad_local -X -A -t -v ON_ERROR_STOP=1 -c $sql 2>&1 | Out-String).Trim()
  if($LASTEXITCODE -ne 0){throw 'Error al verificar SQL en Docker local.'}
  return $result
}
function CheckDocker {
  $os=(& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
  if($LASTEXITCODE -ne 0 -or $os -ne 'linux'){throw 'Docker Desktop Linux no disponible.'}
  $label=(& docker inspect --format '{{index .Config.Labels "contabilidad.local"}}' $container 2>&1 | Out-String).Trim()
  if($LASTEXITCODE -ne 0 -or $label -ne 'pg18-dev'){throw 'No es el contenedor de pruebas autorizado.'}
  $port=(& docker port $container '5432/tcp' 2>&1 | Out-String).Trim()
  if($LASTEXITCODE -ne 0 -or $port -ne '127.0.0.1:55433'){throw 'Puerto Docker inesperado.'}
  $mount=(& docker inspect --format '{{range .Mounts}}{{.Name}}|{{.Destination}}{{end}}' $container 2>&1 | Out-String).Trim()
  if($LASTEXITCODE -ne 0 -or $mount -cne ($volume+'|/var/lib/postgresql')){throw 'Volumen Docker inesperado.'}
  $identity=SqlDocker "SELECT current_database(),current_user,current_setting('server_version_num')::integer"
  if($identity -notmatch '^contabilidad_local[|]postgres[|]18[0-9]{4}$'){throw 'PostgreSQL 18 local no verificado.'}
  $rls=SqlDocker @'
SELECT count(*) FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relname IN
('company_user','journal_entries','journal_entry_lines','company_subdiaries','journal_post_idempotency')
AND c.relrowsecurity AND c.relforcerowsecurity
'@
  if($rls -ne '5'){throw 'No estan los cinco controles FORCED RLS esperados.'}
}
function ReadSecrets {
  if(-not (Test-Path -LiteralPath $settingsFile)){throw 'Falta backend/.env.pg18.local privado.'}
  $settings=@{}
  foreach($line in (Get-Content -LiteralPath $settingsFile)){
    if($line.Trim() -eq '' -or $line.TrimStart().StartsWith('#')){continue}
    $parts=$line -split '=',2
    if($parts.Count -ne 2 -or $parts[0] -notmatch '^[A-Z][A-Z0-9_]*$' -or $settings.ContainsKey($parts[0])){
      throw 'Variables duplicadas o incorrectas en secreto Docker.'
    }
    $settings[$parts[0]]=$parts[1]
  }
  foreach($pair in @(
    @('DB_HOST','127.0.0.1'),@('DB_PORT','55433'),
    @('DB_DATABASE','contabilidad_local'),@('DB_USERNAME','app_backend'),
    @('DB_SSLMODE','disable')
  )) {
    if($settings[$pair[0]] -cne $pair[1]){throw ('La variable Docker no coincide: '+$pair[0])}
  }
  if([string]::IsNullOrWhiteSpace($settings['DB_PASSWORD'])){throw 'Falta clave Docker del usuario app_backend.'}
  return $settings
}
function Env([hashtable]$settings){
  foreach($key in $settings.Keys){
    if(-not $original.ContainsKey($key)){
      $original[$key]=[Environment]::GetEnvironmentVariable($key,'Process')
    }
    [Environment]::SetEnvironmentVariable($key,[string]$settings[$key],'Process')
  }
}
function Backup {
  $dir=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'Contabilidad\Backups'
  if([string]::IsNullOrWhiteSpace($dir)){throw 'No se pudo crear ruta de respaldo.'}
  New-Item -ItemType Directory -Force -Path $dir | Out-Null
  $file=Join-Path $dir ('contabilidad_local_pre_asientos_'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'.dump')
  if(Test-Path -LiteralPath $file){throw 'Ya existe el backup con este nombre.'}
  $tmp='/tmp/ciclo-f5-'+[Guid]::NewGuid().ToString('N')+'.dump'
  try{
    & docker exec $container pg_dump -U postgres -d contabilidad_local -Fc -f $tmp 1>$null 2>$null
    if($LASTEXITCODE -ne 0){throw 'pg_dump fallo; sin permiso para aplicar.'}
    & docker cp ($container+':'+$tmp) $file 1>$null 2>$null
    if($LASTEXITCODE -ne 0){throw 'No se copio el respaldo; sin permiso para aplicar.'}
  }finally{
    & docker exec $container rm -f -- $tmp 1>$null 2>$null
  }
  if(-not (Test-Path -LiteralPath $file) -or (Get-Item $file).Length -lt 1024){
    throw 'Respaldo no valido; ninguna escritura permitida.'
  }
  Write-Host ('[RESPALDO] '+$file)
  return $file
}
try{
  Set-Location $backend
  CheckDocker
  $secrets=ReadSecrets
  if(-not (Test-Path -LiteralPath 'scripts/phase5-docker-financial-lifecycle.php')){
    throw 'No existe el cargador PHP en la rama local.'
  }
  Env @{
    APP_ENV='local';APP_DEBUG='false'
    DB_CONNECTION='pgsql';DB_URL='';DATABASE_URL=''
    DB_HOST='127.0.0.1';DB_PORT='55433';DB_DATABASE='contabilidad_local'
    DB_USERNAME='app_backend';DB_PASSWORD=$secrets['DB_PASSWORD'];DB_SSLMODE='disable'
    DB_EMULATE_PREPARES='false'
    CACHE_STORE='array';SESSION_DRIVER='array';QUEUE_CONNECTION='sync'
    CONTABILIDAD_PG18_DOCKER_VERIFIED='1';CONTABILIDAD_PG18_BACKUP_PATH=''
    NEON_TEST_BRANCH_NAME='';NEON_TEST_BRANCH_HOST=''
  }
  $flag=switch($Accion){
    'Plan' {'--plan'}; 'Simular' {'--probe'}; 'Aplicar' {'--apply'}; 'Verificar' {'--verify'}
  }
  if($Accion -eq 'Aplicar'){
    $backupFile=Backup
    Env @{CONTABILIDAD_PG18_BACKUP_PATH=$backupFile}
  }
  & php scripts/phase5-docker-financial-lifecycle.php $flag
  if($LASTEXITCODE -ne 0){throw ('La prueba '+$Accion+' fallo. Ver salida anterior.')}
  Write-Host ('[PASS] '+$Accion+' concluido en laboratorio local; entorno restaurado.')
  $ok=$true
}catch{
  Write-Host ('[BLOQUEO] '+$_.Exception.Message)
  Write-Host '[SEGURIDAD] No usar SQL INSERT, migrate:fresh, ni repetir Aplicar sin verificar movimientos.'
}finally{
  foreach($key in $original.Keys){
    [Environment]::SetEnvironmentVariable($key,$original[$key],'Process')
  }
  Set-Location $oldDir
}
if(-not $ok){exit 1}
