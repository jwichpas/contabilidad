## F2.3h — corrección de invocación PowerShell (22/10/2026)

El usuario ejecutó dos veces el wrapper: primera vez Docker Desktop no respondía con motor Linux; segunda vez Docker sí respondió pero imprimió su ayuda general en lugar de ejecutar `docker run`, luego `No such container` y `Puerto Docker no seguro`. El fallo fue de la función PowerShell `Invoke-F23h`: el parámetro `$Args` colisionaba con la variable automática `$args`, por lo que no propagaba los argumentos a la aplicación nativa. **No implica defecto en RLS ni en el código contable**, porque las migraciones y el probe PHP ni siquiera habían iniciado.

Solución aplicada a `backend/scripts/phase2-active-rls-docker.ps1`: parámetro renombrado a `$Arguments`, con `-Program` y `-Arguments` explícitos en las seis invocaciones y `& $Program @Arguments`. El `finally` confirma el ID real mediante `docker container inspect` antes de afirmar que eliminó un contenedor.

**F2.3h continúa pendiente de su primera ejecución efectiva; no declarar PASS hasta que termine el probe completo.**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
docker info --format '{{.OSType}}'
.\scripts\phase2-active-rls-docker.ps1
```

## F2.3g certificada / F2.3h Docker ACTIVE + app_backend (22/10/2026)

**F2.3g aprobada por ejecución del usuario**: prueba `--probe=contabilidad-dev` con ADMIN demo activó y retiró la versión **solo dentro de transacción sin COMMIT**, rechazó AUDITOR, SHA-256 incorrecto, doble activación y doble retiro. Verificó dos eventos auditados; ROLLBACK dejó 3 DRAFT, 0 ACTIVE, 0 RETIRED, 0 asientos/auditorías. Preflight F2.3f nuevamente PASS con 454 rutas por cada empresa.

**F2.3h disponible, NO EJECUTADA:** nuevo wrapper Windows `backend/scripts/phase2-active-rls-docker.ps1` y runner `backend/scripts/phase2-active-rls-docker.php`. El primero genera PostgreSQL **18 efímero en Docker**, puerto aleatorio solo loopback, contraseñas aleatorias y marca `f23h_guard`. Aplica las 16 migraciones de esquema más las 3 migraciones portables F2.2/F2.3 (19), sin mover archivos ni copiar credenciales Neon. Comprueba cada migración registrada, ejecuta PHP y elimina el contenedor en `finally`.

El runner verifica el token/host/puerto/rol PostgreSQL local, introduce **dos empresas y usuarios ficticios** con cuentas PCGE jerárquicas de seis dígitos, 6xx, 94/95, 79 y contrapartida; centros CC-ADM/CC-VTA, monedas PEN/USD y matriz A `ACTIVE` / B `DRAFT` no confirmadas. En la **misma transacción** cambia de sesión superuser Docker a `SET LOCAL ROLE app_backend`, comprobando `current_user='app_backend'`, `rolbypassrls=false` y luego ejecuta HTTP Laravel con Sanctum y RLS real: POST 201 con 4 líneas analíticas 941100/791000 y 951100/791000, vínculos insertados por app_backend, GET separado por empresa, 403 auditor, nota de ajuste Haber 6, extorno exacto, auditoría y triggers diferidos. Termina ROLLBACK; el wrapper borra el Docker aun ante fallo. A diferencia de F2.3e, este ensayo verifica **ACTIVE + HTTP + RLS** sin activar la instancia Neon. Usa un conjunto mínimo de cuentas y **no sustituye** el preflight real de 227×2 rutas F2.3f.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-active-rls-docker.php
php scripts/phase2-active-rls-docker.php --plan
.\scripts\phase2-active-rls-docker.ps1
```

**Pendiente:** certificar el resultado del ensayo F2.3h en el equipo del usuario. La activación definitiva con credenciales humanas verificadas no está expuesta en CLI ni endpoint. GitHub Actions no está verde: los jobs pudieron crearse tras arreglar YAML, pero terminaron sin steps disponibles; investigar restricciones del runner/cuenta sin suponer cuál aplica.

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

## F2.3d HTTP certificada y F2.3e HTTP ACTIVE transitoria (22/10/2026)

