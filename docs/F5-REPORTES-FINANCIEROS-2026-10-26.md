# F5 — ESF, ERN y Libro Mayor: conciliación real sobre Neon DEV

**Fecha:** 26/10/2026. **Rama GitHub:** `main`. **Base examinada:** Neon `contabilidad-dev`, `br-odd-lake-b6wshqv3`, `neondb`, **solo SELECT**. Sin modificaciones de asientos, empresas ni catálogos durante la revisión.

## Funcionalidad implementada

- API `GET /api/v1/accounting/reports/financial-snapshot?as_of=YYYY-MM-DD`:
  - saldos de asientos `POSTED` y sus originales `REVERSED`, exclusivamente del tenant autorizado;
  - cuatro decimales exactos (BCMath), moneda funcional desde `company_settings`;
  - ESF preliminar con balance de comprobación, ERN del ejercicio y diferencia de presentación;
  - **nuevo** `data.rubrics`: importes agregados por código y nombre vigentes de `financial_statement_rubrics`, con sección, orden de presentación y `account_ids` para navegación;
  - **nuevo** `data.unmapped_account_ids`: aviso de cuentas con movimientos que carecen de rubro registrado;
  - sin incluir las cuentas puente 79 o analíticas 9 en el resultado de ESF/ERN. Sí integran el balance de comprobación.
- API `GET /api/v1/accounting/reports/general-ledger`: saldo inicial y saldo acumulado por cuenta y periodo, paginado, dentro del tenant autorizado.
- Vista Vue `FinancialStatementsView.vue`: ESF por rubro, ERN por rubro, detalle expandible de cuentas que enlaza al Libro Mayor, balance de comprobación y diferencias visibles. Presentación de importes decimales como cadenas, sin IEEE-754.

## Evidencia de consulta independiente — DEMO A (26/10/2026, PEN)

DEM0 A: 7 asientos, 16 partidas, 9 cuentas con movimiento. Se sumaron líneas directamente en PostgreSQL y se compararon los saldos finales calculados por `SUM(...) OVER (PARTITION BY cuenta ORDER BY fecha,id,orden)` con `SUM(debe-haber)`: **las 9 cuentas coinciden**.

| Rubro | Código | Saldo PEN |
| --- | --- | ---: |
| Efectivo y equivalentes | `ESF_ACT_CORR_EFE` | 12.640,0000 |
| Cuentas por cobrar comerciales | `ESF_ACT_CORR_CXC_COM` | 718,5000 |
| Inventarios | `ESF_ACT_CORR_INV` | 6.480,0000 |
| Cuentas por pagar comerciales | `ESF_PAS_CORR_CXP_COM` | 2.050,8900 |
| Capital social | `ESF_PAT_CAP` | 19.120,0000 |
| Ventas netas | `ER_ING_VENTAS_NETAS` | 718,5000 |
| Compras y variación de inventarios (aún sin ajuste de presentación) | `ERN_COMPRAS_MERC` | 2.050,8900 gasto |

**Activo** = 19.838,5000. **Pasivo** = 2.050,8900. **Capital** = 19.120,0000. **Resultado provisional** = −1.332,3900. **Diferencia ESF** = 0,0000. **Debe/Haber** = 21.989,3900 cada lado.

## Alcance y advertencias

- **El usuario confirmó** `FinancialReportsTenantCurrencyTest`: **2 PASS, 9 assertions** antes de añadir las columnas `rubrics` y `unmapped_account_ids`. Las pruebas existentes fueron extendidas a los campos nuevos: **pendiente rerun**. El frontend compilaba y el test de formato dio 3 PASS antes de modificar la vista por rubros: **pendiente nuevo `pnpm typecheck`**.
- Consultas con rol `app_backend` comprobadas: en DEMO A se ven 16 partidas y en DEMO B no se ve ninguna de DEMO A. No se certifica que las rutas HTTP modificadas ya se hayan ejecutado en el servidor.
- Las matrices analíticas `accounting_destination_rule_versions` de las tres DEMO están en **DRAFT** con 454 rutas cada una; todavía no existe una versión `ACTIVE` ni allocations registradas. No se debe afirmar que el automatismo 94/95/79 esté funcionando en asientos reales. La activación requiere validación y flujo auditado.
- Los estados financieros siguen **preliminares**: falta separar presentación ESF comparativa, ER por función, ajustes de existencias/costo de ventas, cierre y notas NIC/NIIF. Los rubros globales son de presentación, pero el balance contable no demuestra por sí mismo valoración o ajustes NIIF.

