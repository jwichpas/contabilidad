-- Solo contabilidad-dev, PG18. Tres empresas DEMO A/B/C verificadas.
-- Ejecutar 3 sentencias COMO UNA TRANSACCION. Ningun TRUNCATE, DELETE o UPDATE.
-- Referencia operativa: CompanyOperationalDefaultsService.php; altas nuevas usan ese servicio.
-- NOTA: la fase F2 global sigue pendiente y no depende de este backfill.
DO $safe$
BEGIN
  IF (SELECT count(*) FROM public.companies) <> 3
     OR (SELECT count(*) FROM (SELECT id FROM public.companies WHERE (tax_id, business_name) IN (
  ('20999990011', 'DEMO A - Comercial Andina S.A.C.'),
  ('20999990012', 'DEMO B - Servicios del Sur S.A.C.'),
  ('20999990013', 'DEMO C - Distribuidora Pacifico S.A.C.')
)) AS demo) <> 3
     OR (SELECT count(*) FROM public.company_areas) <> 0
     OR (SELECT count(*) FROM public.cash_flow_areas) <> 0 THEN
    RAISE EXCEPTION 'El entorno demo o sus areas ya difieren del punto de partida esperado';
  END IF;
END
$safe$;

WITH definitions(code, name, description) AS (VALUES
  ('DIR-01', 'Dirección General y Gerencia', 'Alta dirección estratégica'),
  ('ADM-01', 'Administración Central', 'Gestión administrativa y recursos'),
  ('FIN-01', 'Finanzas y Tesorería', 'Gestión de liquidez y bancos'),
  ('CON-01', 'Contabilidad y Tributación', 'Contabilidad financiera y fiscalidad'),
  ('LOG-01', 'Logística y Almacén', 'Compras, inventarios y despachos'),
  ('COM-01', 'Comercial y Ventas', 'Fuerza de ventas y canales de distribución')
)
INSERT INTO public.company_areas(company_id, code, name, description, is_active, created_at, updated_at)
SELECT c.id, d.code, d.name, d.description, TRUE, now(), now()
FROM (SELECT id FROM public.companies WHERE (tax_id, business_name) IN (
  ('20999990011', 'DEMO A - Comercial Andina S.A.C.'),
  ('20999990012', 'DEMO B - Servicios del Sur S.A.C.'),
  ('20999990013', 'DEMO C - Distribuidora Pacifico S.A.C.')
)) AS c CROSS JOIN definitions AS d
ON CONFLICT (company_id, code) DO NOTHING;

WITH definitions(code, name, activity_category, flow_direction) AS (VALUES
  ('OPE-01', 'Cobranza por Venta de Mercaderías y Servicios', 'OPERATING', 'INFLOW'),
  ('OPE-02', 'Pagos a Proveedores de Bienes y Servicios', 'OPERATING', 'OUTFLOW'),
  ('OPE-03', 'Pago de Remuneraciones y Beneficios Sociales', 'OPERATING', 'OUTFLOW'),
  ('OPE-04', 'Pago de Tributos y Contribuciones', 'OPERATING', 'OUTFLOW'),
  ('INV-01', 'Adquisición de Propiedades, Planta y Equipo', 'INVESTING', 'OUTFLOW'),
  ('INV-02', 'Venta de Activos Inmovilizados', 'INVESTING', 'INFLOW'),
  ('FIN-01', 'Aportes de Capital Social', 'FINANCING', 'INFLOW'),
  ('FIN-02', 'Préstamos Bancarios y Amortización Financiera', 'FINANCING', 'BOTH'),
  ('EFE-01', 'Fondos Disponibles en Caja y Cuentas Corrientes', 'CASH_EQUIVALENT', 'BOTH')
)
INSERT INTO public.cash_flow_areas(company_id, code, name, activity_category, flow_direction, is_active, created_at, updated_at)
SELECT c.id, d.code, d.name, d.activity_category, d.flow_direction, TRUE, now(), now()
FROM (SELECT id FROM public.companies WHERE (tax_id, business_name) IN (
  ('20999990011', 'DEMO A - Comercial Andina S.A.C.'),
  ('20999990012', 'DEMO B - Servicios del Sur S.A.C.'),
  ('20999990013', 'DEMO C - Distribuidora Pacifico S.A.C.')
)) AS c CROSS JOIN definitions AS d
ON CONFLICT (company_id, code) DO NOTHING;