**F2.3d — aprobada por ejecución del usuario con app_backend y Sanctum:** HTTP 201 POST USD/PEN con CC-ADM, auditoría y tipo de cambio; HTTP 422 para centro ausente o cruzado y descuadre; GET diario segmentado (A ve el suyo, B no ve A); HTTP 403 para auditor sin acceso; extorno HTTP 201, extorno repetido HTTP 422, triggers exactos bimoneda, y ROLLBACK con 0 asientos/partidas/links/periodos/TC/auditoria, 3 versiones DRAFT. El usuario volvió a verificar con 21 migraciones y 1362 rutas, sin ACTIVE.

**F2.3e — publicada pero NO ejecutada:** `backend/scripts/phase2-neon-analytic-http-active.php` ensaya el Kernel HTTP real, autenticación/roles y motor de distribución con **una matriz ACTIVE solo dentro de una transacción admin sin COMMIT**. Exige rol administrativo `neondb_owner`, host/DB de desarrollo, 21 migraciones, tres compañías/versions y baseline de cero asientos, periodos, TC y auditoría. En transacción: cambia versión de empresa A a ACTIVE, hace HTTP POST CC-ADM (941100/791000) y CC-VTA (951100/791000), ajuste Haber 6 (Debe 791000/Haber 951100), comprueba importes bimoneda y vínculos fuente/débito/crédito/regla, impide cuentas destino manuales con automático (HTTP 422), ejecuta extorno y valida link invertido, verificando triggers y eventos auditables. Finalmente ejecuta ROLLBACK y confirma que nunca quedó ACTIVE ni movimiento alguno.

**Límite de la certificación F2.3e:** usa sesión OWNER para que HTTP pueda leer dentro de la misma transacción la versión temporal ACTIVE. Ese rol elude RLS. La cobertura con rol real `app_backend` está certificada por las pruebas F2.3c y F2.3d sobre matrices DRAFT, **pero la combinación HTTP + ACTIVE + RLS no estará probada hasta una prueba adicional separada**. No corresponde habilitar producción basándose únicamente en este probe.

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-analytic-http-active.php
php scripts/phase2-neon-analytic-http-active.php --plan
php scripts/phase2-neon-analytic-http-active.php --probe=contabilidad-dev
php scripts/phase2-neon-analytic-allocations.php --verify
php scripts/phase2-neon-tenant-integrity.php --verify
```

Antes de permitir activar realmente, implementar promoción transaccional controlada con actor autorizado y auditoría inmutable, bloqueo concurrente por empresa, validaciones de cobertura y rechazo de rutas incompletas, mecanismo de retiro seguro y prueba integrada `app_backend` sobre ACTIVE sin exposición de datos a otros tenants. GitHub Actions requiere corrección independiente; no dar por verde ese CI.

## F2.3e aprobada / F2.3f preflight seguro de promoción (22/10/2026)

**Evidencia ejecutada por el usuario:** F2.3e PASS de principio a fin: cambio ACTIVE únicamente dentro de la transacción ADMIN no confirmada, HTTP 201 con ruta CC-ADM Debe 941100/Haber 791000, HTTP 201 ruta CC-VTA Debe 951100/Haber 791000, ajuste al Haber de 6xx con Debe 791000/Haber 951100, rechazo HTTP 422 de mezcla manual y automática, extorno exacto de 4 líneas, 4 links, 4 vouchers balanceados y auditoría, seguido de ROLLBACK a 3 DRAFT/0 ACTIVE, sin asientos, líneas, vínculos, períodos, tasas ni auditoría. Verificaciones posteriores F2.3b/tenant PASS, 21 migraciones y 1362 rutas.

**F2.3f — preflight publicado y pendiente de ejecución:** `backend/scripts/phase2-neon-analytic-promotion-preflight.php` acepta solo `--plan`, `--verify`, `--preflight=20999990011` (o empresa demo 12/13). Controles: host, rol y base Neon DEV PG18, 21 migraciones, exactamente 3 versiones DRAFT y 1362 rutas, integridad fuente 6xx y centro de costo activo, destinos exactos CC-ADM→941100/791000 y CC-VTA→951100/791000, 454 rutas para 227 fuentes sin huecos, SHA-256 canónico calculado desde cuentas ordenadas comparado contra la huella almacenada. Es **solo SELECT**, no promueve, retira ni audita cambios. Antes de permitir activar persiste la necesidad de promoción atómica y auditada autorizada, verificación ACTIVE+RLS combinada y recuperación de CI.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-analytic-promotion-preflight.php
php scripts/phase2-neon-analytic-promotion-preflight.php --plan
php scripts/phase2-neon-analytic-promotion-preflight.php --verify
php scripts/phase2-neon-analytic-promotion-preflight.php --preflight=20999990011
php scripts/phase2-neon-analytic-allocations.php --verify
```

