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
5. **CI PostgreSQL 18:** lint/plan e instalación real de estructura `F2.2 v2 -> F2.3a -> F2.3b` en base desechable, verificación de FK, unicidad, RLS y privilegios. El probe con `ACTIVE` temporal está publicado, **no ejecutado aún**; faltan pruebas HTTP completas y RLS usando `app_backend`.

## Prueba de integración F2.3b con rollback completo

En `backend/scripts/phase2-neon-analytic-rollback.php` se agregó un probe protegido que **requiere** migración 21, base de desarrollo con 3 empresas DEMO, 0 asientos, 3 matrices DRAFT y 1362 rutas. Usa exclusivamente la conexión administrativa **dentro de una transacción global**, marca una matriz `ACTIVE` de forma transitoria y ejecuta los servicios reales `PostJournalEntryService::post` y `reverse` contra centros administrativos y de ventas. Verifica importes exactos en dos monedas, trazabilidad 6xx-94/95-79, extorno invertido, rechazo de centro faltante/cruzado y `SET CONSTRAINTS ALL IMMEDIATE`; termina con **ROLLBACK**, y vuelve a consultar que existan cero asientos, cero vínculos, cero períodos temporales y cero versiones activas.

Este probe usa `neondb_owner` para poder activar la regla durante su transacción, por lo que **no certifica la política RLS real de `app_backend`**. Es un control adicional, no autorización de activación. La instalación F2.3b no habilita la distribución: sin `ACTIVE`, no genera nuevas líneas.

```bash
php -l scripts/phase2-neon-analytic-rollback.php
php scripts/phase2-neon-analytic-rollback.php --plan
# SOLO despues de instalar y verificar migracion F2.3b:
php scripts/phase2-neon-analytic-rollback.php --probe=contabilidad-dev
php scripts/phase2-neon-analytic-allocations.php --verify
```

Si falla el probe, conservar DRAFT y revisar la causa sin hacer SQL de activación ni modificaciones al código en producción.

## F2.3c — RLS operativo app_backend (22/10/2026)

**Comprobación real de lectura YA APROBADA directamente en Neon:** conexión `app_backend` con contexto explícito alternado para las tres empresas demo: exactamente 454 rutas `accounting_destination_routes` y 1 versión visible por tenant; en los tres casos, cero rutas de otra empresa. Sin `app.current_company_id`, cero rutas y cero links visibles. La consulta administrativa de permisos confirma `SELECT/INSERT=true` y `UPDATE/DELETE=false` para `journal_analytic_allocations`. **Esta es evidencia read-only; no certifica INSERT negativo ni integridad FK.**

**Nuevo probe F2.3c en GitHub, pendiente de ejecución:**

`backend/scripts/phase2-neon-analytic-rls.php`

- Exige ambiente Neon `contabilidad-dev`/PG18 directo, 21 migraciones, tres matrices DRAFT, 1362 rutas, empresas ficticias verificadas, y cero asientos/links previos.
- Usa **conexión runtime `app_backend`** con RLS, no el owner para las pruebas. Un SELECT inicial del owner únicamente verifica precondiciones; ningún `ACTIVE` transitorio.
- En una transacción global, crea dos vouchers totalmente ficticios (A y B), con reglas `DRAFT`; comprueba que el POST manual siga creando dos líneas. Añade dos líneas analíticas *de prueba* equilibradas y un vínculo por empresa **sólo para probar políticas y FK**. Esos vínculos directos de test **no equivalen a activar POST automático**.
- Exige denegación SQLSTATE `42501` al intentar insertar datos de B bajo A y al intentar UPDATE/DELETE; `23503` para vínculo al origen de empresa distinta y `23505` para fuente duplicada.
- Exige visibilidad A/B aislada y cero datos visibles desde C o sin tenant.
- Valida triggers diferidos bajo el GUC de su tenant antes de cambiar de empresa. Finalmente ROLLBACK global y verificación independiente, vía owner, de **cero asientos, líneas, vínculos y períodos**, 3 DRAFT, 0 ACTIVE.
- No certifica todavía todo el flujo HTTP ni el mecanismo auditado de activación. GitHub Actions continúa sin jobs ejecutados, requiere investigación separada.

```bash
cd ~/contabilidad && git pull --ff-only origin main && cd backend
php -l scripts/phase2-neon-analytic-rls.php
php scripts/phase2-neon-analytic-rls.php --plan
php scripts/phase2-neon-analytic-rls.php --probe=contabilidad-dev
php scripts/phase2-neon-analytic-allocations.php --verify
php scripts/phase2-neon-tenant-integrity.php --verify
```

**Corrección del test negativo 23503 (22/10/2026):** la primera ejecución reportó `[FAIL] SQLSTATE inesperado: se esperaba 23503`, después de verificar que INSERT/SELECT de A/B funcionaban. La prueba originalmente reemplazaba únicamente `source_line_id` con el de B, pero reutilizaba `debit_line_id` y `credit_line_id` ya insertados en A; las dos restricciones UNIQUE podían interceptar la operación (23505) antes de validar FK. Se actualizó el caso para reemplazar **los tres IDs de línea** con los de B y mantener `company_id/journal_entry_id` de A, aislando la infracción de FK compuesta. El script ahora informa también el SQLSTATE observado. El ROLLBACK y el esquema de las 21 migraciones sí habían pasado; la prueba completa F2.3c sigue pendiente de repetición.

**Importante:** la verificación puede fallar con `[FAIL]` y revertir igualmente la transacción. No activar reglas ni modificar manualmente datos en ese caso; analizar SQLSTATE y middleware/contexto.

## F2.3c verificada y F2.3d HTTP preparada (22/10/2026)

**El usuario confirmó ejecución completa de F2.3c con app_backend:** INSERT/SELECT de A/B; 42501 para escritura cruzada y UPDATE/DELETE, 23503 para referencia de otro voucher/tenant, 23505 para vínculo duplicado; aislamiento B/C/sin tenant; triggers diferidos PASS; ROLLBACK completo y cero asientos, líneas, vínculos y períodos. Se mantienen 21 migraciones, 3 DRAFT, 1362 rutas, 0 ACTIVE.

**F2.3d HTTP está publicada, NO ejecutada aún:** el script `backend/scripts/phase2-neon-analytic-http.php` utiliza el Kernel Laravel y Sanctum en conexión `app_backend` para comprobar HTTP 201 de voucher USD/PEN con centro de costos, tipo de cambio, auditoría, HTTP 422 por centro faltante/ajeno y descuadre, GET de diario con aislamiento multiempresa, HTTP 403 por rol AUDITOR/membresía ausente y 201 de extorno con rechazo 422 al repetir. Todos los cambios son temporales en una transacción externa que termina ROLLBACK. Revalida 0 asientos, líneas, vínculos, períodos, tipos de cambio y auditorías; DRAFT no genera líneas analíticas.

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-analytic-http.php
php scripts/phase2-neon-analytic-http.php --plan
php scripts/phase2-neon-analytic-http.php --probe=contabilidad-dev
php scripts/phase2-neon-analytic-allocations.php --verify
php scripts/phase2-neon-tenant-integrity.php --verify
```

**Mejora del historial:** el backend incluye `lines.costCenter` en GET/POST/extorno; el frontend usa `cost_center.code`, incluso para centros desactivados posteriormente. No altera asientos históricos.

**Pendiente antes de activar:** prueba HTTP con matriz ACTIVE reversible (además del probe F2.3b a nivel de servicio), promoción formal versionada y auditada, y reparación/verificación del CI de GitHub Actions sin jobs.

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
