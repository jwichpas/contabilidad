-- Inspect REAL Laravel migrations, no business rows or mutable statements.
\set ON_ERROR_STOP on
DO $audit$
DECLARE
  forced_count integer;
  tenant_without_rls integer;
  exposed_count integer;
  missing_app_policies integer;
BEGIN
  SELECT count(*) INTO forced_count
  FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='public' AND c.relkind IN ('r','p')
    AND c.relrowsecurity AND c.relforcerowsecurity;
  IF forced_count < 14 THEN
    RAISE EXCEPTION 'Expected 14+ FORCE RLS tables after actual migrations, got %',forced_count;
  END IF;

  SELECT count(*) INTO tenant_without_rls
  FROM pg_catalog.pg_class c
  JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid AND a.attname='company_id'
  WHERE n.nspname='public' AND c.relkind IN ('r','p')
    AND NOT a.attisdropped AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity);
  IF tenant_without_rls <> 0 THEN
    RAISE EXCEPTION 'Tenant tables lacking ENABLE/FORCE RLS: %',tenant_without_rls;
  END IF;

  SELECT count(*) INTO exposed_count
  FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m')
    AND (
      has_table_privilege('anon',c.oid,'SELECT')
      OR has_table_privilege('anon',c.oid,'INSERT')
      OR has_table_privilege('anon',c.oid,'UPDATE')
      OR has_table_privilege('anon',c.oid,'DELETE')
      OR has_table_privilege('authenticated',c.oid,'SELECT')
      OR has_table_privilege('authenticated',c.oid,'INSERT')
      OR has_table_privilege('authenticated',c.oid,'UPDATE')
      OR has_table_privilege('authenticated',c.oid,'DELETE')
    );
  IF exposed_count <> 0 THEN
    RAISE EXCEPTION 'Unexpected anon/authenticated table privileges: %',exposed_count;
  END IF;

  SELECT count(*) INTO missing_app_policies
  FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='public' AND c.relkind IN ('r','p') AND c.relforcerowsecurity
    AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_policy p WHERE p.polrelid=c.oid);
  IF missing_app_policies <> 0 THEN
    RAISE EXCEPTION 'FORCE RLS tables missing policies: %',missing_app_policies;
  END IF;
  RAISE NOTICE 'PASS real schema: % forced tables; zero tenant RLS gaps, public grants or missing policies',forced_count;
END
$audit$;