**GitHub Actions:** el workflow YAML tenía un `:` no escapado en un nombre de step y era inválido; al entrecomillarlo GitHub reconoció el workflow y creó tres jobs reales. Sin embargo los runs posteriores muestran `failure` y algunos jobs terminan sin pasos ni logs disponibles desde el conector, otros quedaron en cola. **No considerar el CI aprobado**: requiere revisar el diagnóstico del runner/facturación/autorización de Actions sin asumir una causa.

## F2.3f PASS / F2.3g promoción auditada con rollback (22/10/2026)

**F2.3f confirmada por el usuario**: `--verify` y los tres `--preflight=RUC` PASS; cada empresa tiene exactamente 227 cuentas fuente, 2 centros activos, 454 rutas y SHA-256 idéntico al snapshot versionado; 3 matrices DRAFT, sin activar ni modificar datos.

**F2.3g — código publicado, prueba por ejecutar:** `backend/app/Services/Accounting/AnalyticMatrixPromotionService.php` implementa una transición `DRAFT → ACTIVE` o `ACTIVE → RETIRED` en transacción OWNER exclusiva de Neon DEV, con validación de un actor usuario ADMIN/CHIEF_ACCOUNTANT activo en la empresa, SHA-256 esperado, comparación del snapshot, integridad de 227×2 rutas, bloqueo de la fila de versión y advisory lock exclusivo por tenant (sin bloquear la fila de `companies`, para evitar deadlock con las FK de POST), UPDATE condicionado al estado anterior y un evento persistible de auditoría **en la misma transacción**. El servicio aún **no tiene ruta HTTP ni comando de activación permanente**; nada ha sido activado. Los retiros conservan la versión y sus enlaces históricos (no existe REACTIVATE de una versión RETIRED).

`AnalyticRoutingService::activeVersionId()` adquirirá un advisory lock compartido por empresa en cada POST; la promoción usa el mismo nombre de lock exclusivo. Así se evita publicar partidas contables mientras se cambia el estado de la regla. Se utiliza advisory lock porque el rol `app_backend` sólo puede hacer SELECT a la tabla de versiones y no tiene privilegio UPDATE necesario para `SELECT FOR SHARE` en PostgreSQL.

**F2.3g ensayo reversible, PENDIENTE de ejecutar:** `backend/scripts/phase2-neon-analytic-promotion-rollback.php` usa conexión OWNER y transacción externa sin COMMIT. Prueba actor AUDITOR rechazado, SHA esperado erróneo, DRAFT→ACTIVE, prohibición de segunda activación, ACTIVE→RETIRED, prohibición de segundo retiro, dos eventos auditados con usuario ADMIN; hace ROLLBACK y verifica por otra lectura 3 DRAFT, 0 ACTIVE/RETIRED, 0 asientos/auditoría. **No certifica el flujo HTTP ACTIVE+RLS simultáneo**, ni se autoriza activación persistente hasta superar pruebas.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l app/Services/Accounting/AnalyticMatrixPromotionService.php
php -l app/Services/Accounting/AnalyticRoutingService.php
php -l scripts/phase2-neon-analytic-promotion-rollback.php
php scripts/phase2-neon-analytic-promotion-rollback.php --plan
php scripts/phase2-neon-analytic-promotion-rollback.php --probe=contabilidad-dev
php scripts/phase2-neon-analytic-promotion-preflight.php --verify
php scripts/phase2-neon-analytic-allocations.php --verify
```

**CI:** al corregir el YAML, GitHub ya reconoce los jobs, pero los tres se marcaron failure y los jobs investigados no exponían ningún step ni log (posible dificultad de runner/configuración, causa no certificada). Estado CI: **no aprobado**.

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
