#requires -Version 5.1
<#
  Applies the identical SELECT-only membership policy from
  2026_10_20_010000_scope_company_listing_to_authenticated_user.php
  only to the verified local, persistent PG18 Docker laboratory.

  .\scripts\habilitar-listado-empresas-pg18-local.ps1 -Accion Verificar
  .\scripts\habilitar-listado-empresas-pg18-local.ps1 -Accion Aplicar
#>
param([ValidateSet('Verificar','Aplicar')][string]$Accion='Verificar')
$ErrorActionPreference='Stop'
$name='contabilidad-pg18-local'
$volume='contabilidad-pg18-local-data'
$success=$false

function SqlResult([string]$sql){
    $arguments=@('exec',$name,'psql','-U','postgres','-d','contabilidad_local',
        '-X','-A','-t','-F','|','-v','ON_ERROR_STOP=1','-c',$sql)
    $value=(& docker @arguments 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0){
        throw 'Falló una consulta sobre el contenedor local.'
    }
    return $value
}
function Check-Docker {
    $os=(& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $os -ne 'linux'){throw 'Docker Linux no disponible.'}
    $label=(& docker inspect --format '{{index .Config.Labels "contabilidad.local"}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $label -ne 'pg18-dev'){throw 'Etiqueta del contenedor inesperada.'}
    $mapping=(& docker port $name '5432/tcp' 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $mapping -ne '127.0.0.1:55433'){throw 'Puerto local inesperado.'}
    $mount=(& docker inspect --format '{{range .Mounts}}{{.Name}}|{{.Destination}}{{end}}' $name 2>&1 | Out-String).Trim()
    if($LASTEXITCODE -ne 0 -or $mount -ne ($volume+'|/var/lib/postgresql')){
        throw 'Volumen PostgreSQL persistente inesperado.'
    }
    $identity=SqlResult "SELECT current_database(), current_user, current_setting('server_version_num')::integer"
    if($identity -notmatch '^contabilidad_local[|]postgres[|]18[0-9]{4}$'){
        throw 'Identidad/version de PostgreSQL 18 no verificada.'
    }
}
function Check-Preconditions {
    # No business data beyond the one synthetic lab user/company may exist.
    # Both the RLS flag and original tenant policy must remain in place.
    $sql=@'
SELECT
 (SELECT count(*) FROM public.migrations)::integer,
 (SELECT count(*) FROM public.users)::integer,
 (SELECT count(*) FROM public.companies)::integer,
 (SELECT count(*) FROM public.company_user)::integer,
 (SELECT count(*) FROM public.company_user cu
  JOIN public.users u ON u.id=cu.user_id
  JOIN public.companies c ON c.id=cu.company_id
  WHERE u.email='demo.pg18@example.test'
    AND c.tax_id='20999990099'
    AND u.current_company_id=c.id
    AND cu.role='ADMIN' AND cu.is_active AND c.is_active)::integer,
 (SELECT CASE WHEN rolcanlogin AND NOT rolsuper AND NOT rolbypassrls
              THEN 1 ELSE 0 END
  FROM pg_catalog.pg_roles WHERE rolname='app_backend')::integer,
 (SELECT CASE WHEN c.relrowsecurity AND c.relforcerowsecurity THEN 1 ELSE 0 END
  FROM pg_catalog.pg_class c
  JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='public' AND c.relname='company_user')::integer,
 (SELECT count(*) FROM pg_catalog.pg_policies
  WHERE schemaname='public' AND tablename='company_user'
    AND policyname='company_user_tenant_isolation_policy'
    AND cmd='ALL' AND 'app_backend'=ANY(roles))::integer,
 (SELECT count(*) FROM pg_catalog.pg_policies
  WHERE schemaname='public' AND tablename='company_user')::integer,
 (SELECT count(*) FROM pg_catalog.pg_policies
  WHERE schemaname='public' AND tablename='company_user'
    AND policyname='company_user_authenticated_member_listing_policy')::integer
'@
    $row=SqlResult $sql
    if($row -ne '25|1|1|1|1|1|1|1|1|0' -and
       $row -ne '25|1|1|1|1|1|1|1|2|1'){
        throw 'Estado de esquema o membresía inesperado; no modificar.'
    }
    return $row
}
function Check-Policy {
    $policy=SqlResult @'
SELECT count(*) FROM pg_catalog.pg_policies
WHERE schemaname='public'
  AND tablename='company_user'
  AND policyname='company_user_authenticated_member_listing_policy'
  AND permissive='PERMISSIVE'
  AND cmd='SELECT'
  AND roles=ARRAY['app_backend']::name[]
  AND qual LIKE '%app.current_user_id%'
  AND qual LIKE '%user_id%'
'@
    if($policy -ne '1'){throw 'No se confirmó la política SELECT restringida.'}
    $sql=@'
BEGIN;
SET LOCAL ROLE app_backend;
SELECT set_config('app.current_user_id',(
  SELECT id::text FROM public.users WHERE email='demo.pg18@example.test'
),true);
DO $verify$
DECLARE v_count integer;
BEGIN
 SELECT count(*) INTO v_count FROM public.company_user;
 IF v_count <> 1 THEN
   RAISE EXCEPTION 'Local RLS owner membership failed';
 END IF;
 PERFORM set_config('app.current_user_id','987654321',true);
 SELECT count(*) INTO v_count FROM public.company_user;
 IF v_count <> 0 THEN
   RAISE EXCEPTION 'Local RLS other membership leaked';
 END IF;
END
$verify$;
ROLLBACK;
'@
    $out=SqlResult $sql
    if($out -notmatch '(?m)^DO$'){throw 'Prueba transaccional RLS de usuario propio y ajeno no aprobada.'}
    Write-Host '[PASS] RLS: usuario propio ve una membresía, usuario ajeno no ve ninguna.'
}
try{
    Check-Docker
    $state=Check-Preconditions
    if($Accion -eq 'Verificar'){
        if($state -eq '25|1|1|1|1|1|1|1|1|0'){
            Write-Host '[VERIFICAR] Falta política SELECT de membresías en el PostgreSQL 18 local.'
            Write-Host '[VERIFICAR] No se realizaron cambios. Siguiente: -Accion Aplicar.'
        }else{
            Check-Policy
            Write-Host '[PASS] La política de listado de empresas ya está instalada.'
        }
        $success=$true
    }elseif($state -eq '25|1|1|1|1|1|1|1|2|1'){
        Check-Policy
        Write-Host '[PASS] La política ya existe; no se realizaron cambios.'
        $success=$true
    }else{
        # Exact copy of the already-reviewed 2026-10-20 policy. No DML changes.
        $sql=@'
CREATE POLICY company_user_authenticated_member_listing_policy
ON public.company_user
AS PERMISSIVE
FOR SELECT
TO app_backend
USING (
  user_id = (
    SELECT nullif(current_setting('app.current_user_id', true),'')::bigint
  )
);
'@
        $result=SqlResult $sql
        if($result -ne 'CREATE POLICY'){throw 'Resultado inesperado de CREATE POLICY.'}
        $after=Check-Preconditions
        if($after -ne '25|1|1|1|1|1|1|1|2|1'){
            throw 'La política de listado no quedó en el estado esperado.'
        }
        Check-Policy
        Write-Host '[PASS] Política SELECT de membresías instalada exclusivamente en PostgreSQL 18 local.'
        $success=$true
    }
}catch{
    Write-Host ('[FAIL] '+$_.Exception.Message)
}
if(-not $success){exit 1}
