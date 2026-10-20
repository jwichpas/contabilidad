-- F1.2: actual migrated ERP tables, real app_backend, no test data required.
-- The session must NOT carry a company context between transactions.
\set ON_ERROR_STOP on
SET ROLE app_backend;
DO $audit$
DECLARE
  t record;
  n bigint;
BEGIN
  IF public.app_current_company_id() IS NOT NULL THEN RAISE EXCEPTION 'Unexpected session tenant at start'; END IF;
  FOR t IN
    SELECT format('%I.%I',ns.nspname,c.relname) AS table_name
    FROM pg_catalog.pg_class c
    JOIN pg_catalog.pg_namespace ns ON ns.oid=c.relnamespace
    JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid AND a.attname='company_id' AND NOT a.attisdropped
    WHERE ns.nspname='public' AND c.relkind IN ('r','p')
      AND c.relrowsecurity AND c.relforcerowsecurity
      AND has_table_privilege(current_user,c.oid,'SELECT')
    ORDER BY c.relname
  LOOP
    EXECUTE format('SELECT count(*) FROM %s',t.table_name) INTO n;
    IF n <> 0 THEN RAISE EXCEPTION 'Tenant-free read leaked records from %',t.table_name; END IF;
  END LOOP;
END
$audit$;
BEGIN;
SELECT set_config('app.current_company_id','101',true);
DO $audit$
BEGIN
 IF public.app_current_company_id() IS DISTINCT FROM 101 THEN
  RAISE EXCEPTION 'LOCAL context not visible inside transaction';
 END IF;
END
$audit$;
COMMIT;
DO $audit$
BEGIN
 IF public.app_current_company_id() IS NOT NULL THEN
  RAISE EXCEPTION 'Company context persisted beyond COMMIT';
 END IF;
END
$audit$;
BEGIN;
SELECT set_config('app.current_company_id','202',true);
DO $audit$
BEGIN
 IF public.app_current_company_id() IS DISTINCT FROM 202 THEN
  RAISE EXCEPTION 'Second transaction has wrong tenant';
 END IF;
END
$audit$;
ROLLBACK;
DO $audit$
BEGIN
 IF public.app_current_company_id() IS NOT NULL THEN
  RAISE EXCEPTION 'Company context survived ROLLBACK';
 END IF;
END
$audit$;
RESET ROLE;
SELECT 'PASS: ERP tenant context is transaction-local and fails closed on real tables' AS result;
