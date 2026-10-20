-- F1.1: verify search_path hardening on actual functions, isolated PostgreSQL 17.
-- All ALTER FUNCTION statements are rolled back: no persistent database changes.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '20s';
ALTER FUNCTION public.app_current_company_id() SET search_path = '';
ALTER FUNCTION public.trg_fn_enforce_accounting_account_hierarchy() SET search_path = '';
ALTER FUNCTION public.trg_fn_prevent_journal_entry_mutation() SET search_path = '';
ALTER FUNCTION public.trg_fn_validate_journal_line_context() SET search_path = '';
ALTER FUNCTION public.trg_fn_enforce_double_entry_balance() SET search_path = '';
DO $audit$
DECLARE
    expected text[] := ARRAY[
        'app_current_company_id',
        'trg_fn_enforce_accounting_account_hierarchy',
        'trg_fn_prevent_journal_entry_mutation',
        'trg_fn_validate_journal_line_context',
        'trg_fn_enforce_double_entry_balance'
    ];
    actual integer;
BEGIN
    SELECT count(*) INTO actual
    FROM pg_catalog.pg_proc p
    JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname = 'public'
      AND p.proname = ANY (expected)
      AND p.pronargs = 0
      AND NOT p.prosecdef
      AND 'search_path=' = ANY (p.proconfig);
    IF actual <> array_length(expected, 1) THEN
        RAISE EXCEPTION 'Search path check failed: % of % functions',actual,array_length(expected,1);
    END IF;
    IF public.app_current_company_id() IS NOT NULL THEN
        RAISE EXCEPTION 'Fail-closed helper invalid with empty search_path';
    END IF;
END
$audit$;
SELECT set_config('app.current_company_id','101',true);
DO $audit$
BEGIN
    IF public.app_current_company_id() IS DISTINCT FROM 101 THEN
        RAISE EXCEPTION 'Tenant helper failed with hardened search_path';
    END IF;
END
$audit$;
ROLLBACK;
SELECT 'PASS: temporary search_path hardening tested and rolled back' AS result;
