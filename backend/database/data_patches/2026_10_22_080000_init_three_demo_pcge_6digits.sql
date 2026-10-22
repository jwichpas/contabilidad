-- F3 DEV-ONLY: reproducible onboarding of the three existing DEMO tenants.
-- PostgreSQL 18 / Neon branch contabilidad-dev ONLY.
-- IMPORTANT: run as ONE transaction. Fail-closed if no longer at the expected baseline.
-- No schema changes, DROP/TRUNCATE/DELETE, password changes, new users or new companies.
-- Source: F2 PCGE_2019 (immutable). Each tenant gets a private chart.
-- Only six-digit accounts may accept postings. A six-digit child is synthesized
-- from each leaf of 3-5 digits, without altering the canonical PCGE matrix.
DO $nexus_dev_company_init$
DECLARE
    v_level integer;
    v_inserted integer;
    v_expected integer;
    v_expected_operational integer;
BEGIN
    IF current_database() <> 'neondb'
        OR current_user <> 'neondb_owner'
        OR current_setting('server_version_num')::integer / 10000 <> 18
        OR (SELECT count(*) FROM public.migrations) <> 18
        OR (SELECT count(*) FROM public.reference_data_versions
            WHERE dataset_key = 'nexus-global-reference-2026-10-21-v1'
              AND (verified_counts->>'accounting_account_templates')::int = 3401) <> 1
        OR (SELECT count(*) FROM public.companies) <> 3
        OR (SELECT count(*) FROM public.companies
            WHERE (id, tax_id, business_name) IN (
                (3000000000101, '20999990011', 'DEMO A - Comercial Andina S.A.C.'),
                (3000000000102, '20999990012', 'DEMO B - Servicios del Sur S.A.C.'),
                (3000000000103, '20999990013', 'DEMO C - Distribuidora Pacifico S.A.C.')
            )) <> 3
        OR (SELECT count(*) FROM public.users) <> 3
        OR (SELECT count(*) FROM public.company_user) <> 8
        OR (SELECT count(*) FROM public.company_areas) <> 18
        OR (SELECT count(*) FROM public.cash_flow_areas) <> 27
        OR (SELECT count(*) FROM public.accounting_accounts) <> 0
        OR (SELECT count(*) FROM public.company_settings) <> 0
        OR (SELECT count(*) FROM public.company_currencies) <> 0
        OR (SELECT count(*) FROM public.journal_entries) <> 0
        OR (SELECT count(*) FROM public.journal_entry_lines) <> 0
        OR (SELECT count(*) FROM public.accounting_account_templates
            WHERE plan_version='PCGE_2019') <> 1764
        OR (SELECT count(*) FROM public.accounting_account_templates
            WHERE plan_version='PCGE_2019' AND is_operational AND length(code)<6) <> 1191
        OR (SELECT count(*) FROM public.currencies
            WHERE code IN ('PEN','USD') AND is_active) <> 2
        THEN
        RAISE EXCEPTION 'F3 DEMO baseline, global F2, tenant identities or empty-target invariant failed; no writes.';
    END IF;

    -- Prevent concurrent reruns from racing the baseline check.
    PERFORM pg_advisory_xact_lock(3001, 2019);
    IF (SELECT count(*) FROM public.accounting_accounts) <> 0 THEN
        RAISE EXCEPTION 'Concurrent tenant chart initialization detected.';
    END IF;

    -- Both versions have complete parent chains; use PCGE_2019 as the
    -- default version for these DEMO tenants. Do NOT promote PCGE_2026.
    IF EXISTS (
        SELECT 1 FROM public.accounting_account_templates t
        LEFT JOIN public.accounting_account_templates p
            ON p.plan_version=t.plan_version AND p.code=t.parent_code
        WHERE t.plan_version='PCGE_2019' AND t.level>1
          AND (p.code IS NULL OR p.level<>t.level-1)
    ) THEN
        RAISE EXCEPTION 'PCGE_2019 parent chain incomplete; no writes.';
    END IF;

    -- Cloning is set-based by tree level: parents exist before child inserts.
    FOR v_level IN 1..6 LOOP
        INSERT INTO public.accounting_accounts (
            company_id,parent_id,code,name,level,path,account_type,balance_type,
            currency_mode,requires_currency,requires_auxiliary,entity_requirement_type,
            requires_cost_center,has_automatic_destination,
            destination_debit_code,destination_credit_code,
            requires_cash_flow_area,cash_flow_category,financial_statement_rubric,
            is_operational,is_active,created_at,updated_at
        )
        SELECT c.id,p.id,t.code,t.name,t.level,t.path,t.account_type,t.balance_type,
               t.currency_mode,(t.currency_mode<>'PEN'),
               (t.entity_requirement_type<>'NONE'),t.entity_requirement_type,
               t.requires_cost_center,t.has_automatic_destination,
               t.destination_debit_code,t.destination_credit_code,
               t.requires_cash_flow_area,t.cash_flow_category,t.financial_statement_rubric,
               (t.is_operational AND length(t.code)=6),t.is_active,now(),now()
        FROM public.accounting_account_templates t
        CROSS JOIN public.companies c
        LEFT JOIN public.accounting_accounts p
            ON p.company_id=c.id AND p.code=t.parent_code
        WHERE t.plan_version='PCGE_2019' AND t.level=v_level
        ORDER BY c.id,t.code;
        GET DIAGNOSTICS v_inserted = ROW_COUNT;
        IF v_inserted <> 3 * (SELECT count(*) FROM public.accounting_account_templates
                              WHERE plan_version='PCGE_2019' AND level=v_level) THEN
            RAISE EXCEPTION 'PCGE level % did not clone completely',v_level;
        END IF;
    END LOOP;

    -- Add per-tenant six-digit posting accounts below terminal 3-5 digit
    -- PCGE nodes. No collisions were found by the preflight SQL.
    IF EXISTS (
        SELECT 1 FROM public.accounting_account_templates t
        JOIN public.accounting_account_templates x
            ON x.plan_version=t.plan_version AND x.code=rpad(t.code,6,'0')
        WHERE t.plan_version='PCGE_2019'
            AND t.is_operational AND length(t.code)<6
    ) THEN
        RAISE EXCEPTION 'Six-digit leaf alias collision; no writes.';
    END IF;

    INSERT INTO public.accounting_accounts (
        company_id,parent_id,code,name,level,path,account_type,balance_type,
        currency_mode,requires_currency,requires_auxiliary,entity_requirement_type,
        requires_cost_center,has_automatic_destination,
        destination_debit_code,destination_credit_code,
        requires_cash_flow_area,cash_flow_category,financial_statement_rubric,
        is_operational,is_active,created_at,updated_at
    )
    SELECT a.company_id,a.id,rpad(t.code,6,'0'),
           left(t.name||' - Movimiento',180),t.level+1,
           a.path||'.'||rpad(t.code,6,'0'),t.account_type,t.balance_type,
           t.currency_mode,(t.currency_mode<>'PEN'),
           (t.entity_requirement_type<>'NONE'),t.entity_requirement_type,
           t.requires_cost_center,t.has_automatic_destination,
           t.destination_debit_code,t.destination_credit_code,
           t.requires_cash_flow_area,t.cash_flow_category,t.financial_statement_rubric,
           true,t.is_active,now(),now()
    FROM public.accounting_account_templates t
    JOIN public.accounting_accounts a ON a.code=t.code
    JOIN public.companies c ON c.id=a.company_id
    WHERE c.id IN (3000000000101,3000000000102,3000000000103)
      AND t.plan_version='PCGE_2019' AND t.is_operational AND length(t.code)<6
    ORDER BY a.company_id,t.code;
    GET DIAGNOSTICS v_inserted = ROW_COUNT;
    IF v_inserted <> 3*1191 THEN
        RAISE EXCEPTION 'Six-digit account expansion incomplete.';
    END IF;

    v_expected := 1764+1191;
    v_expected_operational := 1212;

    -- Demo tax and monetary settings. No fabricated legal representatives
    -- and no claim that SUNAT SIRE/PLE obligations are determined.
    INSERT INTO public.company_settings (
        company_id,base_currency_id,secondary_currency_id,
        tax_regime,default_vat_rate,is_sire_obliged,is_ple_obliged,
        sales_vat_account_id,purchase_vat_account_id,
        fx_gain_account_id,fx_loss_account_id,cost_absorption_account_id,
        retained_earnings_account_id,created_at,updated_at
    )
    SELECT c.id,pen.id,usd.id,'GENERAL',18.00,false,false,
           vat.id,vat.id,fxg.id,fxl.id,absorption.id,retained.id,now(),now()
    FROM public.companies c
    CROSS JOIN (SELECT id FROM public.currencies WHERE code='PEN') pen
    CROSS JOIN (SELECT id FROM public.currencies WHERE code='USD') usd
    LEFT JOIN public.accounting_accounts vat
        ON vat.company_id=c.id AND vat.code='401110'
    LEFT JOIN public.accounting_accounts fxg
        ON fxg.company_id=c.id AND fxg.code='776000'
    LEFT JOIN public.accounting_accounts fxl
        ON fxl.company_id=c.id AND fxl.code='676000'
    LEFT JOIN public.accounting_accounts absorption
        ON absorption.company_id=c.id AND absorption.code='791000'
    LEFT JOIN public.accounting_accounts retained
        ON retained.company_id=c.id AND retained.code='591100'
    WHERE c.id IN (3000000000101,3000000000102,3000000000103);

    INSERT INTO public.company_currencies (
        company_id,currency_id,is_base,is_active,created_at,updated_at
    )
    SELECT c.id,cur.id,(cur.code='PEN'),true,now(),now()
    FROM public.companies c
    CROSS JOIN public.currencies cur
    WHERE c.id IN (3000000000101,3000000000102,3000000000103)
      AND cur.code IN ('PEN','USD');

    IF (SELECT count(*) FROM public.company_settings)<>3
       OR (SELECT count(*) FROM public.company_currencies)<>6
       OR (SELECT count(*) FROM public.accounting_accounts)<>(3*v_expected)
       OR EXISTS (
           SELECT 1 FROM public.companies c
           WHERE c.id IN (3000000000101,3000000000102,3000000000103)
           AND ((SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id)<>v_expected
             OR (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id AND a.is_operational)<>v_expected_operational
             OR EXISTS(SELECT 1 FROM public.accounting_accounts a
                       WHERE a.company_id=c.id AND a.is_operational AND length(a.code)<>6)
             OR (SELECT count(*) FROM public.company_settings s
                 WHERE s.company_id=c.id AND (
                    s.sales_vat_account_id IS NOT NULL AND s.purchase_vat_account_id IS NOT NULL
                    AND s.fx_gain_account_id IS NOT NULL AND s.fx_loss_account_id IS NOT NULL
                    AND s.cost_absorption_account_id IS NOT NULL AND s.retained_earnings_account_id IS NOT NULL
                 ))<>1)
       )
    THEN
        RAISE EXCEPTION 'F3 DEMO postcondition failed; all changes rolled back.';
    END IF;
END
$nexus_dev_company_init$;

-- Read-only verification after commit (run as a separate query):
-- SELECT c.id,c.business_name,count(a.id) AS accounts,
--        count(a.id) FILTER (WHERE a.is_operational AND length(a.code)=6) AS operational_6d
-- FROM public.companies c LEFT JOIN public.accounting_accounts a
--      ON a.company_id=c.id
-- GROUP BY c.id,c.business_name ORDER BY c.id;
