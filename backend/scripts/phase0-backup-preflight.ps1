#requires -Version 5.1
<#
  FASE 0 — PREFLIGHT LOCAL DE RESPALDO (SIN EXPORTAR).
  Solo inspecciona herramientas, variables presentes y ruta propuesta.
  NO llama a Supabase ni a PostgreSQL; NO lee claves, no escribe archivos,
  NO invoca pg_dump/pg_restore para exportar/restaurar, NO genera backups.

  PowerShell:
    .\scripts\phase0-backup-preflight.ps1
    .\scripts\phase0-backup-preflight.ps1 -Destination "D:\RespaldosCifrados"
#>
[CmdletBinding()]
param([string]$Destination = '')

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Report([string]$kind, [string]$message) {
    Write-Host ("[{0}] {1}" -f $kind, $message)
}

Report 'INFO' 'Solo comprobaciones locales: NO exporta ni restaura ninguna base.'
Report 'INFO' 'Supabase contabilidad-database: organizacion Free; no se presupone backup diario.'

$repo = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$repo = $repo.TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
$missing = @()

foreach ($tool in @('pg_dump', 'pg_restore')) {
    $found = Get-Command $tool -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $found) {
        Report 'PENDIENTE' ("No se encuentra {0} en PATH." -f $tool)
        $missing += $tool
    } else {
        Report 'OK' ("{0} localizado: {1}" -f $tool, $found.Source)
        # --version es seguro (no conecta a BD, no toca archivos).
        $version = & $found.Source '--version' 2>&1
        Report 'INFO' ("{0}: {1}" -f $tool, ($version | Select-Object -First 1))
    }
}

foreach ($key in @('DB_MIGRATIONS_HOST','DB_MIGRATIONS_USERNAME','DB_MIGRATIONS_PASSWORD')) {
    $present = ![string]::IsNullOrEmpty([Environment]::GetEnvironmentVariable($key,'Process'))
    if ($present) { Report 'OK' ("Variable {0}: definida (valor oculto)." -f $key) }
    else { Report 'PENDIENTE' ("Variable {0}: no disponible en esta terminal." -f $key) }
}

if ([string]::IsNullOrWhiteSpace($Destination)) {
    Report 'PENDIENTE' 'Seleccione una ruta EXTERNA al repositorio, cifrada y con custodia autorizada.'
} else {
    try {
        $candidate = [System.IO.Path]::GetFullPath($Destination)
        $sep = [System.IO.Path]::DirectorySeparatorChar
        if ($candidate.Equals($repo, [StringComparison]::OrdinalIgnoreCase) -or
            $candidate.StartsWith($repo + $sep, [StringComparison]::OrdinalIgnoreCase)) {
            Report 'BLOQUEO' 'La ruta de respaldo no puede estar dentro del repositorio Git.'
            exit 2
        }
        if (!(Test-Path -LiteralPath $candidate -PathType Container)) {
            Report 'PENDIENTE' 'La carpeta indicada no existe (este preflight NO la creara).'
        } else {
            Report 'OK' ("Carpeta existente fuera del repositorio: {0}" -f $candidate)
            Report 'PENDIENTE' 'Verificar cifrado, espacio disponible y permisos con el responsable.'
        }
    } catch {
        Report 'BLOQUEO' 'La ruta proporcionada no es valida.'
        exit 2
    }
}

Report 'INFO' 'pg_dump --schema=public no equivale a una copia completa de Supabase/Auth/Storage/roles.'
Report 'INFO' 'Inventario pg_restore --list no sustituye una restauracion en destino aislado.'
Report 'OK' 'Preflight finalizado: 0 conexiones SQL, 0 archivos creados, 0 respaldos generados.'
if ($missing.Count -gt 0) { exit 1 }
exit 0
