#requires -Version 5.1
<#
  Preparacion controlada del upgrade PHP 8.5 + Laravel 13 + Pest 4.
  Sin parametros: resuelve dependencias con Composer --dry-run y
  RESTAURA composer.json/composer.lock al salir.
  -Apply: resuelve e instala dependencias; genera composer.lock localmente
  y comprueba el framework. NO toca PostgreSQL ni ejecuta migraciones/seeders.

  Uso desde backend:
    .\scripts\phase-php85-upgrade-laravel.ps1
    .\scripts\phase-php85-upgrade-laravel.ps1 -Apply

  Requiere un git working tree limpio para composer.json y composer.lock.
  Se mantienen copias con timestamp fuera del repo en %TEMP%.
#>
param([switch]$Apply)

$ErrorActionPreference = 'Stop'
$backend = Split-Path -Parent $PSScriptRoot
$repoRoot = Split-Path -Parent $backend
$backup = Join-Path ([System.IO.Path]::GetTempPath()) ("contabilidad-composer-85-" + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
$applied = $false
$previousLocation = Get-Location

function Invoke-Checked {
    param([string]$File, [string[]]$Arguments)
    Write-Host ('[EXEC] ' + $File + ' ' + ($Arguments -join ' '))
    & $File @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Comando fallo (codigo $LASTEXITCODE): $File"
    }
}

try {
    Set-Location $repoRoot
    $changes = @(& git status --porcelain -- backend/composer.json backend/composer.lock)
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo comprobar git status.' }
    if ($changes.Count -gt 0 -and ($changes -join '').Trim() -ne '') {
        throw 'composer.json o composer.lock ya tiene cambios locales; guarde o revise antes de continuar.'
    }

    Set-Location $backend
    $phpVersion = & php -r 'echo PHP_VERSION;'
    if ($LASTEXITCODE -ne 0 -or $phpVersion -notmatch '^8\.5\.[0-9]+') {
        throw "Se requiere PHP 8.5.x; detectado: $phpVersion"
    }

    Invoke-Checked 'composer' @('validate', '--no-check-publish')
    Invoke-Checked 'composer' @('check-platform-reqs')

    New-Item -ItemType Directory -Path $backup -Force | Out-Null
    Copy-Item composer.json (Join-Path $backup 'composer.json') -ErrorAction Stop
    Copy-Item composer.lock (Join-Path $backup 'composer.lock') -ErrorAction Stop
    Write-Host ("[BACKUP] Manifiestos guardados en: $backup")

    # composer.bat (cmd.exe) consume los caracteres ^ pasados desde
    # PowerShell: php:^8.5 acaba transformado en php:8.5 (version exacta).
    # Editar JSON temporalmente, en lugar de pasar ^ por composer.bat.
    $manifestPath = Join-Path $backend 'composer.json'
    $manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
    $manifest.require.php = '^8.5'
    $manifest.require.'laravel/framework' = '^13.0'
    $manifest.'require-dev'.'pestphp/pest' = '^4.0'
    # 4.0.0 NO admite Laravel 13; 4.1.0 si.
    $manifest.'require-dev'.'pestphp/pest-plugin-laravel' = '^4.1'
    $phpunitProperty = $manifest.'require-dev'.PSObject.Properties['phpunit/phpunit']
    if ($null -eq $phpunitProperty) {
        $manifest.'require-dev' | Add-Member -NotePropertyName 'phpunit/phpunit' -NotePropertyValue '^12.0'
    }
    else {
        $manifest.'require-dev'.'phpunit/phpunit' = '^12.0'
    }
    $json = $manifest | ConvertTo-Json -Depth 50
    [System.IO.File]::WriteAllText(
        $manifestPath, ($json + [Environment]::NewLine),
        (New-Object System.Text.UTF8Encoding($false))
    )
    Write-Host '[INFO] Manifest temporal: PHP ^8.5, Laravel ^13.0, Pest ^4.0, plugin Laravel ^4.1, PHPUnit ^12.0'
    Invoke-Checked 'composer' @('validate', '--no-check-publish', '--no-check-lock')

    # Resolver PRIMERO sin instalar, sin scripts ni alterar composer.lock.
    # El formato plain imprime identificacion, severidad y rangos afectados
    # de avisos de seguridad de las versiones RESUELTAS (no el viejo lock).
    Invoke-Checked 'composer' @(
        'update', '--with-all-dependencies', '--dry-run', '--no-scripts',
        '--no-interaction', '--no-progress', '--audit-format=plain'
    )
    Write-Host '[PASS] Composer resolvio dependencias objetivo en modo dry-run.'
    Write-Host '[REVISION] Inspeccionar los avisos de seguridad detallados antes de -Apply.'

    if (-not $Apply) {
        Write-Host '[PLAN] No se instalaron dependencias ni se cambiaron archivos.'
        Write-Host '[PLAN] Para aplicar, ejecute: .\scripts\phase-php85-upgrade-laravel.ps1 -Apply'
    }
    else {
        Invoke-Checked 'composer' @(
            'update', '--with-all-dependencies', '--no-interaction', '--no-progress'
        )
        Invoke-Checked 'composer' @('validate', '--strict', '--no-check-publish')
        Invoke-Checked 'composer' @('check-platform-reqs')
        Invoke-Checked 'php' @('artisan', '--version')

        $lock = Get-Content composer.lock -Raw | ConvertFrom-Json
        $installed = @($lock.packages | Where-Object { $_.name -eq 'laravel/framework' })
        $pest = @($lock.'packages-dev' | Where-Object { $_.name -eq 'pestphp/pest' })
        if ($installed.Count -ne 1 -or $installed[0].version -notmatch '^v?13\.' -or
            $pest.Count -ne 1 -or $pest[0].version -notmatch '^v?4\.') {
            throw "El lock no contiene Laravel 13 y Pest 4 segun lo esperado."
        }
        $applied = $true
        Write-Host ('[PASS] Lock: Laravel ' + $installed[0].version + ' / Pest ' + $pest[0].version)
        Write-Host '[SIGUIENTE] Validar rutas HTTP GET y seguridad antes de subir el lock a GitHub.'
        Write-Host '[SIGUIENTE] php artisan test tests/Feature/Security/ExistingCompaniesHttpReadOnlyTest.php'
    }
}
catch {
    Write-Host ('[FAIL] ' + $_.Exception.Message)
    exit 1
}
finally {
    if (-not $applied -and (Test-Path (Join-Path $backup 'composer.json'))) {
        Set-Location $backend
        Copy-Item (Join-Path $backup 'composer.json') composer.json -Force
        Copy-Item (Join-Path $backup 'composer.lock') composer.lock -Force
        Write-Host '[RESTORE] composer.json y composer.lock originales restaurados.'
        if ($Apply) {
            Write-Host '[ATENCION] Si Composer habia iniciado la instalacion, vendor/ podria contener paquetes nuevos.'
            Write-Host '[ATENCION] Una vez resuelta la causa, ejecutar composer install para restaurar vendor/ segun el lock.'
        }
    }
    Set-Location $previousLocation
}
