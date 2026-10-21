-- F1.3 - Escrituras negativas con RLS REAL del ERP migrado en PostgreSQL 17.
-- SOLO base desechable contabilidad_ci, identidad postgres (admin CI).
-- Fixturas A/B y todos los INSERT/UPDATE/DELETE viven en una sola transaccion;
-- siempre se ejecuta ROLLBACK al concluir. Nunca contra Supabase Dev.
\set ON_ERROR_STOP on

BEGIN;

DO $guard$
BEGIN
    IF current_database() <> 'contabilidad_ci' OR current_user <> 'postgres'
       OR inet_server_addr() IS NULL THEN
        RAISE EXCEPTION 'BLOQUEO F1: solo PostgreSQL CI local contabilidad_ci con rol postgres';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_catalog.pg_roles
        WHERE rolname = 'app_backend' AND NOT rolsuper AND NOT rolbypassrls
    ) THEN
        RAISE EXCEPTION 'Rol real app_backend ausente o privilegiado';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname='company_areas'
          AND c.relrowsecurity AND c.relforcerowsecurity
    ) THEN
        RAISE EXCEPTION 'Falta FORCE RLS en public.company_areas';
    END IF;
END
$guard$;

INSERT INTO public.companies(tax_id,business_name,legal_address,is_active,created_at,updated_at)
VALUES ('20999999993','F1 CI Empresa A','CI local temporal',TRUE,now(),now())
RETURNING id AS f1_company_a \gset

INSERT INTO public.companies(tax_id,business_name,legal_address,is_active,created_at,updated_at)
VALUES ('20999999994','F1 CI Empresa B','CI local temporal',TRUE,now(),now())
RETURNING id AS f1_company_b \gset

-- Las operaciones de negocio se efectuan con el rol restringido real.
SET LOCAL ROLE app_backend;
SELECT set_config('app.current_company_id', :'f1_company_a', true);

INSERT INTO public.company_areas(company_id,code,name,created_at,updated_at)
VALUES (:'f1_company_a'::bigint,'F1_A','Area A',now(),now());

DO $check$
BEGIN
    IF (SELECT count(*) FROM public.company_areas WHERE code='F1_A') <> 1 THEN
        RAISE EXCEPTION 'El rol app_backend no pudo insertar en su propia empresa A';
    END IF;
END
$check$;

SELECT set_config('app.current_company_id', :'f1_company_b', true);

DO $check$
BEGIN
    IF (SELECT count(*) FROM public.company_areas WHERE code='F1_A') <> 0 THEN
        RAISE EXCEPTION 'La empresa B puede leer el area de A';
    END IF;
END
$check$;

INSERT INTO public.company_areas(company_id,code,name,created_at,updated_at)
VALUES (:'f1_company_b'::bigint,'F1_B','Area B',now(),now());

DO $check$
BEGIN
    IF (SELECT count(*) FROM public.company_areas WHERE code='F1_B') <> 1 THEN
        RAISE EXCEPTION 'El rol app_backend no pudo insertar en su propia empresa B';
    END IF;
END
$check$;

SELECT set_config('app.current_company_id', :'f1_company_a', true);

DO $negative$
DECLARE
    company_b bigint;
    affected integer;
    blocked boolean;
