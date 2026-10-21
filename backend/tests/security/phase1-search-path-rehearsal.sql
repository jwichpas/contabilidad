-- F1.4: comprobacion NO DDL de endurecimiento search_path aplicado por Laravel.
-- Solo pg17 CI desechable tras aplicar migraciones de esquema.
\set ON_ERROR_STOP on
BEGIN READ ONLY;
SET LOCAL statement_timeout = '20s';

DO $audit$
DECLARE
    expected text[] := ARRAY[
        'app_current_company_id',
        'trg_fn_enforce_accounting_account_hierarchy',
        'trg_fn_prevent_journal_entry_mutation',
        'trg_fn_validate_journal_line_context',
        'trg_fn_enforce_double_entry_balance'
    ];
    count_hardened integer;
BEGIN
    SELECT count(*) INTO count_hardened
    FROM pg_catalog.pg_proc p
    JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
    WHERE n.nspname='public'
      AND p.proname=ANY (expected)
      AND p.pronargs=0
      AND NOT p.prosecdef
      AND 'search_path=pg_catalog' = ANY (p.proconfig);
    IF count_hardened <> 5 THEN
        RAISE EXCEPTION 'No estan fijados los cinco search_path: %/5',count_hardened;
    END IF;

    IF public.app_current_company_id() IS NOT NULL THEN
        RAISE EXCEPTION 'El helper RLS no es fail-closed sin contexto';
    END IF;
END
$audit$;

SELECT set_config('app.current_company_id','101',true);
DO $audit$
BEGIN
    IF public.app_current_company_id() IS DISTINCT FROM 101 THEN
        RAISE EXCEPTION 'Helper RLS fallo con search_path fijo';
    END IF;
END
$audit$;

ROLLBACK;
SELECT 'PASS: cinco funciones con search_path fijo; helper RLS conserva contexto y fail-closed' AS result;
