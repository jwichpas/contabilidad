#requires -Version 5.1
<#
  FASE 0 - Respaldo logico del ERP (SOLO esquema public)
  Supabase contabilidad-database -> archivo custom pg_dump para PostgreSQL 17.

  IMPORTANTE:
  - LEE la BD origen, no ejecuta DDL/DML ni restauraciones.
  - No incluye auth.users ni otros esquemas Supabase, Storage u objetos externos.
  - Requiere destino existente fuera de Git y CONFIRMADO CIFRADO.
  - La contrasena de postgres se solicita en pantalla sin eco; nunca en argumentos.

  Uso desde backend:
  .\scripts\phase0-backup-windows.ps1 -Destination "D:\RespaldosCifrados\contabilidad" -EncryptedDestinationConfirmed

  Debe disponer de PostgreSQL client 17 (pg_dump, pg_restore, psql).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Destination,
    [switch]$EncryptedDestinationConfirmed,
    [ValidateSet('aws-1-ca-central-1.pooler.supabase.com','db.bnjwxphwtkhblvhsnylj.supabase.co')]
    [string]$DbHost = 'aws-1-ca-central-1.pooler.supabase.com',
    [ValidateSet('postgres.bnjwxphwtkhblvhsnylj','postgres')]
    [string]$DbUser = 'postgres.bnjwxphwtkhblvhsnylj'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$project = 'bnjwxphwtkhblvhsnylj'
$port = '5432'  # Pooler sesion / directo, no 6543 transaccional
$dbName = 'postgres'
$repo = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..')).TrimEnd('\','/')
$oldPassword = [Environment]::GetEnvironmentVariable('PGPASSWORD','Process')
$oldHost = [Environment]::GetEnvironmentVariable('PGHOST','Process')
$oldPort = [Environment]::GetEnvironmentVariable('PGPORT','Process')
$oldUser = [Environment]::GetEnvironmentVariable('PGUSER','Process')
$oldDb = [Environment]::GetEnvironmentVariable('PGDATABASE','Process')
$oldSsl = [Environment]::GetEnvironmentVariable('PGSSLMODE','Process')
$partial = $null
$archive = $null
$indexTemp = $null
$password = $null
$bstr = [IntPtr]::Zero
$success = $false

