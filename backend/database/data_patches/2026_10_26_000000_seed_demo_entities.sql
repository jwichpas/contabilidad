-- Development-only tenant data for the three existing DEMO companies.
-- Apply as one statement to Neon contabilidad-dev / neondb, never production.
-- Public RUC sources (company-owned pages), checked 2026-10-26:
-- BCP: https://www.viabcp.com/ (20100047218)
-- Interbank: https://interbank.pe/tratamiento-opcional-de-los-datos-personales (20100053455)
-- Alicorp: https://www.alicorp.com.pe/pe/es/promociones/celebra-al-peru-con-alicorp-2026-julio-2026 (20100055237)
-- Plaza Vea: https://www.plazavea.com.pe/campanas-y-suscripciones (20100070970)
-- Names prefixed [DEMO] and roles are testing fixtures, NOT actual commercial relationships.
-- Each seeded entity receives an audit event with its document number masked.
-- DNI 00000000-00000002 are synthetic placeholders, NOT attributed to real people;
-- never use these records for fiscal documents, verification or production.
DO $demo_entities$
DECLARE
    v_inserted integer;
BEGIN
    IF current_database() <> 'neondb'
        OR current_user <> 'neondb_owner'
        OR (SELECT count(*) FROM public.companies WHERE (id,tax_id,business_name) IN (
            (3000000000101,'20999990011','DEMO A - Comercial Andina S.A.C.'),
            (3000000000102,'20999990012','DEMO B - Servicios del Sur S.A.C.'),
            (3000000000103,'20999990013','DEMO C - Distribuidora Pacifico S.A.C.')
        )) <> 3
        OR (SELECT count(*) FROM public.sunat_table_02_identity_document_types WHERE code IN ('1','6')) <> 2
    THEN
        RAISE EXCEPTION 'DEMO entity baseline mismatch; no inserts made';
    END IF;

    PERFORM pg_advisory_xact_lock(3001, 2026);

    INSERT INTO public.entities (
        company_id, identity_document_type_code, tax_id, legal_name, commercial_name,
        country_code, is_client, is_supplier, is_employee,
        is_financial_institution, is_shareholder, is_active, created_at, updated_at
    )
    SELECT c.id, d.doc, d.tax_id, d.legal_name, d.commercial_name,
           'PER', d.is_client, d.is_supplier, d.is_employee,
           d.is_financial_institution, d.is_shareholder, true, now(), now()
    FROM public.companies c
    CROSS JOIN (VALUES
        ('6','20100047218','[DEMO] BANCO DE CREDITO DEL PERU S.A.','BCP · DEMO',false,false,false,true,false),
        ('6','20100053455','[DEMO] BANCO INTERNACIONAL DEL PERU S.A.A.','Interbank · DEMO',false,false,false,true,false),
        ('6','20100055237','[DEMO] ALICORP S.A.A.','Alicorp · DEMO',false,true,false,false,false),
        ('6','20100070970','[DEMO] SUPERMERCADOS PERUANOS S.A.','Plaza Vea · DEMO',true,false,false,false,false),
        ('1','00000000','PERSONA FICTICIA DEMO 01','Cliente · DNI sintético',true,false,false,false,false),
        ('1','00000001','PERSONA FICTICIA DEMO 02','Trabajador · DNI sintético',false,false,true,false,false),
        ('1','00000002','PERSONA FICTICIA DEMO 03','Accionista · DNI sintético',false,false,false,false,true)
    ) AS d(doc,tax_id,legal_name,commercial_name,is_client,is_supplier,is_employee,
           is_financial_institution,is_shareholder)
    WHERE c.id IN (3000000000101,3000000000102,3000000000103)
    ON CONFLICT (company_id,identity_document_type_code,tax_id) DO NOTHING;
    GET DIAGNOSTICS v_inserted = ROW_COUNT;

    -- A complete first run or an unchanged re-run are the only accepted states.
    IF v_inserted NOT IN (0,21) OR EXISTS (
        SELECT 1 FROM public.companies c
        CROSS JOIN (VALUES
            ('6','20100047218','[DEMO] BANCO DE CREDITO DEL PERU S.A.',false,false,false,true,false),
            ('6','20100053455','[DEMO] BANCO INTERNACIONAL DEL PERU S.A.A.',false,false,false,true,false),
            ('6','20100055237','[DEMO] ALICORP S.A.A.',false,true,false,false,false),
            ('6','20100070970','[DEMO] SUPERMERCADOS PERUANOS S.A.',true,false,false,false,false),
            ('1','00000000','PERSONA FICTICIA DEMO 01',true,false,false,false,false),
            ('1','00000001','PERSONA FICTICIA DEMO 02',false,false,true,false,false),
            ('1','00000002','PERSONA FICTICIA DEMO 03',false,false,false,false,true)
        ) AS d(doc,tax_id,legal_name,is_client,is_supplier,is_employee,is_financial_institution,is_shareholder)
        LEFT JOIN public.entities e ON e.company_id=c.id
            AND e.identity_document_type_code=d.doc AND e.tax_id=d.tax_id
        WHERE c.id IN (3000000000101,3000000000102,3000000000103)
          AND (e.id IS NULL OR e.legal_name<>d.legal_name OR NOT e.is_active
            OR e.is_client<>d.is_client OR e.is_supplier<>d.is_supplier
            OR e.is_employee<>d.is_employee
            OR e.is_financial_institution<>d.is_financial_institution
            OR e.is_shareholder<>d.is_shareholder)
    ) THEN
        RAISE EXCEPTION 'DEMO entities are incomplete or modified; refusing partial seed';
    END IF;

    INSERT INTO public.audit_logs (
        company_id, user_id, event_type, auditable_type, auditable_id,
        old_values, new_values, severity, created_at
    )
    SELECT e.company_id, NULL, 'DEMO_ENTITY_SEEDED', 'App\Models\Entity', e.id::text,
           NULL, jsonb_build_object(
               'source_patch', '2026_10_26_000000_seed_demo_entities.sql',
               'identity_document_type_code', e.identity_document_type_code,
               'tax_id', repeat('*', length(e.tax_id)-4) || right(e.tax_id,4),
               'legal_name', e.legal_name
           ), 'INFO', now()
    FROM public.entities e
    WHERE e.company_id IN (3000000000101,3000000000102,3000000000103)
      AND (e.identity_document_type_code,e.tax_id) IN (
          ('6','20100047218'),('6','20100053455'),('6','20100055237'),('6','20100070970'),
          ('1','00000000'),('1','00000001'),('1','00000002')
      )
      AND NOT EXISTS (
          SELECT 1 FROM public.audit_logs a
          WHERE a.company_id=e.company_id AND a.auditable_id=e.id::text
            AND a.event_type='DEMO_ENTITY_SEEDED'
      );

    IF (SELECT count(*) FROM public.audit_logs a
        JOIN public.entities e ON e.company_id=a.company_id AND e.id::text=a.auditable_id
        WHERE a.event_type='DEMO_ENTITY_SEEDED'
          AND a.company_id IN (3000000000101,3000000000102,3000000000103)
          AND (e.identity_document_type_code,e.tax_id) IN (
              ('6','20100047218'),('6','20100053455'),('6','20100055237'),('6','20100070970'),
              ('1','00000000'),('1','00000001'),('1','00000002')
          )) <> 21 THEN
        RAISE EXCEPTION 'DEMO entity audit incomplete; rolling back';
    END IF;
END
$demo_entities$;
