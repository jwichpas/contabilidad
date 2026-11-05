#requires -Version 5.1
<#
  PostgreSQL 18 local persistente: crea e inicia/detiene Docker.
  NO realiza migraciones ni conexiones al entorno remoto.
  Credencial admin en .env.pg18.local (ignorado por Git).
#>
param([ValidateSet('Crear','Estado','Iniciar','Detener')][string]$Accion='Crear')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$name='contabilidad-pg18-local'
$volume='contabilidad-pg18-local-data'
$secret=Join-Path $root '.env.pg18.local'
function HasContainer {
  & docker container inspect $name 1>$null 2>$null
  return $LASTEXITCODE -eq 0
}
function HasVolume {
  & docker volume inspect $volume 1>$null 2>$null
  return $LASTEXITCODE -eq 0
}
function Guard {
  if(-not (HasContainer)){throw 'Contenedor local inexistente.'}
  $tag=(& docker inspect --format '{{index .Config.Labels "contabilidad.local"}}' $name | Out-String).Trim()
  if($LASTEXITCODE -ne 0 -or $tag -ne 'pg18-dev'){throw 'El contenedor no tiene la etiqueta local requerida.'}
}
try {
  $os=(& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
  if($LASTEXITCODE -ne 0 -or $os -ne 'linux'){throw 'Docker Linux no disponible.'}
  if($Accion -eq 'Estado'){
    if(HasContainer){Guard; & docker ps -a --filter ('name=^/'+$name+'$') --format '{{.Names}} {{.Status}} {{.Ports}}'}
    else{Write-Host '[INFO] PostgreSQL 18 local no creado.'}
  }elseif($Accion -eq 'Iniciar'){
    Guard
    & docker start $name
    if($LASTEXITCODE -ne 0){throw 'No se pudo iniciar.'}
  }elseif($Accion -eq 'Detener'){
    Guard
    & docker stop $name
    if($LASTEXITCODE -ne 0){throw 'No se pudo detener.'}
  }else{
    if((HasContainer) -or (HasVolume) -or (Test-Path -LiteralPath $secret)){
      throw 'Ya hay contenedor, volumen o configuración local: no sobrescribir.'
    }
    $password='pg-'+[Guid]::NewGuid().ToString('N')
    $volumeCreated=$false
    $containerCreated=$false
    $complete=$false
    try {
      & docker volume create --label 'contabilidad.local=pg18-dev' $volume
      if($LASTEXITCODE -ne 0){throw 'No se pudo crear el volumen.'}
      $volumeCreated=$true
      $dockerArgs=@(
        'run','--detach','--name',$name,
        '--label','contabilidad.local=pg18-dev',
        '--publish','127.0.0.1:55433:5432',
        '--volume',($volume+':/var/lib/postgresql'),
        '--env',('POSTGRES_PASSWORD='+$password),
        '--env','POSTGRES_DB=contabilidad_local',
        '--restart','unless-stopped','postgres:18-alpine'
      )
      & docker @dockerArgs
      if($LASTEXITCODE -ne 0){throw 'No se pudo crear PostgreSQL.'}
      $containerCreated=$true
      $ready=$false
      for($i=0;$i -lt 45;$i++){
        & docker exec $name pg_isready -h 127.0.0.1 -U postgres -d contabilidad_local 1>$null 2>$null
        if($LASTEXITCODE -eq 0){$ready=$true;break}
        Start-Sleep -Seconds 1
      }
      if(-not $ready){throw 'PostgreSQL no respondió.'}
      $version=(& docker exec $name psql -U postgres -d contabilidad_local -X -A -t -c 'SHOW server_version' | Out-String).Trim()
      if($LASTEXITCODE -ne 0 -or $version -notmatch '^18\.[0-9]+'){throw 'La versión no es PostgreSQL 18.'}
      $settings=@(
        'DB_HOST=127.0.0.1','DB_PORT=55433','DB_DATABASE=contabilidad_local',
        'DB_MIGRATIONS_HOST=127.0.0.1','DB_MIGRATIONS_PORT=55433',
        'DB_MIGRATIONS_DATABASE=contabilidad_local','DB_MIGRATIONS_USERNAME=postgres',
        ('DB_MIGRATIONS_PASSWORD='+$password),'DB_MIGRATIONS_SSLMODE=disable'
      )
      [IO.File]::WriteAllText($secret,($settings -join [Environment]::NewLine)+[Environment]::NewLine,(New-Object System.Text.UTF8Encoding($false)))
      $complete=$true
      Write-Host ('[PASS] PostgreSQL '+$version+' en 127.0.0.1:55433.')
      Write-Host ('[PASS] Volumen persistente: '+$volume)
      Write-Host '[PASS] Credencial admin en backend/.env.pg18.local (no compartir).'
      Write-Host '[PENDIENTE] Tablas del ERP aún no instaladas.'
    }finally{
      if(-not $complete){
        if($containerCreated){& docker rm --force $name 1>$null 2>$null}
        if($volumeCreated){& docker volume rm $volume 1>$null 2>$null}
        Write-Host '[LIMPIEZA] Instalación incompleta descartada.'
      }
    }
  }
}catch{
  Write-Host ('[FAIL] '+$_.Exception.Message)
  exit 1
}
