#requires -Version 5.1
<#
  Fase 0: proteger un respaldo PostgreSQL YA EXISTENTE con age.
  No realiza conexiones a PostgreSQL ni modifica Supabase.
  Requiere 'age' (https://age-encryption.org/) y un destinatario age1...
  Conserva los originales hasta que se pruebe la recuperacion cifrada.
  Nunca introduzca claves privadas en GitHub ni en argumentos.
  Ejemplo:
    .\scripts\phase0-encrypt-backup-windows.ps1 -Archive "C:\CONTA\contabilidad-public-20261020T210205261Z.dump" -Recipient "age1..."
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Archive,
    [Parameter(Mandatory=$true)][ValidatePattern('^age1[023456789acdefghjklmnpqrstuvwxyz]{58}$')][string]$Recipient
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$created = @()
$completed = $false
try {
    $age = Get-Command 'age' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $age) { throw 'Falta age en PATH. Instale age desde su distribucion oficial antes de ejecutar.' }
    if (!(Test-Path -LiteralPath $Archive -PathType Leaf)) { throw 'No existe el dump.' }
    $full = (Resolve-Path -LiteralPath $Archive).ProviderPath
    if (![IO.Path]::GetFileName($full).StartsWith('contabilidad-public-', [StringComparison]::OrdinalIgnoreCase) -or
        !($full.EndsWith('.dump', [StringComparison]::OrdinalIgnoreCase))) {
        throw 'Se admite solamente contabilidad-public-*.dump.'
    }
    $repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..')).TrimEnd('\','/')
    if ($full.StartsWith($repo + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'No se admiten respaldos dentro del repositorio.'
    }
    $manifestPath = "$full.manifest.json"
    $listPath = "$full.list"
    foreach ($file in @($manifestPath,$listPath)) {
        if (!(Test-Path -LiteralPath $file -PathType Leaf)) { throw "Archivo acompanante inexistente: $file" }
    }
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    if ($manifest.project_ref -ne 'bnjwxphwtkhblvhsnylj' -or
        $manifest.schema -ne 'public' -or
        $manifest.archive_file -ne [IO.Path]::GetFileName($full)) {
        throw 'El manifiesto no corresponde al respaldo esperado.'
    }
    $hash = (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash
    if ($hash -ne [string]$manifest.sha256) { throw 'SHA256 no coincide; se detuvo el cifrado.' }
    Write-Host '[PASS] SHA256 del original coincide con el manifiesto.'

    $sources = @($full,$listPath,$manifestPath)
    foreach ($source in $sources) {
        if (Test-Path -LiteralPath "$source.age") { throw "Ya existe $source.age; no se sobreescribe." }
        if (Test-Path -LiteralPath "$source.age.partial") { throw "Ya existe un cifrado parcial: $source.age.partial" }
    }
    foreach ($source in $sources) {
        $partial = "$source.age.partial"
        $created += $partial
        & $age.Source -r $Recipient -o $partial $source
        if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $partial -PathType Leaf) -or
            (Get-Item -LiteralPath $partial).Length -le 0) {
            throw "age fallo al cifrar $source"
        }
        Move-Item -LiteralPath $partial -Destination "$source.age" -ErrorAction Stop
        $created += "$source.age"
        Write-Host "[PASS] Cifrado: $source.age"
    }
    Write-Host '[IMPORTANTE] Originales SIN CIFRAR conservados. No son seguros en C:.'
    Write-Host '[SIGUIENTE] Validar descifrado y restauracion antes de eliminar originales.'
    Write-Host '[CLAVE] Guarde la identidad age privada FUERA del equipo y del repositorio. Sin ella no puede recuperar el respaldo.'
    $completed = $true
}
catch {
    Write-Error -Message ('[FAIL] ' + $_.Exception.Message) -ErrorAction Continue
}
finally {
    if (!$completed) {
        foreach ($file in $created) {
            if (Test-Path -LiteralPath $file -PathType Leaf) { Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue }
        }
    }
}
if (!$completed) { exit 1 }
