-- Real ERP function regression on disposable PostgreSQL 17 after Laravel migrations.
\set ON_ERROR_STOP on
SET ROLE app_backend;
DO $$
BEGIN
 IF public.app_current_company_id() IS NOT NULL THEN
   RAISE EXCEPTION 'Tenant context must fail closed when missing';
 END IF;
END $$;
SELECT set_config('app.current_company_id','101',false);
DO $$
BEGIN
 IF public.app_current_company_id() IS DISTINCT FROM 101 THEN
   RAISE EXCEPTION 'Tenant context returned wrong company';
 END IF;
END $$;
SELECT set_config('app.current_company_id','invalid',false);
DO $$
BEGIN
 IF public.app_current_company_id() IS NOT NULL THEN
   RAISE EXCEPTION 'Invalid tenant context must fail closed';
 END IF;
END $$;
SELECT set_config('app.current_company_id','',false);
DO $$
BEGIN
 IF public.app_current_company_id() IS NOT NULL THEN
   RAISE EXCEPTION 'Empty tenant context must fail closed';
 END IF;
END $$;
RESET ROLE;
SELECT 'PASS: real ERP tenant context function' AS result;
