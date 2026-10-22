# F4 — Cuentas analíticas 94/95 y centros de costos: Neon DEV

**Fecha:** 22-10-2026. **Destino ejecutado:** proyecto Neon `soft-bonus-52215969`, rama existente `contabilidad-dev` (`br-odd-lake-b6wshqv3`), base `neondb`. Neon production y Supabase **no** fueron modificados.

## Qué se hizo

1. Se incorporó `InitializeCompanyAnalyticAccountsService` para nuevas empresas. Durante la clonación del PCGE inicializa un pequeño árbol de cuentas **analíticas privadas de la empresa**, separado de las 3.401 plantillas PCGE globales:
   - 9 → 94 → 941 → 9411 → 94110 → **941100**, gasto de administración.
   - 9 → 95 → 951 → 9511 → 95110 → **951100**, gasto de ventas.
   - Si la raíz 9 ya existe (como en la plantilla PCGE_2026), la valida y la reutiliza, sin sobrescribirla.
   - Solo las hojas `941100` y `951100` son operativas.
   - Se comprueba que la cuenta operativa `791000` ya exista en la misma empresa.
   - Se crean los centros `CC-ADM` (`941100`/Debe, `791000`/Haber) y `CC-VTA` (`951100`/Debe, `791000`/Haber) mediante foreign keys intraempresa.
   - Es idempotente si los datos existentes coinciden, bloquea conflictos de códigos y no crea entidades, bancos ni personas.

2. Para las tres empresas DEMO existentes se ejecutó **una sola vez** el parche aditivo y transaccional `backend/database/data_patches/2026_10_22_100000_init_demo_analytic_cost_centers.sql`. Comprobó primero empresas, catálogos, migraciones, cuentas iniciales, usuarios y ausencia de asientos. El parche no hace DROP, TRUNCATE, DELETE, ni altera plantillas globales.

3. Se actualizó `MasterChartOfAccountsTest.php` para el conteo nuevo de inicialización PCGE 2019: **2.966 cuentas** y **1.214 imputables de seis dígitos**. La clase nueva tiene `CompanyAnalyticAccountsTest.php` con pruebas estáticas de la jerarquía, seis dígitos y amarres.

## Verificación directa de PostgreSQL, no solo salida declarativa

| Empresa | Total cuentas | Imputables (6 dígitos) | Centros |
| --- | ---: | ---: | ---: |
| DEMO A | 2.966 | 1.214 | 2 |
| DEMO B | 2.966 | 1.214 | 2 |
| DEMO C | 2.966 | 1.214 | 2 |

**Totales:** 8.898 cuentas privadas, 3.642 imputables, 6 centros de costos. Continúan 3.401 plantillas maestras y un ledger F2, 3 usuarios, 8 membresías. **0 asientos y 0 partidas**. Sin cuentas huérfanas y sin cuentas imputables de longitud distinta a seis dígitos.

La consulta como rol restringido `app_backend` con tenant DEMO A vio 2 centros de costos propios, 2 hojas analíticas y 0 centros de DEMO B. Sin contexto de empresa vio 0 centros y 0 hojas. Se verificó lectura RLS, todavía NO los endpoints POST ni el asiento automático.

## Limitaciones importantes

- `PostJournalEntryService` y `CreateJournalEntryController` hoy reciben `lines.*.accounting_account_id` pero **no** `lines.*.cost_center_id`. `journal_entry_lines` tampoco tiene una FK de centro de costos. **No declarar imputación automática por centro ni generar filas 94/95/79 desde POST** hasta completar una migración y pruebas con tratamiento contable correcto; el servicio actual simplemente persiste líneas.
- Las cuentas de naturaleza 62/63/64/65/67/68 clonadas del maestro todavía tienen metadatos `destination_debit_code='9411'` y `destination_credit_code='7911'`, previos a F4. Estos códigos no son imputables de seis dígitos. En la próxima iteración, sustituir por lógica referenciada al centro de costo elegido y evitar dependencias implícitas. No alterar automáticamente todas las cuentas históricas por un destino único.
- Las normas tributarias y obligaciones reales SIRE/PLE para una empresa todavía requieren configuración; los defaults DEMO no sirven para producción.
- No se ha ejecutado PHP/Pest de F4 ni una prueba de integración HTTP contra un entorno aislado. El usuario confirmó antes **2 unitarios/13 aserciones** de `SixDigitChartExpanderTest`; eso NO certifica F4.

## Comprobaciones locales recomendadas

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l app/Services/Accounting/InitializeCompanyAnalyticAccountsService.php
php -l app/Services/Accounting/InitializeCompanyChartOfAccountsService.php
php -l tests/Unit/CompanyAnalyticAccountsTest.php
php artisan test --filter=CompanyAnalyticAccountsTest
php artisan test --filter=SixDigitChartExpanderTest
```

El archivo SQL F4 queda como registro auditable de una instalación DEMO; **no repetir la aplicación** (las precondiciones lo impedirían). Tampoco ejecutar `migrate:fresh`, `db:seed` general, operaciones sobre Neon production o sobre Supabase.