BEGIN
    SELECT id INTO STRICT company_b FROM public.companies WHERE tax_id='20999999994';

    -- RLS filtra SELECT/UPDATE/DELETE: no debe afectar filas del tenant B.
    IF (SELECT count(*) FROM public.company_areas WHERE code='F1_B') <> 0 THEN
        RAISE EXCEPTION 'Lectura interempresa expuesta';
    END IF;
    UPDATE public.company_areas SET name='INTEREMPRESA' WHERE code='F1_B';
    GET DIAGNOSTICS affected = ROW_COUNT;
    IF affected <> 0 THEN
        RAISE EXCEPTION 'UPDATE interempresa afecto filas de B';
    END IF;
    DELETE FROM public.company_areas WHERE code='F1_B';
    GET DIAGNOSTICS affected = ROW_COUNT;
    IF affected <> 0 THEN
        RAISE EXCEPTION 'DELETE interempresa afecto filas de B';
    END IF;

    -- WITH CHECK debe rechazar INSERT en otro tenant, no solo ocultarlo.
    blocked := false;
    BEGIN
        INSERT INTO public.company_areas(company_id,code,name,created_at,updated_at)
        VALUES (company_b,'F1_CROSS_INSERT','Prohibido',now(),now());
    EXCEPTION WHEN insufficient_privilege THEN
        blocked := true;
    END;
    IF NOT blocked THEN
        RAISE EXCEPTION 'INSERT interempresa permitido';
    END IF;

    -- Incluso una fila propia NO puede ser transferida a otra company_id.
    blocked := false;
    BEGIN
        UPDATE public.company_areas SET company_id=company_b WHERE code='F1_A';
    EXCEPTION WHEN insufficient_privilege THEN
        blocked := true;
    END;
    IF NOT blocked THEN
        RAISE EXCEPTION 'UPDATE de company_id interempresa permitido';
    END IF;

    -- UPDATE legitimo, ejecutado solo en su company_id.
    UPDATE public.company_areas SET name='Area A autorizada' WHERE code='F1_A';
    GET DIAGNOSTICS affected = ROW_COUNT;
    IF affected <> 1 THEN
        RAISE EXCEPTION 'UPDATE legitimo no afecto exactamente una fila';
    END IF;
END
$negative$;

-- Sin tenant, ninguna fila visible y ningun INSERT posible.
SELECT set_config('app.current_company_id', '', true);
DO $fail_closed$
DECLARE blocked boolean := false;
BEGIN
    IF (SELECT count(*) FROM public.company_areas WHERE code IN ('F1_A','F1_B')) <> 0 THEN
        RAISE EXCEPTION 'Sin contexto se exponen filas';
    END IF;
    BEGIN
        INSERT INTO public.company_areas(company_id,code,name,created_at,updated_at)
        VALUES ((SELECT id FROM public.companies WHERE tax_id='20999999993'),
                'F1_NO_TENANT','Prohibido',now(),now());
    EXCEPTION WHEN insufficient_privilege THEN
        blocked := true;
    END;
    IF NOT blocked THEN
        RAISE EXCEPTION 'INSERT sin contexto permitido';
    END IF;
END
$fail_closed$;

SELECT set_config('app.current_company_id', :'f1_company_b', true);
DO $final$
DECLARE affected integer;
BEGIN
    IF (SELECT count(*) FROM public.company_areas WHERE code='F1_A') <> 0 THEN
        RAISE EXCEPTION 'La empresa B ve area de A al restaurar el contexto';
    END IF;
    IF (SELECT count(*) FROM public.company_areas WHERE code='F1_B') <> 1 THEN
        RAISE EXCEPTION 'La empresa B perdio su fila por UPDATE/DELETE ajeno';
    END IF;
    DELETE FROM public.company_areas WHERE code='F1_B';
    GET DIAGNOSTICS affected = ROW_COUNT;
    IF affected <> 1 THEN
        RAISE EXCEPTION 'DELETE legitimo de B no afecto exactamente una fila';
    END IF;
END
$final$;

ROLLBACK;

-- Comprobar que NINGUNA empresa ficticia persistio tras ROLLBACK.
DO $cleanup$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.companies WHERE tax_id IN ('20999999993','20999999994')
    ) THEN
        RAISE EXCEPTION 'Fallo ROLLBACK: empresas ficticias persistieron';
    END IF;
END
$cleanup$;

SELECT 'PASS: RLS REAL bloquea INSERT/UPDATE/DELETE cruzados y sin tenant; acepta operaciones propias; rollback limpio' AS result;
