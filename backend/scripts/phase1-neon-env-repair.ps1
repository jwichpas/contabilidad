#requires -Version 5.1
<#
  Reparacion NO DESTRUCTIVA del .env parcial para Neon contabilidad-dev.
  Default: solo diagnostico. -Apply: guarda backup y completa SOLO variables
  NO SECRETAS faltantes o vacias desde backend/.env.example.
  No imprime ni sustituye passwords, APP_KEY o URLs preexistentes.
#>
[CmdletBinding()]
param([switch]$Apply)
$ErrorActionPreference = 'Stop'
$backend = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $backend '.env'
$examplePath = Join-Path $backend '.env.example'
if (-not (Test-Path -LiteralPath $examplePath)) { throw 'Falta .env.example' }

$allowlist = @(
  'APP_ENV','DB_CONNECTION','DB_URL','DATABASE_URL',
  'NEON_TEST_BRANCH_NAME','NEON_TEST_BRANCH_HOST','NEON_USE_POOLING',
  'DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_SSLMODE','DB_EMULATE_PREPARES',
  'DB_MIGRATIONS_URL','DB_MIGRATIONS_HOST','DB_MIGRATIONS_PORT',
  'DB_MIGRATIONS_DATABASE','DB_MIGRATIONS_USERNAME','DB_MIGRATIONS_SSLMODE'
)
$template = [IO.File]::ReadAllText($examplePath)
$defaults = @{}
foreach ($line in [regex]::Split($template, '\r\n|\n|\r')) {
  if ($line -match '^([A-Z][A-Z0-9_]*)=(.*)$') {
    if ($allowlist -contains $Matches[1]) { $defaults[$Matches[1]] = $Matches[2] }
  }
}
$exists = Test-Path -LiteralPath $envPath
$current = if ($exists) { [IO.File]::ReadAllText($envPath) } else { $template }
$eol = if ($current.Contains([string][char]13 + [string][char]10)) {
  [string][char]13 + [string][char]10
} else { [string][char]10 }
$lines = New-Object 'System.Collections.Generic.List[string]'
foreach ($line in [regex]::Split($current, '\r\n|\n|\r')) {
  [void]$lines.Add($line)
}
$changed = New-Object 'System.Collections.Generic.List[string]'
foreach ($key in $allowlist) {
  if (-not $defaults.ContainsKey($key)) { throw ('La plantilla carece de ' + $key) }
  $indexes = @()
  for ($i = 0; $i -lt $lines.Count; $i++) {
    if ($lines[$i] -match ('^' + [regex]::Escape($key) + '=(.*)$')) {
      $indexes += $i
    }
  }
  if ($indexes.Count -gt 1) { throw ('Variable duplicada: ' + $key) }
  if ($indexes.Count -eq 0) {
    [void]$lines.Add($key + '=' + $defaults[$key])
    [void]$changed.Add($key)
    continue
  }
  $index = [int]$indexes[0]
  $existingValue = $lines[$index].Substring($key.Length + 1).Trim()
  if ($existingValue -in @('', '""', "''")) {
    if ($existingValue -ne [string]$defaults[$key]) {
      $lines[$index] = $key + '=' + $defaults[$key]
      [void]$changed.Add($key)
    }
  }
}
Write-Host ('[INFO] backend/.env existe: ' + $(if ($exists) { 'SI' } else { 'NO' }))
if (-not $exists) { Write-Host '[PLAN] Crear .env completo desde .env.example (passwords vacios).' }
Write-Host ('[PLAN] Claves no secretas por completar: ' + $changed.Count)
if ($changed.Count -gt 0) { Write-Host ($changed -join ', ') }
Write-Host '[SEGURIDAD] No modifica APP_KEY ni passwords ni valores existentes no vacios.'
foreach ($key in @('APP_ENV','DB_CONNECTION','DB_HOST','DB_MIGRATIONS_HOST')) {
  if ($null -ne [Environment]::GetEnvironmentVariable($key,'Process')) {
    Write-Host ('[AVISO] Variable de proceso ' + $key + ' puede prevalecer sobre .env.')
  }
}
if (-not $Apply) {
  Write-Host '[DRY-RUN] No se modifico ningun archivo. Ejecutar con -Apply para completar.'
  exit 0
}
if ($changed.Count -eq 0 -and $exists) {
  Write-Host '[PASS] Ninguna clave no secreta por reparar.'
  exit 0
}
if ($exists) {
  $backup = Join-Path $backend ('.env.backup.' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
  Copy-Item -LiteralPath $envPath -Destination $backup
  Write-Host ('[BACKUP] ' + $backup)
}
$contentToWrite = ($lines -join $eol).TrimEnd([char[]]@([char]13,[char]10)) + $eol
$tempPath = Join-Path $backend '.env.neon-repair.tmp'
try {
  [IO.File]::WriteAllText($tempPath,$contentToWrite,(New-Object System.Text.UTF8Encoding($false)))
  Move-Item -LiteralPath $tempPath -Destination $envPath -Force
} finally {
  if (Test-Path -LiteralPath $tempPath) {
    Remove-Item -LiteralPath $tempPath -Force
  }
}
Write-Host '[PASS] Configuracion publica Neon completada. Claves privadas permanecen sin cambios.'
Write-Host '[SIGUIENTE] Completar passwords en .env privado y ejecutar el preflight Neon.'