## Verificación local corta

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php artisan test --filter=FinancialReportsTenantCurrencyTest
cd ../frontend
pnpm typecheck
```

No se requieren migraciones F5 ni ejecutar seeds. No usar GitHub Actions.


## Continuación F5 — auditoría de matrices para su eventual activación (26/10/2026)

- Confirmación del usuario: `FinancialReportsTenantCurrencyTest` **2 PASS, 12 assertions** y `vue-tsc --noEmit` sin errores mostrados.
- Inspección READ ONLY DEMO A: matriz ID 1, `PCGE_2019_CC_V2`, estado `DRAFT`, 227 fuentes / 454 pares, dos centros activos, 0 rutas inválidas y digest real SHA-256 `7ec6129c655bc4ebd9ad6f378d8b12352c3e097da33517e4f1f6e484d70e01f8`, idéntico al digest versionado.
- DEMO B/C permanecen `DRAFT`; las tres empresas tienen **0 imputaciones analíticas**. DEMO A conserva siete asientos de prueba con 16 partidas; en particular `601100` registra S/ 2.050,8900 y **no lleva imputación automática**, mientras `621100` y `636100` requieren centro y regla pero aún no tienen movimientos. **No retroalimentar ni convertir asientos anteriores automáticamente**.
- Los scripts antiguos de promoción requerían 21/22 migraciones y tres empresas, incompatible con el estado actual: **63 migraciones / cuatro empresas**. Se actualizaron `AnalyticMatrixPromotionService` y el preflight READ ONLY, conservando el mismo aislamiento del servicio, la validación SHA y la auditoría obligatoria.
- **No se ha activado la matriz**: el intento de crear el parche automático de activación fue bloqueado por controles de la herramienta; ninguna transición o asiento nuevo fue confirmado. Se mantiene `DRAFT`. No se debe afirmar que el POST genera actualmente nuevas líneas analíticas sobre DEMO A.

### Preflight de solo lectura

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l app/Services/Accounting/AnalyticMatrixPromotionService.php
php -l scripts/phase2-neon-analytic-promotion-preflight.php
php scripts/phase2-neon-analytic-promotion-preflight.php --preflight=20999990011
```

Este preflight no cambia datos ni activa reglas. Para el paso siguiente se necesita una transición auditada `DRAFT → ACTIVE` aprobada para DEMO A y luego un asiento **nuevo** en cuenta 6xx marcada como `has_automatic_destination`, con su centro de costos, contrastando `6xx / 94xx-95xx / 791000` y el resultado de los reportes. No asumir que las reglas crean distribución retroactiva de cuentas de compras `601100`.


## F5.1 — Comando de activación operativa de DEMO A (27/10/2026)

El usuario ejecutó y confirmó:

```
[PASS] 20999990011: 227 fuentes, CC-ADM/CC-VTA, 454 rutas, SHA256 valido, DRAFT.
[PASS] F2.3f: preflight READ ONLY, ninguna matriz activada.
```

Se añadió `backend/scripts/phase5-demo-a-analytic-activate.php`, un **comando operativo CLI**, no una migración, que por defecto solo imprime el plan y no se conecta a Neon. Con la opción explícita `--activate-demo-a=<sha256>` invoca el servicio de promoción existente, con verificaciones del host de desarrollo, rol propietario, 63 migraciones, empresa DEMO, membresía ADMIN, rutas 227×2 y huella SHA-256. La transacción agrupa la transición DRAFT→ACTIVE y el registro obligatorio en `audit_logs`. No altera DEMO B/C ni asientos anteriores.

**Ejecución explícita, una sola vez, por el operador sobre Neon `contabilidad-dev`:**

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase5-demo-a-analytic-activate.php
php scripts/phase5-demo-a-analytic-activate.php --plan
php scripts/phase5-demo-a-analytic-activate.php --activate-demo-a=7ec6129c655bc4ebd9ad6f378d8b12352c3e097da33517e4f1f6e484d70e01f8
```

La última línea **modifica intencionadamente** el estado analítico de DEMO A y crea un registro de auditoría si pasa todos los controles. La operación no es idempotente: un segundo intento se rechaza porque el estado ya no será DRAFT. **No confundir comando preparado con comando ejecutado**: por ahora no existe constancia de activación; el último SELECT de Neon seguía mostrando tres matrices DRAFT, cero asignaciones analíticas.

Tras ver el comprobante de activación, probar desde el editor de asientos un movimiento **nuevo** en cuenta 6xx que exija centro y destino (p. ej. `621100` si sus otras dimensiones están completas), seleccionando CC-ADM o CC-VTA. Se deben registrar 4 líneas para el voucher básico (2 manuales + 2 de destino); verificar `journal_analytic_allocations`, balances en Libro Mayor y exclusión de 94/95 y 79 del resultado ERN para evitar duplicaciones. NO reimputar los tres movimientos anteriores de compras `601100`.
