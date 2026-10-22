# F2.3b — Motor de imputación analítica por centro de costos (preparado, desactivado)

**Fecha:** 22/10/2026. **Repositorio:** `jwichpas/contabilidad`, rama única `main`.
**Entorno autorizado:** Neon `contabilidad-dev`, PostgreSQL 18. No crear otras ramas ni modificar production/Supabase.

## Situación confirmada por el usuario y Neon

- **F2.3a instalada y verificada:** 20 migraciones, columna `journal_entry_lines.cost_center_id` nullable y FK con `company_id`.
- 3 empresas DEMO, 2.966 cuentas / 1.214 operativas de seis dígitos cada una.
- 227 cuentas operativas con `has_automatic_destination=true` por empresa; todas pertenecen a clase 6, tipo EXPENSE, y exigen centro de costos.
- 3 versiones `PCGE_2019_CC_V2` en `DRAFT`, 454 rutas por empresa (1.362 en total), `ACTIVE=0`.
- Los metadatos antiguos `9411/7911` son referencia histórica y **no** se usan al contabilizar.

## Implementación que se añade en F2.3b

1. **Migración portable:** `database/portable_migrations/2026_10_22_130000_create_journal_analytic_allocations.php`.
   - Añade el índice/clave única `(company_id, journal_entry_id, id)` a `journal_entry_lines` para FKs de trazabilidad al **mismo asiento**.
   - Crea `journal_analytic_allocations`: `company_id`, `journal_entry_id`, `source_line_id` (cuenta 6xx), `debit_line_id`, `credit_line_id`, `rule_version_id`, `cost_center_id`, `created_at`.
   - Restringe enlaces a la misma empresa y al mismo asiento con claves foráneas compuestas; unicidad por partida fuente y por cada partida analítica.
   - RLS `ENABLE+FORCE`, SELECT/INSERT exclusivamente en tenant activo para `app_backend`, sin UPDATE/DELETE.
   - Índice parcial UNIQUE garantiza a lo sumo **una versión ACTIVE por empresa**.
2. **`AnalyticRoutingService`:** consulta *exclusivamente* versiones `ACTIVE`; valida que la cuenta fuente sea 6xx EXPENSE operativa, que el centro esté activo y que las cuentas destino operativas correspondan a `CC-ADM` o `CC-VTA` con contrapartida `791000`. **No** lee destinos de metadatos legacy.
3. **`PostJournalEntryService`:** durante una misma transacción/lock del período:
   - Primero registra las partidas ingresadas por el usuario.
   - Para cada débito 6xx elegible genera **Debe 941100 o 951100 / Haber 791000** por exactamente los mismos montos local y extranjero.
   - Para un ajuste de crédito a 6xx genera las dos partidas invertidas: **Debe 791000 / Haber 941100 o 951100**.
   - Cada par analítico se liga de manera inequívoca a la partida 6xx y la versión de regla utilizada.
   - Evita mezclar 94/95/79 manuales y líneas auto-derivadas en el mismo voucher.
   - El extorno crea una copia invertida exacta de todas las partidas, conserva centro de costos y reconstruye los vínculos con los lados Debe/Haber invertidos; **no reejecuta la distribución** al extornar, incluso si las reglas fueron retiradas.
4. **Tests unitarios:** espejo monetario exacto 4 decimales bimoneda, ajuste de crédito, rechazo de importes contradictorios.
5. **CI PostgreSQL 18:** lint/plan e instalación real de estructura `F2.2 v2 -> F2.3a -> F2.3b` en base desechable, verificación de FK, unicidad, RLS y privilegios. Los tests HTTP reales/negativos con `ACTIVE` temporal aún están pendientes.

## Estado y barrera de activación

**Todo el código de generación está detrás de la condición `status=ACTIVE`.**

Actualmente las tres matrices están en `DRAFT`; por tanto, **después de instalar la migración F2.3b los POST seguirán sin generar transferencias**. No cambiar el estado por SQL manual. Falta una prueba integral del motor en PostgreSQL con matriz activa solo dentro de pruebas aisladas, permisos negativos, caso administrador/ventas, ajustes de crédito, partidas duplicadas, extorno, bloqueo por período, balance exacto y RLS multiempresa. Solo después se publicará un mecanismo de aprobación/activación versionado y auditado.

## Comandos reproducibles de verificación e instalación

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend

php -l database/portable_migrations/2026_10_22_130000_create_journal_analytic_allocations.php
php -l scripts/phase2-neon-analytic-allocations.php
php -l app/Services/Accounting/AnalyticRoutingService.php
php -l app/Services/Accounting/PostJournalEntryService.php
php -l tests/Unit/AnalyticRoutingServiceTest.php

php scripts/phase2-neon-analytic-allocations.php --plan
php scripts/phase2-neon-analytic-allocations.php --verify
# El primer --verify debe indicar PENDIENTE mientras haya 20 migraciones.
php scripts/phase2-neon-analytic-allocations.php --apply=contabilidad-dev
php scripts/phase2-neon-analytic-allocations.php --verify
php scripts/phase2-neon-tenant-integrity.php --verify
php scripts/phase2-neon-journal-cost-centers.php --verify

php artisan test --filter=AnalyticRoutingServiceTest
```

**Expected:** 21 migraciones, 3 matrices `DRAFT`, 1.362 rutas sin cambios, cero efectos sobre asientos o usuarios DEMO. La instalación sólo añade DDL de trazabilidad. No utilizar `migrate:fresh`, `db:seed`, ni migraciones generales. Si aparece `[BLOQUEO]`, detener y reportar el resultado sin reiniciar datos.

**No afirmar que el motor automático está certificado o activado** hasta completar pruebas HTTP/DB reales; el CI de sintaxis y migración es apenas un contrato de infraestructura.
