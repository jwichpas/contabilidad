#requires -Version 5.1
<#
  Neon F1.6: elimina SOLO variables vacias heredadas de esta sesion PowerShell.
  Los valores NO vacios se conservan; no escribe .env, no conecta a Neon.
  Modo default: muestra nombres. -Apply: limpia SOLO vacios del proceso ACTUAL.
#>
[CmdletBinding()]
param([switch]$Apply)
$ErrorActionPreference = 'Stop'

$keys = @(
  'APP_ENV','APP_KEY','DB_CONNECTION','DB_URL','DATABASE_URL',
  'DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD',
  'DB_SSLMODE','DB_EMULATE_PREPARES',
  'DB_MIGRATIONS_URL','DB_MIGRATIONS_HOST','DB_MIGRATIONS_PORT',
  'DB_MIGRATIONS_DATABASE','DB_MIGRATIONS_USERNAME',
  'DB_MIGRATIONS_PASSWORD','DB_MIGRATIONS_SSLMODE',
  'DB_APP_BACKEND_ROLE_PASSWORD','DB_APP_BACKEND_NEW_PASSWORD',
  'NEON_TEST_BRANCH_NAME','NEON_TEST_BRANCH_HOST','NEON_USE_POOLING'
)
$empty = New-Object 'System.Collections.Generic.List[string]'
$nonempty = New-Object 'System.Collections.Generic.List[string]'
foreach ($name in $keys) {
  $value = [Environment]::GetEnvironmentVariable($name,'Process')
  if ($null -eq $value) { continue }
  if ([string]::IsNullOrWhiteSpace($value)) {
    [void]$empty.Add($name)
  } else {
    [void]$nonempty.Add($name)
  }
}
Write-Host ('[INFO] Variables de proceso vacias que ocultan .env: ' + $empty.Count)
if ($empty.Count -gt 0) { Write-Host ('[VACIAS] ' + ($empty -join ', ')) }
Write-Host ('[INFO] Variables no vacias preservadas: ' + $nonempty.Count)
if ($nonempty.Count -gt 0) {
  Write-Host ('[REVISAR] Variables no vacias que pueden prevalecer sobre .env: ' + ($nonempty -join ', '))
}
Write-Host '[SEGURIDAD] No se muestran valores, passwords ni tokens.'
if (-not $Apply) {
  Write-Host '[DRY-RUN] No se ha modificado la sesion.'
  exit 0
}
foreach ($name in $empty) {
  [Environment]::SetEnvironmentVariable($name,$null,'Process')
}
Write-Host ('[PASS] Variables vacias retiradas SOLO de esta sesion PowerShell: ' + $empty.Count)
Write-Host '[SIGUIENTE] Ejecutar php scripts/phase1-neon-preflight.php.'
