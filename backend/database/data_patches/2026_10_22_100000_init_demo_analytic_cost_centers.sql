-- F4: Development-only additive analytical chart for the THREE EXISTING DEMO companies.
-- Targets Neon contabilidad-dev (PG18), database neondb. 2026-10-22.
-- Verified prerequisite F2 + F3. Execute ONCE as one statement / transaction.
-- No schema changes, deletes, truncates, new companies or alterations to global PCGE.
-- 94/95 is a private management-analytics extension (not a PCGE master catalog change).
DO $f4_demo_analytics$
DECLARE
    v_level integer;
    v_rows integer;
BEGIN
    -- Never reuse in production or in a database with accounting history.
    IF current_database()<>'neondb' OR current_user<>'neondb_owner'
        OR (current_setting('server_version_num')::int/10000)<>18
        OR (SELECT count(*) FROM public.migrations)<>18
        OR (SELECT count(*) FROM public.reference_data_versions WHERE dataset_key='nexus-global-reference-2026-10-21-v1')<>1
        OR (SELECT count(*) FROM public.accounting_account_templates)<>3401
        OR (SELECT count(*) FROM public.companies)<>3
        OR (SELECT count(*) FROM public.companies WHERE (id,tax_id,business_name) IN (
            (3000000000101,'20999990011','DEMO A - Comercial Andina S.A.C.'),
            (3000000000102,'20999990012','DEMO B - Servicios del Sur S.A.C.'),
            (3000000000103,'20999990013','DEMO C - Distribuidora Pacifico S.A.C.')
        ))<>3
        OR (SELECT count(*) FROM public.users)<>3
        OR (SELECT count(*) FROM public.company_user)<>8
        OR (SELECT count(*) FROM public.accounting_accounts)<>8865
        OR (SELECT count(*) FROM public.accounting_accounts WHERE is_operational AND length(code)=6)<>3636
        OR (SELECT count(*) FROM public.company_settings)<>3
        OR (SELECT count(*) FROM public.company_currencies)<>6
        OR (SELECT count(*) FROM public.cost_centers)<>0
        OR (SELECT count(*) FROM public.journal_entries)<>0
        OR (SELECT count(*) FROM public.journal_entry_lines)<>0
        OR (SELECT count(*) FROM public.accounting_accounts WHERE code='791000' AND is_active AND is_operational)<>3
        OR EXISTS(SELECT 1 FROM public.accounting_accounts WHERE code IN ('9','94','941','9411','94110','941100','95','951','9511','95110','951100'))
    THEN
        RAISE EXCEPTION 'F4 DEV-only baseline mismatch; refuse all writes.';
    END IF;

    PERFORM pg_advisory_xact_lock(3001,2094);

    FOR v_level IN 1..6 LOOP
        INSERT INTO public.accounting_accounts (
            company_id,parent_id,code,name,level,path,
            account_type,balance_type,currency_mode,requires_currency,
            requires_auxiliary,entity_requirement_type,requires_cost_center,
            has_automatic_destination,requires_cash_flow_area,cash_flow_category,
            financial_statement_rubric,is_operational,is_active,created_at,updated_at
        )
        SELECT company.id,p.id,defs.code,defs.name,defs.level,
               CASE WHEN defs.parent_code IS NULL THEN defs.code
                    ELSE p.path||'.'||defs.code END,
               'COST','DEBIT','PEN',false,false,'NONE',false,false,false,'NONE',
               defs.rubric,defs.is_operational,true,now(),now()
        FROM public.companies company
        CROSS JOIN (
            VALUES
            ('9',NULL::text,'Contabilidad analítica por función',1,false,'NONE'),
            ('94','9','Gastos de administración',2,false,'NONE'),
            ('941','94','Administración general',3,false,'NONE'),
            ('9411','941','Gastos administrativos distribuidos',4,false,'NONE'),
            ('94110','9411','Gastos administrativos - agrupación',5,false,'NONE'),
            ('941100','94110','Gastos administrativos - movimiento',6,true,'ERF_GASTOS_ADMIN_94'),
            ('95','9','Gastos de venta',2,false,'NONE'),
            ('951','95','Ventas y distribución',3,false,'NONE'),
            ('9511','951','Gastos comerciales distribuidos',4,false,'NONE'),
            ('95110','9511','Gastos comerciales - agrupación',5,false,'NONE'),
            ('951100','95110','Gastos de ventas - movimiento',6,true,'ERF_GASTOS_VENTAS_95')
        ) AS defs(code,parent_code,name,level,is_operational,rubric)
        LEFT JOIN public.accounting_accounts p
               ON p.company_id=company.id AND p.code=defs.parent_code
        WHERE company.id IN (3000000000101,3000000000102,3000000000103)
          AND defs.level=v_level
          AND (defs.parent_code IS NULL OR p.id IS NOT NULL)
        ORDER BY company.id,defs.code;
        GET DIAGNOSTICS v_rows = ROW_COUNT;

        IF v_rows<>(CASE v_level WHEN 1 THEN 3 WHEN 2 THEN 6 WHEN 3 THEN 6
                                   WHEN 4 THEN 6 WHEN 5 THEN 6 WHEN 6 THEN 6 ELSE 0 END)
        THEN
            RAISE EXCEPTION 'F4 analytic level % inserted unexpected % rows',v_level,v_rows;
        END IF;
    END LOOP;

    INSERT INTO public.cost_centers(
        company_id,code,name,destination_debit_account_id,
        destination_credit_account_id,is_active,created_at,updated_at
    )
    SELECT company.id,defs.code,defs.name,debit.id,credit.id,true,now(),now()
    FROM public.companies company
    CROSS JOIN (VALUES
        ('CC-ADM','Administración general','941100'),
        ('CC-VTA','Comercial y ventas','951100')
    ) defs(code,name,debit_code)
    JOIN public.accounting_accounts debit
         ON debit.company_id=company.id AND debit.code=defs.debit_code
            AND debit.is_active AND debit.is_operational
    JOIN public.accounting_accounts credit
         ON credit.company_id=company.id AND credit.code='791000'
            AND credit.is_active AND credit.is_operational
    WHERE company.id IN (3000000000101,3000000000102,3000000000103);
    GET DIAGNOSTICS v_rows = ROW_COUNT;

    IF v_rows<>6
        OR (SELECT count(*) FROM public.accounting_accounts)<>8898
        OR (SELECT count(*) FROM public.accounting_accounts WHERE is_operational AND length(code)=6)<>3642
        OR (SELECT count(*) FROM public.cost_centers)<>6
        OR EXISTS (
            SELECT 1 FROM public.accounting_accounts a
            LEFT JOIN public.accounting_accounts p
                  ON p.company_id=a.company_id AND p.id=a.parent_id
            WHERE a.code IN ('9','94','941','9411','94110','941100','95','951','9511','95110','951100')
              AND (a.account_type<>'COST'
                   OR (a.level=1 AND a.parent_id IS NOT NULL)
                   OR (a.level>1 AND (p.id IS NULL OR p.level<>a.level-1))
                   OR (a.is_operational AND length(a.code)<>6))
        )
        OR EXISTS (
            SELECT 1 FROM public.cost_centers cc
            JOIN public.accounting_accounts debit ON debit.id=cc.destination_debit_account_id
            JOIN public.accounting_accounts credit ON credit.id=cc.destination_credit_account_id
            WHERE debit.company_id<>cc.company_id
               OR credit.company_id<>cc.company_id
               OR debit.code NOT IN ('941100','951100')
               OR credit.code<>'791000'
        )
    THEN
        RAISE EXCEPTION 'F4 postcondition mismatch; rollback all inserts.';
    END IF;
END
$f4_demo_analytics$;

-- Verified by follow-up read-only SQL:
-- SELECT c.id,c.business_name,
--  (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id) accounts,
--  (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id AND a.is_operational) postings,
--  (SELECT count(*) FROM public.cost_centers cc WHERE cc.company_id=c.id) centers
-- FROM public.companies c ORDER BY c.id;
