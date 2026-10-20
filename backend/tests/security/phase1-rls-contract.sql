-- F1 security regression fixture: PostgreSQL 17 disposable ONLY.
-- This is an isolated policy contract test, NOT a validation of live Supabase RLS.
\set ON_ERROR_STOP on
CREATE ROLE f1_app LOGIN;
CREATE ROLE f1_anon LOGIN;
CREATE SCHEMA f1_contract;
GRANT USAGE ON SCHEMA f1_contract TO f1_app;
CREATE TABLE f1_contract.ledger (
  id integer PRIMARY KEY,
  company_id integer NOT NULL,
  amount numeric(14,4) NOT NULL
);
INSERT INTO f1_contract.ledger VALUES (1,101,12.3400),(2,202,99.0000);
GRANT SELECT, INSERT, UPDATE ON f1_contract.ledger TO f1_app;
ALTER TABLE f1_contract.ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE f1_contract.ledger FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON f1_contract.ledger
  FOR ALL TO f1_app
  USING (company_id = NULLIF(current_setting('app.current_company_id',true),'')::integer)
  WITH CHECK (company_id = NULLIF(current_setting('app.current_company_id',true),'')::integer);
SET ROLE f1_app;
-- No tenant context: zero rows.
DO $$ BEGIN
 IF (SELECT count(*) FROM f1_contract.ledger) <> 0 THEN RAISE EXCEPTION 'missing context exposed rows'; END IF;
END $$;
SELECT set_config('app.current_company_id','101',false);
DO $$ BEGIN
 IF (SELECT count(*) FROM f1_contract.ledger) <> 1 THEN RAISE EXCEPTION 'tenant 101 count invalid'; END IF;
 IF (SELECT count(*) FROM f1_contract.ledger WHERE company_id=202) <> 0 THEN RAISE EXCEPTION 'cross tenant read'; END IF;
END $$;
DO $$
DECLARE rejected boolean := false;
BEGIN
 BEGIN
   INSERT INTO f1_contract.ledger VALUES(3,202,1.0000);
 EXCEPTION WHEN insufficient_privilege THEN
   rejected := true;
 END;
 IF NOT rejected THEN
   RAISE EXCEPTION 'cross tenant insert unexpectedly allowed';
 END IF;
END $$;
RESET ROLE;
-- Anonymous role must not see records, even with tenant header set.
SET ROLE f1_anon;
DO $$ BEGIN
 IF has_table_privilege(current_user,'f1_contract.ledger','SELECT') THEN
    RAISE EXCEPTION 'anonymous SELECT grant detected';
 END IF;
END $$;
RESET ROLE;
-- No persistent side effects: container is destroyed by CI.
SELECT 'PASS: isolated PostgreSQL RLS contract fixture' AS result;
