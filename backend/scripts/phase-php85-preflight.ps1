#requires -Version 5.1
<#
  Preflight PHP 8.5 / Windows para el backend Laravel.
  SOLO LECTURA: no cambia PATH, php.ini, Composer, archivos ni PostgreSQL.
  Ejecutar desde backend/ despues de colocar el PHP 8.5 al inicio del PATH
  de la sesion de PowerShell:
      .\scripts\phase-php85-preflight.ps1
#>
$ErrorActionPreference = 'Stop'

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Host '[FAIL] No se encontro php.exe en PATH.'
    exit 1
}

Write-Host ('[INFO] PHP: ' + $php.Source)

# PHP puede devolver exit code 0 aun emitiendo avisos de Startup (por ejemplo
# DLL faltante o extension cargada dos veces). El preflight anterior daba PASS
# si php -m listaba el modulo; ahora verificamos tambien stderr/stdout de -v.
$startInfo = New-Object System.Diagnostics.ProcessStartInfo
$startInfo.FileName = $php.Source
$startInfo.Arguments = '-v'
$startInfo.UseShellExecute = $false
$startInfo.RedirectStandardOutput = $true
$startInfo.RedirectStandardError = $true
$startInfo.CreateNoWindow = $true
$process = [System.Diagnostics.Process]::Start($startInfo)
if (-not $process) {
    Write-Host '[FAIL] No se pudo iniciar php.exe.'
    exit 1
}
$phpStdout = $process.StandardOutput.ReadToEnd()
$phpStderr = $process.StandardError.ReadToEnd()
$process.WaitForExit()

$startupDiagnostics = ($phpStdout + [Environment]::NewLine + $phpStderr)
if ($process.ExitCode -ne 0 -or
    $startupDiagnostics -match '(?i)(PHP Startup:|Unable to load dynamic library|Module "[^"]+" is already loaded)') {
    Write-Host '[FAIL] PHP tiene errores o advertencias de carga de extensiones.'
    if ($phpStderr.Trim()) { Write-Host $phpStderr.Trim() }
    if ($phpStdout -match '(?i)(PHP Startup:|Unable to load dynamic library|Module "[^"]+" is already loaded)') {
        Write-Host $phpStdout.Trim()
    }
    Write-Host '[INFO] Revise php.ini: elimine extensiones duplicadas y DLL inexistentes.'
    Write-Host '[INFO] php -n -m enumera los modulos integrados SIN cargar php.ini.'
    exit 1
}

$version = (& $php.Source -r 'echo PHP_VERSION;')
if ($LASTEXITCODE -ne 0 -or -not ($version -match '^8\.5\.[0-9]+')) {
    Write-Host ('[FAIL] Se requiere PHP 8.5.x; se detecto: ' + $version)
    exit 1
}
Write-Host ('[PASS] PHP ' + $version)

$modules = @(& $php.Source -m)
if ($LASTEXITCODE -ne 0) {
    Write-Host '[FAIL] php -m no se pudo ejecutar.'
    exit 1
}
$required = @('bcmath', 'pdo_pgsql', 'mbstring', 'openssl', 'ctype', 'tokenizer', 'xml', 'fileinfo')
$missing = @($required | Where-Object { $modules -notcontains $_ })
if ($missing.Count -gt 0) {
    Write-Host ('[FAIL] Extensiones faltantes: ' + ($missing -join ', '))
    Write-Host '[INFO] Revise php.ini, extension_dir y extension=...'
    exit 1
}
Write-Host ('[PASS] Extensiones CLI: ' + ($required -join ', '))
& $php.Source --ini

$composer = Get-Command composer -ErrorAction SilentlyContinue
if (-not $composer) {
    Write-Host '[FAIL] Composer no se encontro en PATH.'
    exit 1
}
Write-Host ('[INFO] Composer: ' + $composer.Source)
& composer --version
if ($LASTEXITCODE -ne 0) {
    Write-Host '[FAIL] Composer no se ejecuta con la configuracion actual.'
    exit 1
}

$backend = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path (Join-Path $backend 'composer.lock'))) {
    Write-Host '[FAIL] Falta composer.lock: no continuar con la migracion.'
    exit 1
}

Push-Location $backend
try {
    & composer validate --no-check-publish
    if ($LASTEXITCODE -ne 0) {
        Write-Host '[FAIL] El manifest/lock actual no valida con Composer.'
        exit 1
    }
}
finally {
    Pop-Location
}

Write-Host '[PASS] Preflight de entorno PHP 8.5.'
Write-Host '[PENDIENTE] Laravel 11 no esta oficialmente soportado en PHP 8.5.'
Write-Host '[PENDIENTE] Actualizar Laravel/Pest y resolver composer.lock antes de reanudar F1.'
