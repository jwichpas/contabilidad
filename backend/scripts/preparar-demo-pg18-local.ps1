#requires -Version 5.1
<#
  One-time demo fixture, separate Laravel HTTP server on port 8011,
  and HTTP latency measurement using Sanctum against LOCAL PG18 only.
  -Accion Crear | Servidor | Medir
#>
param([ValidateSet('Crear','Servidor','Medir')][string]$Accion='Crear')
$ErrorActionPreference='Stop'
$backend=Split-Path -Parent $PSScriptRoot
$prior=Get-Location
$privateDb=Join-Path $backend '.env.pg18.local'
$privateDemo=Join-Path $backend '.env.pg18.demo.local'
$originalEnv=@{}
$success=$false

function Read-Private([string]$path){
    if(-not (Test-Path -LiteralPath $path)){
        throw 'Falta un archivo privado de PostgreSQL local.'
    }
    $map=@{}
    foreach($line in (Get-Content -LiteralPath $path)){
        if([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')){continue}
        $parts=$line -split '=',2
        if($parts.Count -ne 2 -or $parts[0] -notmatch '^[A-Z][A-Z0-9_]*$'){
            throw 'Archivo privado local mal formado.'
        }
        if($map.ContainsKey($parts[0])){throw 'Clave privada duplicada.'}
        $map[$parts[0]]=$parts[1]
    }
    return $map
}
function Override-Env([hashtable]$vars){
    foreach($key in $vars.Keys){
        if(-not $originalEnv.ContainsKey($key)){
            $originalEnv[$key]=[Environment]::GetEnvironmentVariable($key,'Process')
        }
        [Environment]::SetEnvironmentVariable($key,[string]$vars[$key],'Process')
    }
}
function Check-Container([hashtable]$local){
    if($local['DB_HOST'] -ne '127.0.0.1' -or
       $local['DB_PORT'] -ne '55433' -or
       $local['DB_DATABASE'] -ne 'contabilidad_local' -or
       $local['DB_USERNAME'] -ne 'app_backend' -or
       [string]::IsNullOrWhiteSpace($local['DB_PASSWORD']) -or
       $local['DB_MIGRATIONS_HOST'] -ne '127.0.0.1' -or
       $local['DB_MIGRATIONS_PORT'] -ne '55433' -or
       $local['DB_MIGRATIONS_DATABASE'] -ne 'contabilidad_local'){
        throw 'Bloqueado: conexión privada no corresponde a PG18 local.'
    }
    $name='contabilidad-pg18-local'
    $tag=(& docker inspect --format '{{index .Config.Labels "contabilidad.local"}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $tag -ne 'pg18-dev'){
        throw 'Contenedor PG18 no coincide con la instalación local.'
    }
    $mapped=(& docker port $name '5432/tcp' 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $mapped -ne '127.0.0.1:55433'){
        throw 'Puerto Docker no coincide con loopback 55433.'
    }
    $mount=(& docker inspect --format '{{range .Mounts}}{{.Name}}|{{.Destination}}{{end}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $mount -ne 'contabilidad-pg18-local-data|/var/lib/postgresql'){
        throw 'El volumen PostgreSQL 18 local no coincide.'
    }
    & docker exec $name pg_isready -h 127.0.0.1 -U postgres -d contabilidad_local 1>$null 2>$null
    if($LASTEXITCODE -ne 0){throw 'PostgreSQL 18 local no está disponible.'}
}
function Get-Median([double[]]$values){
    $sorted=@($values | Sort-Object)
    $n=$sorted.Count
    if($n -eq 0){throw 'Sin mediciones.'}
    if(($n % 2) -eq 1){return [double]$sorted[[int][Math]::Floor($n/2)]}
    $middle=[int]($n/2)
    return ([double]$sorted[$middle-1]+[double]$sorted[$middle])/2
}
try {
    Set-Location $backend
    if(Test-Path 'bootstrap/cache/config.php'){
        throw 'Laravel tiene config cacheada; no ejecutar entorno local.'
    }
    $local=Read-Private $privateDb
    Check-Container $local
    Override-Env @{
        APP_ENV='local'; APP_DEBUG='false'
        APP_URL='http://127.0.0.1:8011'
        DB_CONNECTION='pgsql'; DB_URL=''; DATABASE_URL=''
        DB_HOST='127.0.0.1'; DB_PORT='55433'; DB_DATABASE='contabilidad_local'
        DB_USERNAME='app_backend'; DB_PASSWORD=$local['DB_PASSWORD']
        DB_SSLMODE='disable'; DB_EMULATE_PREPARES='false'
        CACHE_STORE='file'; SESSION_DRIVER='array'; QUEUE_CONNECTION='sync'
        SANCTUM_TRACK_LAST_USED_AT='false'
    }
    if($Accion -eq 'Crear'){
        if(Test-Path -LiteralPath $privateDemo){
            throw 'El usuario ficticio ya existe; no crear dos veces.'
        }
        & php scripts/crear-fixture-pg18-local.php
        if($LASTEXITCODE -ne 0){throw 'No se completó la creación ficticia.'}
        $success=$true
    }elseif($Accion -eq 'Servidor'){
        if(-not (Test-Path -LiteralPath $privateDemo)){
            throw 'Primero ejecutar -Accion Crear.'
        }
        $busy=Get-NetTCPConnection -LocalPort 8011 -State Listen -ErrorAction SilentlyContinue
        if($busy){throw 'Puerto 8011 ya ocupado. No sustituir servicio existente.'}
        Write-Host '[LOCAL] Laravel PG18: http://127.0.0.1:8011; detener con Ctrl+C.'
        Write-Host '[LOCAL] app_backend conectado solo a 127.0.0.1:55433.'
        $success=$true
        & php artisan serve --host=127.0.0.1 --port=8011
    }else{
        $demo=Read-Private $privateDemo
        if($demo['LOCAL_DEMO_EMAIL'] -ne 'demo.pg18@example.test' -or
           $demo['LOCAL_DEMO_COMPANY_ID'] -notmatch '^[1-9][0-9]*$' -or
           [string]::IsNullOrWhiteSpace($demo['LOCAL_DEMO_PASSWORD'])){
            throw 'El archivo del usuario ficticio no es válido.'
        }
        $base='http://127.0.0.1:8011'
        $body=@{
            email=$demo['LOCAL_DEMO_EMAIL']
            password=$demo['LOCAL_DEMO_PASSWORD']
            device_name='pg18-latency-measurement'
        } | ConvertTo-Json -Compress
        try {
            $login=Invoke-RestMethod -Method Post -Uri ($base+'/api/v1/auth/login') -ContentType 'application/json' -Body $body
        }catch{
            throw 'El login local 8011 falló. Comprobar la terminal Servidor.'
        }
        $token=[string]$login.data.token
        if([string]::IsNullOrWhiteSpace($token) -or
           [string]$login.data.user.current_company_id -ne $demo['LOCAL_DEMO_COMPANY_ID']){
            throw 'El token o la empresa del login no coinciden con la demostración.'
        }
        $allowed=$false
        foreach($member in @($login.data.companies)){
            if([string]$member.id -eq $demo['LOCAL_DEMO_COMPANY_ID']){$allowed=$true}
        }
        if(-not $allowed){throw 'Membresía no encontrada en login autorizado.'}
        $headers=@{
            Authorization=('Bearer '+$token)
            Accept='application/json'
            'X-Company-Id'=$demo['LOCAL_DEMO_COMPANY_ID']
        }
        $date=(Get-Date).ToString('yyyy-MM-dd')
        $totals=New-Object 'System.Collections.Generic.List[double]'
        $auths=New-Object 'System.Collections.Generic.List[double]'
        $catalogs=New-Object 'System.Collections.Generic.List[double]'
        for($i=1;$i -le 5;$i++){
            try{
                $url=$base+'/api/v1/accounting/journal-entries/editor-bootstrap?date='+$date
                $response=Invoke-WebRequest -UseBasicParsing -Method Get -Uri $url -Headers $headers
            }catch{
                throw ('Solicitud '+$i+' falló; no se muestran secretos.')
            }
            if($response.StatusCode -ne 200){throw ('HTTP inesperado: '+$response.StatusCode)}
            $data=$response.Content | ConvertFrom-Json
            if($null -eq $data.data -or $null -eq $data.data.accounts -or
               @($data.data.accounts).Count -ne 200){
                throw 'El Libro Diario no devolvió las 200 cuentas sintéticas esperadas.'
            }
            $timing=[string]$response.Headers['Server-Timing']
            if($timing -notmatch 'request_total;dur=([0-9.]+)'){throw 'Falta request_total.'}
            $total=[double]::Parse($Matches[1],[cultureinfo]::InvariantCulture)
            if($timing -notmatch 'auth_sql_total;dur=([0-9.]+)'){throw 'Falta auth_sql_total.'}
            $auth=[double]::Parse($Matches[1],[cultureinfo]::InvariantCulture)
            if($timing -notmatch 'catalogs_sql;dur=([0-9.]+)'){throw 'Falta catalogs_sql.'}
            $catalog=[double]::Parse($Matches[1],[cultureinfo]::InvariantCulture)
            $totals.Add($total);$auths.Add($auth);$catalogs.Add($catalog)
            Write-Host ('Muestra '+$i+': total '+$total.ToString('0.0')+' ms | auth_sql '+$auth.ToString('0.0')+' ms | catalogs_sql '+$catalog.ToString('0.0')+' ms')
        }
        $a=Get-Median $totals.ToArray()
        $b=Get-Median $auths.ToArray()
        $c=Get-Median $catalogs.ToArray()
        Write-Host ('MEDIANAS: request_total '+$a.ToString('0.0')+' ms | auth_sql_total '+$b.ToString('0.0')+' ms | catalogs_sql '+$c.ToString('0.0')+' ms')
        Write-Host '[REFERENCIA] Petición remota anterior: request_total 3522.1 ms.'
        Write-Host '[NOTA] Datos sintéticos, carga y configuración de base diferentes.'
        $success=$true
    }
}catch{
    Write-Host ('[FAIL] '+$_.Exception.Message)
}finally{
    foreach($key in $originalEnv.Keys){
        [Environment]::SetEnvironmentVariable($key,$originalEnv[$key],'Process')
    }
    Set-Location $prior
}
if(-not $success){exit 1}
