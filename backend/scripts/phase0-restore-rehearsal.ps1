#requires -Version 5.1
<#
  FASE 0 - Simulacro NO DESTRUCTIVO sobre PostgreSQL 17 aislado con Docker.
  REQUIERE ARCHIVO creado por phase0-backup-windows.ps1.

  - NO SE CONECTA A SUPABASE. NO HAY PUERTOS PUBLICADOS. --network none.
  - Docker crea contenedor efimero, restaura public y verifica conteos.
  - El contenedor y sus datos temporales se DESTRUYEN al terminar el ensayo.
  - El respaldo original y su manifiesto se mantienen intactos.
  - Solo puede funcionar con Docker Linux containers + imagen postgres:17-alpine.

  Uso: .\scripts\phase0-restore-rehearsal.ps1 -Archive "D:\RespaldosCifrados\contabilidad\contabilidad-public-...dump"
#>
[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$Archive)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$dockerName = 'contabilidad-f0-restore-' + ([guid]::NewGuid().ToString('N').Substring(0,12))
$started = $false
$ok = $false

try {
    $docker = Get-Command 'docker' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $docker) { throw 'Docker Desktop no disponible en PATH. Instale/configure contenedores Linux.' }
    if (!(Test-Path -LiteralPath $Archive -PathType Leaf)) { throw 'No existe el dump indicado.' }
    $full = (Resolve-Path -LiteralPath $Archive).ProviderPath
    if (![System.IO.Path]::GetFileName($full).StartsWith('contabilidad-public-', [StringComparison]::OrdinalIgnoreCase) -or
        !$full.EndsWith('.dump',[StringComparison]::OrdinalIgnoreCase)) {
        throw 'Solo se aceptan los archivos contabilidad-public-*.dump creados para Fase 0.'
    }
    $manifestPath = "$full.manifest.json"
    if (!(Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
        throw 'Falta manifiesto .manifest.json; no se puede verificar integridad.'
    }
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    if ($manifest.project_ref -ne 'bnjwxphwtkhblvhsnylj' -or
        $manifest.schema -ne 'public' -or
        $manifest.archive_file -ne [System.IO.Path]::GetFileName($full)) {
        throw 'El manifiesto no corresponde a la base y archivo de desarrollo aprobados.'
    }
    $realHash = (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash
    if ($realHash -ne ([string]$manifest.sha256)) { throw 'SHA256 del archivo NO coincide con el manifiesto.' }
    Write-Host '[PASS] SHA256 del dump confirmado.'

    # Limita el alcance: NO hay argumento de host / puerto remoto.
    # Docker --network none no publica puertos ni establece conexiones.
    $dockerOs = (& docker info --format '{{.OSType}}' | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $dockerOs -ne 'linux') {
        throw 'Docker no responde o no usa motor Linux. No se usara ningun servidor remoto.'
    }

    # Password temporal unico para postgres en el contenedor SIN red.
    $random = New-Object byte[] 36
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($random) } finally { $rng.Dispose() }
    $temporaryPassword = [Convert]::ToBase64String($random)
    $random = $null

    Write-Host '[AISLAMIENTO] Creando PostgreSQL 17 efimero, SIN red ni puertos.'
    $cid = & docker run --detach --rm --network none --name $dockerName --tmpfs /var/lib/postgresql/data:rw,size=512m -e "POSTGRES_PASSWORD=$temporaryPassword" postgres:17-alpine
    $temporaryPassword = $null
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace(($cid | Out-String))) {
        throw 'No se pudo crear contenedor aislado. Compruebe Docker e imagen postgres:17-alpine.'
    }
    $started = $true

    $ready = $false
    for ($i = 0; $i -lt 90; $i++) {
        & docker exec $dockerName pg_isready -U postgres -d postgres 1>$null 2>$null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    if (!$ready) { throw 'PostgreSQL 17 aislado no inicio en 90 segundos.' }

    # Las politicas RLS exportadas mencionan app_backend. Solo en Docker.
    & docker exec $dockerName psql -U postgres -d postgres -v ON_ERROR_STOP=1 -c 'CREATE ROLE app_backend NOLOGIN; CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN;' 1>$null
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron preparar los roles de prueba.' }

    & docker cp $full ($dockerName + ':/tmp/contabilidad-public.dump') | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo copiar el dump al contenedor aislado.' }

    # PostgreSQL crea 'public' por defecto, y pg_dump -n public incluye
    # CREATE SCHEMA public. Suprimir SOLO el esquema vacio del contenedor
    # temporal antes de restaurar. RESTRICT (sin CASCADE) aborta si el
    # esquema contiene cualquier objeto: nunca tocar datos preexistentes.
    # El destino es SIEMPRE este contenedor --network none/--tmpfs; no hay
    # parametros para host o puerto de Supabase.
    Write-Host '[PREPARACION] Eliminando public vacio SOLO en Docker efimero (RESTRICT, sin CASCADE).'
    & docker exec $dockerName psql -U postgres -d postgres -X -v ON_ERROR_STOP=1 -c 'DROP SCHEMA public RESTRICT;' 1>$null
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo preparar el esquema public vacio en Docker. No utilizar CASCADE ni tocar Supabase.'
    }
    Write-Host '[OK] PostgreSQL podra recrear public desde el dump original.'

    Write-Host '[RESTAURACION] pg_restore en postgres:17-alpine, dentro del Docker aislado.'
    & docker exec $dockerName pg_restore -U postgres -d postgres --exit-on-error --no-owner --no-acl /tmp/contabilidad-public.dump
    if ($LASTEXITCODE -ne 0) { throw 'pg_restore fallo: incompatibilidad u objeto faltante. NO aprobado.' }

    $sql = "SELECT json_build_object('companies',(SELECT count(*) FROM public.companies),'users',(SELECT count(*) FROM public.users),'memberships',(SELECT count(*) FROM public.company_user),'accounts',(SELECT count(*) FROM public.accounting_accounts),'entries',(SELECT count(*) FROM public.journal_entries),'lines',(SELECT count(*) FROM public.journal_entry_lines),'migrations',(SELECT count(*) FROM public.migrations),'rls_tables',(SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relrowsecurity AND c.relforcerowsecurity),'functions',(SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public'))::text"
    $json = & docker exec $dockerName psql -U postgres -d postgres -X -A -t -v ON_ERROR_STOP=1 -c $sql
    if ($LASTEXITCODE -ne 0) { throw 'Consulta de conteos restaurados fallo.' }
    $restored = (($json | Out-String).Trim() | ConvertFrom-Json)

    # Conteos del manifiesto corresponden al momento POSTERIOR al dump.
    # Actividad concurrente puede crear diferencias que requieran analisis.
    $keys = @('companies','users','memberships','accounts','entries','lines','migrations')
    $differences = @()
    foreach ($key in $keys) {
        $expected = [int64]$manifest.source_counts_after_dump.$key
        $actual = [int64]$restored.$key
        if ($actual -ne $expected) { $differences += "$key esperado=$expected/restaurado=$actual" }
    }
    if ($differences.Count -gt 0) {
        throw ('Los conteos no coinciden: ' + ($differences -join ', ') + '. Revisar concurrencia del backup.')
    }
    if ([int]$restored.rls_tables -lt 14 -or [int]$restored.functions -lt 5) {
        throw 'La restauracion carece de funciones o tablas con RLS esperadas.'
    }
    Write-Host ('[PASS] Integridad y conteos de 7 entidades: ' + ($keys -join ', '))
    Write-Host ("[PASS] RLS forzado en $($restored.rls_tables) tablas y $($restored.functions) funciones public.")
    Write-Host '[PASS] Esquema public restaurado en PostgreSQL 17 independiente y aislado.'
    Write-Host '[LIMITACION] Roles, GRANTs y credenciales de produccion se deben crear por migraciones.'
    Write-Host '[LIMITACION] No incluye auth.users, Storage ni esquemas de plataforma Supabase.'
    $ok = $true
}
catch {
    Write-Error -Message ('[FAIL] Ensayo incompleto: ' + $_.Exception.Message) -ErrorAction Continue
}
finally {
    if ($started) {
        & docker rm --force $dockerName 1>$null 2>$null
        if ($LASTEXITCODE -eq 0) { Write-Host '[LIMPIEZA] Docker efimero y datos temporales eliminados.' }
        else { Write-Warning ('No se pudo confirmar eliminacion de ' + $dockerName) }
    }
}
if (!$ok) { exit 1 }
