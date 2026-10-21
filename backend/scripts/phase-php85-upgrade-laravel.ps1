#requires -Version 5.1
<#
  Upgrade controlado: PHP 8.5 / Laravel 13 / Pest 4 (Windows).
  Sin -Apply: dry-run Composer; restaura ambos manifests en finally.
  Con -Apply: actualiza composer.json, composer.lock y vendor; comprueba
  versiones, compatibilidad PHP y auditoria del lock. NO ejecuta SQL,
  migraciones Laravel ni seeders. Se requieren manifests limpios en Git.

  Validar sintaxis ANTES de ejecutar con -Apply:
    $tok = $null; $err = $null
    [System.Management.Automation.Language.Parser]::ParseFile(
      (Resolve-Path .\scripts\phase-php85-upgrade-laravel.ps1).Path,
      [ref]$tok, [ref]$err) | Out-Null
    if ($err.Count) { $err | Format-List; throw 'Error de sintaxis' }
#>
param([switch]$Apply)

$ErrorActionPreference = 'Stop'
$backend = Split-Path -Parent $PSScriptRoot
$repoRoot = Split-Path -Parent $backend
$backupDir = Join-Path ([System.IO.Path]::GetTempPath()) (
    'contabilidad-composer-85-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff')
)
$originalLocation = Get-Location
$success = $false
$errorMessage = $null

function Invoke-Checked {
    param(
        [string]$File,
        [string[]]$Arguments
    )
    Write-Host ('[EXEC] ' + $File + ' ' + ($Arguments -join ' '))
    & $File @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw ('Comando fallido: ' + $File + ' (codigo ' + $LASTEXITCODE + ')')
    }
}

try {
    Set-Location $repoRoot
    $changes = @(& git status --porcelain -- backend/composer.json backend/composer.lock)
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo comprobar git status.'
    }
    if (($changes -join '').Trim().Length -gt 0) {
        throw 'composer.json o composer.lock ya tiene cambios locales.'
    }

    Set-Location $backend
    $phpVersion = & php -r 'echo PHP_VERSION;'
    if ($LASTEXITCODE -ne 0 -or $phpVersion -notmatch '^8\.5\.[0-9]+') {
        throw ('Se requiere PHP 8.5.x: ' + $phpVersion)
    }

    Invoke-Checked 'composer' @('validate', '--no-check-publish')
    Invoke-Checked 'composer' @('check-platform-reqs')

    New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
    Copy-Item composer.json (Join-Path $backupDir 'composer.json')
    Copy-Item composer.lock (Join-Path $backupDir 'composer.lock')
    Write-Host ('[BACKUP] ' + $backupDir)

    # Evitar composer require con ^ porque composer.bat/cmd.exe elimina el ^.
    $manifestPath = Join-Path $backend 'composer.json'
    $manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
    $manifest.require.php = '^8.5'
    $manifest.require.'laravel/framework' = '^13.0'
    $manifest.'require-dev'.'pestphp/pest' = '^4.0'
    $manifest.'require-dev'.'pestphp/pest-plugin-laravel' = '^4.1'
    $phpunitProp = $manifest.'require-dev'.PSObject.Properties['phpunit/phpunit']
    if ($null -eq $phpunitProp) {
        $manifest.'require-dev' | Add-Member -NotePropertyName 'phpunit/phpunit' -NotePropertyValue '^12.0'
    }
    else {
        $manifest.'require-dev'.'phpunit/phpunit' = '^12.0'
    }
    $json = $manifest | ConvertTo-Json -Depth 50
    [System.IO.File]::WriteAllText(
        $manifestPath,
        ($json + [Environment]::NewLine),
        (New-Object System.Text.UTF8Encoding($false))
    )

    Write-Host '[INFO] PHP ^8.5 / Laravel ^13 / Pest ^4 / Plugin Laravel ^4.1 / PHPUnit ^12'
    Invoke-Checked 'composer' @('validate', '--no-check-publish', '--no-check-lock')

    Invoke-Checked 'composer' @(
        'update', '--with-all-dependencies', '--dry-run', '--no-scripts',
        '--no-interaction', '--no-progress', '--audit-format=plain'
    )
    Write-Host '[PASS] Composer resolvio las dependencias objetivo en dry-run.'

    if ($Apply) {
        Invoke-Checked 'composer' @(
            'update', '--with-all-dependencies', '--no-scripts',
            '--no-interaction', '--no-progress'
        )
        Invoke-Checked 'composer' @('validate', '--strict', '--no-check-publish')
        Invoke-Checked 'composer' @('check-platform-reqs')
        Invoke-Checked 'php' @('artisan', '--version')

        $lock = Get-Content composer.lock -Raw | ConvertFrom-Json
        $framework = @($lock.packages | Where-Object { $_.name -eq 'laravel/framework' })
        $pest = @($lock.'packages-dev' | Where-Object { $_.name -eq 'pestphp/pest' })

        if ($framework.Count -ne 1 -or $pest.Count -ne 1) {
            throw 'Faltan Laravel o Pest en el nuevo composer.lock.'
        }

        $laravelText = [string]$framework[0].version
        $pestText = [string]$pest[0].version
        if ($laravelText -notmatch '^v?13\.' -or $pestText -notmatch '^v?4\.') {
            throw ('Versiones inesperadas en lock: Laravel ' + $laravelText + ', Pest ' + $pestText)
        }

        $laravelClean = ($laravelText -replace '^v', '') -split '[-+]'
        $laravelVersion = [version]$laravelClean[0]
        if ($laravelVersion -lt [version]'13.30.0') {
            throw ('Laravel requiere >=13.30.0: ' + $laravelText)
        }

        # Solo el nuevo lock cuenta para validar vulnerabilidades resueltas.
        Invoke-Checked 'composer' @('audit', '--locked', '--format=plain')
        Write-Host '[PASS] Auditoria de seguridad del nuevo composer.lock aprobada.'
        Write-Host ('[PASS] Lock: Laravel ' + $laravelText + ' / Pest ' + $pestText)
        Write-Host '[SIGUIENTE] php artisan test tests/Feature/Security/ExistingCompaniesHttpReadOnlyTest.php'
    }
    else {
        Write-Host '[PLAN] Simulacion completa; no se instalaron paquetes.'
    }
    $success = $true
}
catch {
    $errorMessage = $_.Exception.Message
    Write-Host ('[FAIL] ' + $errorMessage)
}
finally {
    if (-not $success -or -not $Apply) {
        if (Test-Path (Join-Path $backupDir 'composer.json')) {
            Set-Location $backend
            Copy-Item (Join-Path $backupDir 'composer.json') composer.json -Force
            Copy-Item (Join-Path $backupDir 'composer.lock') composer.lock -Force
            Write-Host '[RESTORE] composer.json y composer.lock originales restaurados.'
            if ($Apply -and -not $success) {
                Write-Host '[ATENCION] vendor/ puede requerir composer install para restaurarse al lock anterior.'
            }
        }
    }
    Set-Location $originalLocation
}

if (-not $success) {
    exit 1
}