try {
    if (!$EncryptedDestinationConfirmed) {
        throw 'Debe confirmar que la unidad/carpeta destino esta cifrada: -EncryptedDestinationConfirmed.'
    }
    if (!(Test-Path -LiteralPath $Destination -PathType Container)) {
        throw 'La carpeta de destino no existe. Creela en una unidad cifrada, FUERA de Git.'
    }
    $dir = (Resolve-Path -LiteralPath $Destination).ProviderPath.TrimEnd('\','/')
    if ($dir.Equals($repo,[StringComparison]::OrdinalIgnoreCase) -or
        $dir.StartsWith($repo + [System.IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) {
        throw 'BLOQUEO: No se permiten respaldos dentro del repositorio.'
    }
    # No permitir exportaciones a ubicaciones de red por accidente.
    if ($dir.StartsWith('\\')) { throw 'Use un volumen cifrado local, no una carpeta UNC compartida.' }

    if (($DbHost -eq 'db.bnjwxphwtkhblvhsnylj.supabase.co' -and $DbUser -ne 'postgres') -or
        ($DbHost -eq 'aws-1-ca-central-1.pooler.supabase.com' -and $DbUser -ne "postgres.$project")) {
        throw 'La combinacion de host y rol no corresponde al entorno Supabase aprobado.'
    }

    foreach ($command in @('pg_dump','pg_restore','psql')) {
        $tool = Get-Command $command -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -eq $tool) { throw "No se encuentra $command en PATH. Instale PostgreSQL client 17." }
        $ver = (& $tool.Source '--version' | Select-Object -First 1)
        if ($LASTEXITCODE -ne 0 -or !([string]$ver -match '\b17\.\d+')) {
            throw "$command debe ser version 17.x, compatible con el PostgreSQL 17 de desarrollo."
        }
        Write-Host "[OK] $command 17 detectado."
    }

    Write-Host "[FUENTE] Proyecto $project, base postgres; exportacion limitada a public."
    Write-Host '[SEGURIDAD] No se guardaran passwords. El destino debe tener cifrado real y ACL restringidas.'
    $secure = Read-Host 'Contrasena ADMINISTRATIVA de la base Supabase (no mostrar)' -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    $bstr = [IntPtr]::Zero
    if ([string]::IsNullOrEmpty($password)) { throw 'No se proporciono la contrasena.' }

    $env:PGHOST = $DbHost
    $env:PGPORT = $port
    $env:PGDATABASE = $dbName
    $env:PGUSER = $DbUser
    $env:PGPASSWORD = $password
    $env:PGSSLMODE = 'require'
    $password = $null

    # Guarda de identidad de solo lectura antes de lanzar pg_dump.
    $guardSql = "SELECT (current_database()='postgres' AND current_user='postgres')::int"
    $guard = & psql --no-password --no-psqlrc --set ON_ERROR_STOP=1 --tuples-only --no-align --command $guardSql 2>&1
    if ($LASTEXITCODE -ne 0 -or ($guard | Out-String).Trim() -ne '1') {
        throw 'No se confirmo la identidad postgres/postgres del servidor esperado. Operacion bloqueada.'
    }

    $stamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ')
    $archive = Join-Path $dir "contabilidad-public-$stamp.dump"
    $partial = "$archive.partial"
    $indexTemp = "$archive.list.partial"
    if ((Test-Path -LiteralPath $archive) -or (Test-Path -LiteralPath $partial)) {
        throw 'El nombre de respaldo ya existe. No se sobreescribira.'
    }

    Write-Host '[BACKUP] pg_dump custom de public: solo SELECT/snapshot, sin modificar BD.'
    & pg_dump --no-password --format=custom --schema=public --no-owner --no-acl --file $partial --host $DbHost --port $port --username $DbUser --dbname $dbName
    if ($LASTEXITCODE -ne 0) { throw 'pg_dump devolvio error; respaldo incompleto.' }
    if (!(Test-Path -LiteralPath $partial) -or (Get-Item -LiteralPath $partial).Length -lt 1024) {
        throw 'Archivo de respaldo vacio o demasiado pequeno.'
    }

    & pg_restore --list $partial 1> $indexTemp
    if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $indexTemp) -or
        (Get-Item -LiteralPath $indexTemp).Length -lt 10) {
        throw 'No se pudo leer el indice del archivo con pg_restore --list.'
    }

    # Los conteos son una consulta posterior al dump: si hay actividad
    # concurrente, pueden diferir del snapshot exacto de pg_dump.
    $countSql = "SELECT json_build_object('companies',(SELECT count(*) FROM public.companies),'users',(SELECT count(*) FROM public.users),'memberships',(SELECT count(*) FROM public.company_user),'accounts',(SELECT count(*) FROM public.accounting_accounts),'entries',(SELECT count(*) FROM public.journal_entries),'lines',(SELECT count(*) FROM public.journal_entry_lines),'migrations',(SELECT count(*) FROM public.migrations))::text"
    $raw = & psql --no-password --no-psqlrc --set ON_ERROR_STOP=1 --tuples-only --no-align --command $countSql
    if ($LASTEXITCODE -ne 0) { throw 'La consulta de conteos de referencia fallo.' }
    $counts = (($raw | Out-String).Trim() | ConvertFrom-Json)

    # Validaciones minimas, sin presumir que el conteo sera siempre el mismo.
    if ($counts.companies -lt 1 -or $counts.users -lt 1 -or $counts.accounts -lt 1) {
        throw 'El origen no tiene los datos esenciales del ERP.'
    }

    Move-Item -LiteralPath $partial -Destination $archive -ErrorAction Stop
    $partial = $null
    Move-Item -LiteralPath $indexTemp -Destination "$archive.list" -ErrorAction Stop
    $indexTemp = $null
    $hash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash
    $manifest = [ordered]@{
        project = 'contabilidad-database'
        project_ref = $project
        schema = 'public'
        created_utc = [DateTime]::UtcNow.ToString('o')
        archive_file = (Split-Path -Leaf $archive)
        sha256 = $hash
        source_counts_after_dump = $counts
        warning = 'Solo public. Conteos pueden variar por actividad concurrente. No equivale a restauracion probada.'
        includes_supabase_auth = $false
        includes_storage_objects = $false
        recovery_verified = $false
    }
    $manifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath "$archive.manifest.json" -Encoding UTF8

    Write-Host "[PASS] Respaldo creado: $archive"
    Write-Host "[PASS] SHA256: $hash"
    Write-Host "[PASS] Indice: $archive.list"
    Write-Host "[PASS] Manifiesto: $archive.manifest.json"
    Write-Host '[PENDIENTE] Ejecutar restauracion aislada para demostrar recuperacion.'
    $success = $true
}
catch {
    Write-Error -Message ('[ERROR] No se pudo completar el respaldo: ' + $_.Exception.Message) -ErrorAction Continue
}
finally {
    if ($bstr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
    if ($null -ne $partial -and (Test-Path -LiteralPath $partial)) { Remove-Item -LiteralPath $partial -Force }
    if ($null -ne $indexTemp -and (Test-Path -LiteralPath $indexTemp)) { Remove-Item -LiteralPath $indexTemp -Force }
    [Environment]::SetEnvironmentVariable('PGPASSWORD',$oldPassword,'Process')
    [Environment]::SetEnvironmentVariable('PGHOST',$oldHost,'Process')
    [Environment]::SetEnvironmentVariable('PGPORT',$oldPort,'Process')
    [Environment]::SetEnvironmentVariable('PGUSER',$oldUser,'Process')
    [Environment]::SetEnvironmentVariable('PGDATABASE',$oldDb,'Process')
    [Environment]::SetEnvironmentVariable('PGSSLMODE',$oldSsl,'Process')
}
if (!$success) { exit 1 }
