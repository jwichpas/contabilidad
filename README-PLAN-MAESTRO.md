> **NEON DEV CON DATOS DE PRUEBA PERMANENTES (21/10/2026):** usuario solicitó crear y conservar 3 empresas y 3 usuarios hasta depurar desarrollo. Ya insertados en Neon rama `contabilidad-dev` ID `br-odd-lake-b6wshqv3`: DEMO A Comercial Andina, DEMO B Servicios del Sur, DEMO C Distribuidora Pacífico (RUC ficticios `20999990011/12/13`), usuarios `demo.admin@contabilidad.test` (ADMIN A/B/C), `demo.contador@contabilidad.test` (ACCOUNTANT A/B, DATA_ENTRY C), `demo.auditor@contabilidad.test` (AUDITOR B/C). Contraseñas aleatorias con BCrypt en BD, entregadas en archivo separado; **no están en GitHub**. SELECT confirmó **3 empresas, 3 usuarios, 8 memberships, 17 migraciones, 0 tokens y asientos**. Docker faltaba en su entorno Linux, por lo que se publicó `backend/scripts/phase1-neon-concurrency-demo.php` (`--plan` sin SQL; `--apply=contabilidad-dev` persistirá período octubre, 8 cuentas PCGE y 2 asientos ficticios A bajo bloqueo real de dos sesiones); prueba **aún no ejecutada**. Probes anteriores requieren esquema vacío y no deben repetirse ahora. Se detectó `currencies.sunat_code VARCHAR(2)` incompatible con moneda ISO 4217 SUNAT de 3 letras, pendiente de migración contractual antes de configuración monetaria/tributaria de empresas. Neon production y Supabase intactos. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.14 — 2 CONEXIONES EN POSTGRESQL 18 DOCKER PREPARADAS (21/10/2026):** Tras F1.12/F1.13 PASS y verificación independiente de Neon `contabilidad-dev` (17 migraciones, cero filas de prueba, UNIQUE de correlativos), se añadió `backend/scripts/phase1-journal-concurrency-docker.php`: abre dos conexiones físicas `app_backend` y prueba `SELECT ... FOR UPDATE NOWAIT` bloqueado con SQLSTATE 55P03 mientras la primera posee lock del período; tras COMMIT, el segundo obtiene el lock y `JournalEntryNumberService` produce `10-00002` después de `10-00001`, con partida doble y constraint UNIQUE. Se integra al final del wrapper PowerShell existente `phase1-isolated-writes-docker.ps1`. Nuevo Bash nativo `phase1-journal-concurrency-docker.sh` permite ejecutar desde `~/contabilidad/backend`, instalando 16 migraciones solo en un nuevo Docker PostgreSQL 18 y destruyéndolo en trap. **F1.14 no ejecutada aún; pendiente PHP lint, bash -n y prueba real.** Neon/Supabase no reciben escrituras. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.12/13 PASS; F1.14 CONCURRENCIA DOCKER PREPARADA (21/10/2026):** usuario confirmó todos los escenarios de `phase1-neon-journal-number-rollback.php --probe=contabilidad-dev`: correlativos automáticos **10-00001, 10-00002**, GET preview no reservado, POST 201 balanceado, triggers diferidos válidos y negativos, 422 descuadrado, inmutabilidad 23514, extorno **10-00003**, original REVERSED, auditorías y 422 ante doble extorno; ROLLBACK limpio verificado con RLS activo. Neon SELECT posterior: PG18.6, 17 migraciones, cero empresas, usuarios, tokens, asientos, partidas ni auditorías; UNIQUE (company_id, fiscal_period_id, entry_number) vigente. Se creó `scripts/phase1-journal-concurrency-docker.php` para **DOS conexiones PostgreSQL físicas** y bloqueo real `FOR UPDATE NOWAIT` (**55P03**), luego asientos 10-00001/00002 COMMIT solo en Docker efímero, integrado al final de `phase1-isolated-writes-docker.ps1` antes de destruir el contenedor. **F1.14 aún no ejecutada**; usar PowerShell Docker local, nunca contra Neon. Neon production y Supabase intactos. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.10 + F1.11 PASS / F1.12–F1.13 IMPLEMENTADAS PENDIENTES (21/10/2026):** El usuario verificó en Neon dev 17 migraciones, autenticación Bearer real, expiración 12 h, cambio de empresa, logout/revocación y ROLLBACK sin datos. F1.11 validó 7 controles del asiento POST balanceado, triggers SQL diferidos buenos/malos, inmutabilidad y auditoría. Neon independiente confirmó 17 migraciones, ambas políticas company_user y 0 datos persistentes. Se corrigió numeración automática: desapareció `COUNT(*) + 1` anterior al lock, y `JournalEntryNumberService` genera `MAX(sufijo)+1` **dentro del FOR UPDATE del periodo**; GET next-number es preview no reservado. Se incorporó `POST /journal-entries/{entryId}/reverse` con roles contables, periodo OPEN bloqueado, fecha/empresa válida, correlativo seguro, inversión exacta y auditoría. Preparado `phase1-neon-journal-number-rollback.php` para probar 10-00001/00002, trigger, reversión 10-00003 y prohibición de doble extorno, más ROLLBACK; **pendiente de php -l y ejecución real**. La prueba prevista es secuencial, aún NO certifica dos sesiones concurrentes. `main` solamente, Neon production y Supabase intactos. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.11 PASS / F1.10 POLÍTICA RLS FALTANTE (21/10/2026):** F1.11 pasó 7 pruebas con POST HTTP 201, Debe/Haber exactos, trigger SQL diferido bueno/malo (23514), HTTP 422, inmutabilidad SQL, auditoría y ROLLBACK de todas las fixturas en una segunda verificación READ ONLY con tenant activo. F1.10 pasó login real HTTP 200 pero falló inmediatamente después (y confirmó ROLLBACK). Neon SELECT demostró que **NO existe** `company_user_authenticated_member_listing_policy` requerida para listar empresas en login con `app.current_user_id`; estaba solo en una migración Supabase-only excluida. Publicadas migración limitada `backend/database/neon_migrations/2026_10_21_030000_allow_authenticated_company_membership_listing.php` y herramienta `backend/scripts/phase1-neon-membership-policy.php` (`--plan` sin SQL, `--verify` solo SELECT, `--apply=contabilidad-dev` explícito solo admin neondb_owner en host exacto, conserva policy tenant y lleva 16→17 migraciones). F1.10 ahora identifica etapa de error sin secretos y bloquea pruebas si falta la policy. **No se ejecutó reparación remota**; próximo paso lint/plan/verify, aplicación local dirigida y F1.10 retry. PG18.6 dev sigue con 0 empresas/usuarios/tokens/asientos/auditoría, 16 migraciones; Supabase y Neon production intactos. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.10–F1.11 — PRUEBAS ENDURECIDAS Y AÚN PENDIENTES (21/10/2026):** F1.10 Bearer real, token de 12 h, cambio de empresa y revocación mantiene runner de transacción/rollback. F1.11 ahora exige **7 checks**: HTTP 201, Debe/Haber exactos, trigger diferido válido forzado, HTTP 422 descuadrado, UPDATE inmutable 23514, **SQL directo descuadrado rechazado por constraint diferida 23514 con SAVEPOINT**, y auditoría del asiento. Verificación posterior al ROLLBACK ahora utiliza una **nueva transacción READ ONLY CON GUC TENANT**, no conteos RLS sin contexto; comprueba 0 cabeceras, partidas, cuentas, periodos, membresías, auditoría, empresas y usuarios. Neon SELECT confirmó permisos tokens del rol app_backend sin DELETE contable, PostgreSQL 18.6, 16 migraciones y cero datos de negocio persistentes antes de pruebas. **No se han ejecutado F1.10 ni F1.11 remotamente**, requieren lint/plan y prueba secuencial en entorno local de usuario. No modificar Neon production ni Supabase. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.10–F1.11 — REVISIÓN CONTINUADA (21/10/2026):** se revisaron login/logout Sanctum y servicio POST. Neon PG18.6 `contabilidad-dev`: app_backend con permisos de tokens, sin DELETE sobre asientos/auditoría; `personal_access_tokens.expires_at` existe. F1.11 añade prueba **negativa SQL independiente del PHP**: asiento descuadrado temporal dentro de savepoint, `SET CONSTRAINTS ALL IMMEDIATE` debe rechazar con 23514, `ROLLBACK TO SAVEPOINT` y comprobación de fila inexistente; 7 verificaciones en lugar de 6. F1.10 y F1.11 siguen **sin resultados locales**, requieren `php -l`, `--plan` y ejecutar `--probe=contabilidad-dev` de forma secuencial. Último SELECT de Neon: 0 empresas, usuarios, tokens, asientos, partidas y auditorías; 16 migraciones. No se cambió Neon production ni Supabase. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.9 COMPLETADA / F1.10 + F1.11 PREPARADAS (21/10/2026):** usuario confirmó 8/8 HTTP PASS y ROLLBACK en Neon `contabilidad-dev`. Se añadió F1.10 `phase1-neon-token-rollback.php` para validar Bearer Sanctum real, vencimiento 12h, lista/cambio de empresa y logout/revocación. Se añadió F1.11 `phase1-neon-journal-rollback.php` para POST 201 balanceado, 422 descuadrado, 2 triggers diferidos forzados con `SET CONSTRAINTS ALL IMMEDIATE`, inmutabilidad SQL 23514, evento auditoría y ROLLBACK; ambas pruebas con `--plan` sin SQL, `--probe=contabilidad-dev` opt-in. **Ninguna ha sido ejecutada aún por el usuario**. Neon SELECT independiente: PG18.6, 16 migraciones, 0 empresas, usuarios, tokens, asientos, partidas y auditorías; 2 triggers diferidos registrados. Corregido comentario falso REPEATABLE READ en servicio POST: actualmente usa aislamiento SQL por defecto; concurrencia pendiente. Siguiente paso: lint + plan de ambos, ejecutar F1.10 y analizar PASS antes de permitir F1.11. Supabase y Neon production sin cambios. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.9 APROBADA / F1.10 LISTA PARA VALIDACIÓN (21/10/2026):** usuario ejecutó en Neon dev 8 pruebas HTTP 401/403/422/200 con Sanctum simulado; todas PASS y ROLLBACK confirmado. SELECT independiente mostró 0 empresas, usuarios, tokens y eventos de auditoría; 16 migraciones intactas. Nuevo script `backend/scripts/phase1-neon-token-rollback.php` para probar login real, Bearer, vencimiento 12h, consulta/cambio de empresa, logout y token revocado, con `--plan` sin SQL y `--probe=contabilidad-dev` en transacción ROLLBACK. Código publicado pero **prueba local aún no ejecutada**. PostgreSQL sequences pueden avanzar pese a ROLLBACK. No se tocó Neon production ni Supabase. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **NEON F1.8 — ESQUEMA PG18 INSTALADO Y REVISADO (21/10/2026):** usuario confirmó bootstrap `--apply=contabilidad-dev` **16/16 PASS**. Neon SELECT directo verificó **PG18.6, 31 tablas public, 16 filas migrations, 13 FORCE RLS, 5 funciones search_path**, app_backend limitado y audit_logs sin exposición global. Se añadieron `backend/scripts/phase1-neon-app-role-readonly.php` (prueba del login real app_backend con SELECT/BEGIN READ ONLY/ROLLBACK) y `backend/scripts/phase1-neon-rls-rollback.php` (plan sin SQL; `--probe=contabilidad-dev` opt-in con INSERT/UPDATE/DELETE ficticios en transacción ROLLBACK y verificación de limpieza). Se añadieron lints CI; **todavía no hay resultado local de estas dos herramientas**. No ejecutar pruebas HTTP Pest directamente en Neon; Docker PG18 ya pasó 12 tests/37 aserciones. No se modificó Supabase ni Neon production. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **NEON F1.7 — LISTO PARA BOOTSTRAP LOCAL EXPLÍCITO (21/10/2026):** usuario confirmó DB_PASSWORD sin duplicados, preflight PG18 remoto --read-only --require-empty PASS, PHP lint PASS e inventario 16 migraciones portables PASS. Read-only Neon adicional confirmó CREATEROLE de neondb_owner, extensión pgcrypto disponible, esquema dev vacío y sin app_backend. Se endureció el inicializador: host exacto de contabilidad-dev, privilegios admin, inventario cerrado, 13 tablas con FORCE RLS, 16 migraciones registradas, cinco funciones search_path, política audit_logs y rol app_backend sin neon_superuser. F1.4 también pinnea host exacto. **No se ejecutó DDL**. Siguiente ejecución con claves locales: `php scripts/phase1-neon-schema-bootstrap.php --apply=contabilidad-dev` exclusivamente tras nuevo --plan y --read-only PASS. No usar migrate general ni migrate:fresh. Supabase y Neon production intactos. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.7 — NEON PG18 EN LECTURA APROBADO, BOOTSTRAP PREPARADO (21/10/2026):** Usuario obtuvo preflight sin SQL PASS y preflight `--read-only --require-empty` PASS contra Neon `contabilidad-dev` (PG18, neondb, 0 tablas, rol app_backend inexistente). Neon conectado confirmó por SELECT PostgreSQL 18.6 y estado ready. APP_KEY válida y contraseña del rol coincide con la última DB_PASSWORD leída, pero el diagnóstico detectó **DB_PASSWORD duplicado** en .env: corregir a una sola definición activa antes de cualquier DDL. Preflight ahora **bloquea claves duplicadas**. Publicado `backend/scripts/phase1-neon-schema-bootstrap.php`: `--plan` sin SQL, con inventario exacto 16 migraciones PostgreSQL portables / 5 Supabase-only excluidas; `--apply=contabilidad-dev` explícito con guardas de rama, base vacía y verificación de roles/search_path/política de auditoría. **Modo apply NO ejecutado; lint y plan locales pendientes**. Neon production y Supabase intactos. [Guía F1.7](docs/NEON-DESARROLLO-SEGURO.md).

> **NEON F1.6 — CAUSA DEMOSTRADA POR DIAGNÓSTICO (21/10/2026):** el proceso PHP tiene `getenv` y `$_SERVER` VACÍOS para APP_ENV, DB_* y credenciales **ANTES de iniciar Laravel**, mientras el .env privado sí contiene parámetros de contabilidad-dev y las contraseñas de conexión. El limpiador anterior informó PASS falso; fue corregido a `Remove-Item Env:` con verificación de ausencia en PowerShell y **un proceso PHP hijo independiente**. El preflight bloquea `APP_KEY` inválida (detectada en origen) y la contraseña vacía de `DB_APP_BACKEND_ROLE_PASSWORD`. Próxima puerta: ejecutar limpiador corregido, `phase1-neon-env-origins.php`, preflight sin SQL y completar clave/credencial privadas. **Sin migraciones remotas ni modificaciones Supabase/Neon production.** [Evidencia y procedimiento](docs/NEON-DESARROLLO-SEGURO.md).

> **NEON F1.6 — ORIGEN DE VARIABLES PENDIENTE DE DIAGNOSTICAR (21/10/2026):** se limpiaron correctamente 20 variables vacías de PowerShell, pero el preflight antiguo volvió a llamarlas «heredadas» al consultar `getenv()` **después** del bootstrap Laravel. Esa interpretación era incorrecta; no se ha demostrado origen PowerShell. Se añadió `backend/scripts/phase1-neon-env-origins.php` para comparar de forma segura proceso/`$_ENV`/`$_SERVER` **antes** de Laravel, presencia de claves `.env`, y `env()`/config después. El preflight fue corregido para inspeccionar solo fuentes anteriores al bootstrap y dejar de bloquear URLs intencionalmente vacías. **Pendiente ejecutar el nuevo diagnóstico**, completar contraseñas locales y corregir APP_KEY inválida; no hay DDL/DML en Neon ni Supabase. [Detalle](docs/NEON-DESARROLLO-SEGURO.md).

> **NEON F1.6 — BLOQUEO DE VARIABLES VACÍAS HEREDADAS (21/10/2026):** el archivo `backend/.env` del usuario ya contiene los hosts correctos de Neon `contabilidad-dev`, pero Laravel recibe `APP_ENV` y parámetros `DB_*` vacíos; el preflight detectó esos nombres presentes en el entorno del proceso PowerShell. Publicado `backend/scripts/phase1-neon-clear-empty-process-env.ps1`, que muestra solo NOMBRES en dry-run y con `-Apply` borra solo variables heredadas vacías de la sesión actual, sin modificar `.env` ni contraseñas. Preflight PHP ahora bloquea explícitamente estos overrides vacíos. Aún faltan contraseñas privadas de `neondb_owner` y `app_backend`; `APP_KEY=base64:x` debe corregirse si es literal. Neon `contabilidad-dev` no recibió migraciones, Neon `production` y Supabase intactos. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **NEON F1.6 — .ENV MIXTO Y CONFIG LARAVEL POR DIAGNOSTICAR (21/10/2026):** el segundo preflight local fallo antes de SQL. La evidencia del .env muestra conexion Laravel activa **a Supabase**, conexion admin Neon al endpoint **production** y `NEON_TEST_BRANCH_HOST=HOST_DIRECTO_DE_LA_RAMA`, mientras preflight imprimio valores de configuracion DB vacios aun existiendo algunos valores en el archivo. `phase1-neon-env-repair.ps1` fue ampliado para corregir SOLO valores antiguos conocidos/no secretos a los endpoints verificados de `contabilidad-dev`, con dry-run, backup y sin cambiar passwords/otros datos. `phase1-neon-preflight.php` ahora indica si config/database.php se cargo, si env() ve los hosts y nombres de variables heredadas que pueden prevalecer sobre el .env; exige password de app_backend y coherencia de secretos. **No se ejecuto SQL ni se modificaron Neon production/Supabase**. Ejecutar preflight tras reparar y configurar credenciales privadas; mantener bloqueo de migraciones. [Guia](docs/NEON-DESARROLLO-SEGURO.md).

> **NEON F1.6 — PREFLIGHT LOCAL DETENIDO, REPARACIÓN PREPARADA (21/10/2026):** el usuario confirmó que `backend/.env` existe y contiene `NEON_TEST_BRANCH_NAME=contabilidad-dev`, pero `APP_ENV` y todas las variables `DB_*` relevantes llegaron vacías a Laravel. No hubo conexiones Neon/SQL/DML. Se añadió `backend/scripts/phase1-neon-env-repair.ps1`: **dry run por defecto**, `-Apply` respalda .env y rellena solo valores no secretos faltantes/vacíos desde la plantilla verificada de Neon PG18 contabilidad-dev, sin sobrescribir contraseñas ni valores existentes. Pendiente completar contraseña administrativa y rol restringido privado, después pasar preflight sin SQL y `--read-only --require-empty`. **No ejecutar migraciones aún**. Supabase intacto. [Guía](docs/NEON-DESARROLLO-SEGURO.md).

> **NEON F1.6 PREPARADO (21/10/2026):** nuevo entorno objetivo de pruebas remotas **Neon PostgreSQL 18**; Supabase Dev se preserva sin cambios. La captura Neon corresponde a rama `production`, que NO debe usarse para bootstrap ni pruebas de escritura. Se añadió `backend/scripts/phase1-neon-preflight.php`: configuración local sin SQL por defecto, o `--read-only` para identidad/versión y `--require-empty` para verificar base nueva vacía; bloquea endpoint production de la captura y requiere host de rama de pruebas. Migración inicial exige secreto `app_backend` fuerte, sin password por defecto. F1.4 ya admite Neon solo con guard de rama distinta de production, SSL y propietario `neondb_owner`, manteniendo controles previos Supabase/CI. **No se conectó a Neon ni se ejecutaron migraciones remotas.** [Guía de integración](docs/NEON-DESARROLLO-SEGURO.md).

> **F1.5 — CONTINUACIÓN (21/10/2026):** PostgreSQL 18.6 Docker F1.4 **12/12 PASS, 37 aserciones**. Supabase Dev PG17.11 sigue sin migraciones F1.4: preflight local mostró campos `DB_MIGRATIONS_*` vacíos; se mejoró el diagnóstico sin SQL para detectar `.env`, ruta de conexión y contraseña (solo estado, nunca valor). GitHub Actions falla **antes de iniciar jobs**: [corrida #39678580152](https://github.com/jwichpas/contabilidad/actions/runs/39678580152) reporta `failure` y **0 jobs**, causa aún no comprobada; no atribuir fallos a PostgreSQL 18. Próximo: completar configuración administrativa local sin compartir secretos, validar la conexión y revisar el aviso de Actions. [Arquitectura PG18](docs/POSTGRESQL-18-ARQUITECTURA.md).

> **PLATAFORMA OBJETIVO — POSTGRESQL 18 (21/10/2026):** el usuario comprobó **PostgreSQL 18.6 Docker F1.4 PASS** (12 pruebas, 37 aserciones, 0 fallos; migraciones y RLS aislados). Se decidió **PostgreSQL 18 como motor de referencia de todo el ERP**: el runner Docker usa PG18 por defecto y ambos jobs PostgreSQL de GitHub Actions usan imagen PG18 con comprobación de versión real. **GitHub Actions sigue sin PASS verificable**. Supabase `contabilidad-database` se conserva **solo para desarrollo**, en PostgreSQL **17.11**, y **no se ha actualizado** a PG18. Su migración F1.4 continúa pendiente por configuración administrativa local inválida `sslmode=connect_timeout=30` / variables administrativas vacías. Se actualizó `.env.example` para indicar el alcance Dev. **No migrar a Dev hasta corregir conexión.** Una futura instancia PostgreSQL 18 de producción será independiente del motor administrado de Supabase. [Decisión y pasos](docs/POSTGRESQL-18-ARQUITECTURA.md).

> **F1.4 — DOCKER PASS (21/10/2026):** PostgreSQL 17 efímero confirmó migraciones seleccionadas, `search_path=pg_catalog` en 5 funciones, RLS de auditoría global/A/B y 12/12 pruebas (37 aserciones) con JUnit sin fallos y eliminación del contenedor. **La corrección aún NO se ha aplicado a Supabase Dev**. Auditoría remota posterior de solo lectura: **0/5 funciones endurecidas, 0/2 migraciones registradas, 7 eventos globales preservados y política de auditoría antigua aún vigente**. Próxima puerta: ejecutar *únicamente* las dos migraciones `2026_10_21_010000` y `2026_10_21_020000` vía `pgsql_migrations` de Laravel 13 en el Windows del usuario; verificar DONE, el estado real de `public.migrations`, los avisos del advisor y los 7 eventos. No hacer migraciones masivas ni tocar producción. [Procedimiento](docs/FASE-1-VALIDACION-SUPABASE-DEV.md).

> **F1.4 — ENSAYO DOCKER DETUVO MIGRACIONES (21/10/2026):** la primera ejecución reportó FAIL de `2026_10_19_010000_add_company_economic_profile` (guard de Supabase) y de búsqueda `search_path` (proconfig 0/5), además de mostrar que Artisan podía imprimir FAIL sin detener el loop. Cambios publicados: el ensayo Docker/CI excluye **5** migraciones dependientes del proyecto Supabase; las cinco funciones fijan `search_path=pg_catalog` y usan verificación determinista; el runner exige una fila en `migrations` por cada archivo y CI cuenta migraciones ejecutadas. **F1.4 NO aprobado** hasta nueva ejecución Docker. Supabase Dev NO recibió estas migraciones. [Detalle](docs/FASE-1-VALIDACION-SUPABASE-DEV.md).

> **F1.3 PASS / F1.4 EN PREPARACIÓN (21/10/2026):** el usuario ejecutó PostgreSQL 17 aislado en Docker: **12/12 pruebas, 37 aserciones y 0 fallos**, con `[PASS] Pest JUnit` y limpieza del contenedor. Los controles de escritura multiempresa se validaron antes de Pest. Sumado a las pruebas de lectura/guard anteriores son **18 pruebas y 95 aserciones PASS** en dos entornos, no una única suite. Auditoría SELECT sobre Supabase Dev detectó **5 funciones `search_path` mutable** (advisor confirmado) y política `audit_logs` que permite lectura de **7 eventos globales** sin empresa; no se leyeron sus contenidos. Se prepararon **dos migraciones Laravel de endurecimiento** y regresiones en Docker para fijar `search_path` y cerrar lectura global, **todavía NO ejecutadas en Supabase Dev ni verificadas por el nuevo Docker**. Próxima puerta: repetir `backend/scripts/phase1-isolated-writes-docker.ps1`, revisar PASS antes de cualquier migración Dev. CI GitHub Actions pendiente por fallo de runners sin logs. [Evidencia](docs/FASE-1-VALIDACION-SUPABASE-DEV.md).

> **F1.3 — PRUEBA AISLADA DETECTÓ FALLO (21/10/2026):** Docker/PostgreSQL 17 ejecutó SQL RLS y llegó a Pest; **11/12 tests HTTP PASS, 1 FAIL**, por recibir `422` cuando una empresa desactivada debía devolver `403`. El script emitió un `[PASS]` falso; **no considerar aprobado el ciclo**. Se corrigió el test para actualizar `companies.is_active = FALSE` por Query Builder y comprobar SQL real antes de llamar al middleware. El runner y CI ahora exigen reporte JUnit con **12 tests y 0 fallos** antes del PASS. **Pendiente repetir prueba Docker y confirmar resultado.** [Evidencia](docs/FASE-1-VALIDACION-SUPABASE-DEV.md).

> **F1.3 — SIGUIENTE PUERTA PREPARADA (21/10/2026):** GitHub Actions registró corridas fallidas sin pasos/logs verificables ([#43](https://github.com/jwichpas/contabilidad/actions/runs/39664475037)); **no se atribuye PASS ni un fallo de código**. Se corrigieron fixtures HTTP de CI (orden de FK y booleanos) y se agregó el ensayo de `INSERT/UPDATE/DELETE` interempresa sobre la tabla ERP real `company_areas`, siempre en PG17 `contabilidad_ci` y con ROLLBACK. Workflow excluye temporalmente sus **4 migraciones exclusivas de Supabase** del esquema efímero. Alternativa para ejecutar sin Actions: `backend/scripts/phase1-isolated-writes-docker.ps1`, con Docker local, token aleatorio para verificar el destino de conexión y sin mover archivos del proyecto. **Todo este trabajo está publicado, pero las pruebas DML aisladas aún NO tienen PASS de ejecución.** [Evidencias y uso](docs/FASE-1-VALIDACION-SUPABASE-DEV.md).

> **CAMBIO DE PLATAFORMA — PREPARACIÓN PHP 8.5 (21/10/2026):** por decisión del usuario, se **pausan nuevas pruebas F1** hasta actualizar el backend. Objetivo **PHP 8.5.x + Laravel 13.x + Pest 4**. Laravel 11 actual no admite oficialmente PHP 8.5 y ya salió de soporte de seguridad. Se agregó [plan de actualización Windows/Composer](docs/ACTUALIZACION-PHP-85-LARAVEL-13.md) y [preflight de PHP 8.5 solo lectura](backend/scripts/phase-php85-preflight.ps1). **El usuario ya instaló PHP 8.5.12, Laravel 13.38.0 y Pest 4.7.8 EN WINDOWS:** arranque Artisan PASS, 29 rutas registradas, 4/4 tests HTTP de lectura (40/40 aserciones) PASS y Composer audit del nuevo lock sin avisos. **composer.json y composer.lock están publicados juntos en main** (commit `be67509`). El usuario validó también la autorización por roles sobre empresas A/B: 2 tests y 18 aserciones PASS, total de suites F1 sin escrituras **6 tests/58 aserciones PASS** con Laravel 13. El workflow F1 fue actualizado a PHP 8.5, con revisión del lock y exclusión de la migración de datos ficticios del CI efímero; **ejecución CI pendiente de evidencia**. **No se modificó Supabase durante esta actualización.**

> **F1.3 — HTTP DE LECTURA PASS (21/10/2026):** suite real `ExistingCompaniesHttpReadOnlyTest.php`: **4 pruebas / 40 aserciones PASS (19,00 s)** en Supabase Dev. Incluye separación A/B de cuentas y asientos, denegación de GET anónimo (401), IDs de empresa inválidos (400), empresa sin acceso (403), secuencia A→B→A y limpieza de contexto RLS/caché. [Evidencia](docs/FASE-1-VALIDACION-SUPABASE-DEV.md). **Pendiente** autorización por roles, escritura cruzada y endurecimiento restante. Se prepara `ExistingCompaniesRoleGuardReadOnlyTest.php` (middleware directo y controlador simulado, **sin escrituras**, aún sin ejecución).

> **F1.2 CONFIRMADA EN DESARROLLO (21/10/2026):** el usuario aplicó con éxito la migración Laravel de empresa ficticia B (ID 182, batch 10, 9 s). Comprobación read-only `app_backend`: empresa A (ID 1) y B (ID 182) tuvieron **0 filas ajenas** visibles en **14 tablas FORCE RLS**; conteos de filas visibles 1.842/1.776, con ROLLBACK. SELECT de verificación: cada empresa tiene **1.764 cuentas**, B tiene 1 membresía, configuración, 2 monedas y actividad; actividad de A no configurada. **No equivale a cierre F1**: quedan autorización HTTP, escritura cruzada, auditorías globales y funciones `search_path`. [Evidencia F1](docs/FASE-1-VALIDACION-SUPABASE-DEV.md).

> **AVANCE F1 (20/10/2026) — SEGURIDAD A NIVEL DE CÓDIGO, CI SIN CERTIFICAR:** Implementado `RequireCompanyRole` en 7 rutas de escritura (cuentas, asientos, parámetros, tipos de cambio, inicialización), con consulta de membresía activa y roles por empresa; `TenantContext` cambió de `singleton` a `scoped` y restaura el `SET LOCAL` previo en transacciones anidadas. Añadidas pruebas HTTP sobre dos empresas ficticias, revocación, headers malformados, usuarios sin permisos, auditoría de solo lectura y rollback; workflow F1 usa PostgreSQL 17 efímero y ejecuta estas pruebas como `app_backend` limitado. **No afirmar F1 aprobada hasta disponer de resultados verificables de GitHub Actions.** [Matriz roles](docs/FASE-1-AUTORIZACION-ROLES.md) · [F1](docs/FASE-1-INICIO-SEGURIDAD.md).

> **ACTUALIZACIÓN 20/10/2026 — RESTAURACIÓN F0 PASS; CIERRE CONDICIONADO.** El usuario ejecutó exitosamente el ensayo PostgreSQL 17 aislado: SHA256, restauración `public`, 7 conteos, 14 tablas FORCE RLS y 5 funciones; Docker efímero eliminado. El volumen C: está **descifrado (0%)**. Por decisión expresa del usuario, el cifrado individual de respaldos se difiere al paso a producción. **Fase 1 puede iniciarse en desarrollo**, pero F0 no se considera apta para producción: persisten rotación de credencial expuesta, 5 avisos `search_path`, permisos adicionales, recuperación integral fuera de `public` y protección de respaldos. Véase [README de cierre condicionado](docs/README-FASE-0-CIERRE-Y-PRODUCCION.md). Las notas históricas siguientes reflejan estados anteriores al ensayo exitoso.

# Plan maestro, hoja de ruta e hitos — Sistema Contable

> **Documento rector del proyecto** | Repositorio: `jwichpas/contabilidad` | Rama: `main` | Versión: **1.1** | Fecha de registro: **2026-10-19**, ampliación F7: **2026-10-20**  
> **Estado global: EN EJECUCIÓN — FASE 0 EN PROGRESO (no aprobada).**  
> **F0 — SIMULACRO POSTGRESQL 17 INICIADO, AÚN NO APROBADO (20/10/2026):** Docker `linux`, SHA-256 de copia `public` PASS, contenedor sin red/puertos creado y eliminado. `pg_restore` falló al intentar `CREATE SCHEMA public;` cuando ese esquema ya existía por defecto en la base nueva. Se corrigió el script de restauración en `main` con **`DROP SCHEMA public RESTRICT` solo en el Docker efímero**, sin CASCADE (commit `86d1688`); **reintento pendiente** y Supabase intacto. `Get-BitLockerVolume` no pudo confirmar cifrado de `C:\CONTA`: verificar en Windows con `manage-bde -status C:` en terminal elevada. **Fase 0 EN PROGRESO**, sin restauración validada ni DDL aplicado.  \n\n> **F0 — PRIMER RESPALDO LÓGICO GENERADO EN WINDOWS (20/10/2026):** el usuario ejecutó el script `phase0-backup-windows.ps1` y reportó **PASS** de exportación `public` mediante PostgreSQL 17, inventario, SHA-256 y manifiesto; copia local `C:\CONTA\contabilidad-public-20261020T210205261Z.dump`, SHA-256 `8896DCC5F0B10279F95D6C8F55E32BD9278F217676C7953E7D2E081E617E423E`. **No existe aún evidencia de restauración**; el cifrado real/ACL del destino `C:` no se ha verificado (flag de confirmación manual). Próximo paso: restauración Docker PostgreSQL 17 aislado, sin conexión al origen. La Fase 0 sigue **EN PROGRESO**. [Plan](docs/FASE-0-PLAN-RECUPERACION.md) · [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  \n\n> **F0 — RESPALDO Y RESTAURACIÓN AUTORIZADOS, EJECUCIÓN LOCAL PENDIENTE (2026-10-20):** Supabase continuará **solo como base de desarrollo**; el usuario decidió **PostgreSQL independiente para producción** manteniendo Laravel/Vue/RLS/Sanctum. Autorizó generar respaldo lógico cifrado fuera de Git y ensayar restauración en un PostgreSQL 17 aislado. Preparados en `main`: [respaldo Windows `pg_dump`](backend/scripts/phase0-backup-windows.ps1), [restauración Docker sin red](backend/scripts/phase0-restore-rehearsal.ps1), [preflight](backend/scripts/phase0-backup-preflight.ps1), [plan de recuperación](docs/FASE-0-PLAN-RECUPERACION.md) y [política de portabilidad](docs/PORTABILIDAD-POSTGRESQL-PRODUCCION.md). **No se han ejecutado los scripts desde este chat ni confirmado backup/restore.** Requieren carpeta cifrada externa, PostgreSQL client 17 y Docker en PC Windows. **No declarar F0 cerrada ni aplicar DDL al origen** antes de evidencia y controles finales.  \n\n
> **F0 20/10/2026 — SEGURIDAD/RECUPERACIÓN: hallazgo crítico de preparación:** Supabase confirmó organización `grupo ichpas` en **plan Free**, proyecto contabilidad-database PostgreSQL 17.11 y base ~17 MB; **no se dispone de garantía de backups diarios administrados ni existe backup/restauración comprobado**. Se documentó un [preflight Windows no destructivo](backend/scripts/phase0-backup-preflight.ps1), actualizado el [plan de recuperación](docs/FASE-0-PLAN-RECUPERACION.md) y preparada una [propuesta `search_path = ''` para cinco funciones (NO APLICADA)](docs/FASE-0-PROPUESTA-SEARCH-PATH.md). Security Advisor confirmó **5 avisos de funciones** y **1 aviso separado de Supabase Auth sobre contraseñas filtradas**; el ERP usa Laravel Sanctum. Inspección SELECT: 14 tablas con RLS forzado y 18 globales sin RLS pero sin permisos SELECT para `anon`/`authenticated`; funciones SECURITY INVOKER y sin CREATE para `app_backend` en public. **Ningún DDL, backup, restauración ni alteración de datos ejecutados.** La Fase 0 sigue **EN PROGRESO** hasta verificar copia recuperable y remediación controlada. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  \n\n> **F0 — RECORRIDO FUNCIONAL EN NAVEGADOR APROBADO (2026-10-20):** el usuario confirma **login correcto**, **selección de empresa**, **consulta del Plan de Cuentas** y **apertura del editor de asientos sin errores** contra Laravel local. Se cierra la validación manual del flujo principal de navegación/lectura y la incidencia visible de carga de cuentas (SQLSTATE 42883), respaldada por pruebas HTTP **7/7 (29 aserciones)** y frontend **16/16**, typecheck/build PASS. **No significa haber contabilizado asientos ni validado todas las rutas/roles**. Fase 0 sigue EN PROGRESO por recuperación/restauración aislada no verificada, revisión de 5 funciones PostgreSQL y permisos/casos de seguridad restantes. Ningún DDL ni respaldo/restauración ejecutados. [Evidencia](docs/FASE-0-DIAGNOSTICO.md).  \n\n> **F0 — SQL PostgreSQL boolean/integer verificado (2026-10-20):** el usuario confirmó `ReadOnlyTenantApiSmokeTest.php` **7/7 pruebas y 29/29 aserciones aprobadas (19,56 s)**; `php -l` pasó en controlador de cuentas operativas, modelo y pruebas. Se validó por HTTP el listado de cuentas, árbol activo, catálogos y aislamiento del usuario tras corregir 29 predicados SQL booleanos en 19 archivos (commit `22702cf`). Supabase mantiene **1 empresa, 1 usuario, 1 membresía, 1.764 cuentas, 2 asientos, 4 partidas, 18 migraciones**; conteos actuales de tokens=29 y auditorías=40 pueden cambiar con autenticaciones. **Pendiente prueba visual en Libro Diario y alta ERP del nuevo usuario (rol por confirmar).** [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md) · [Detalles](docs/FASE-0-SQL-BOOLEAN-FIX.md). Fase 0 sigue en progreso.  \n
> **F0 — Alta usuario ERP en preparación (20/10/2026):** el usuario autorizó preparar la incorporación de `elcontador@gmail.com` en Laravel (cuenta presente en Supabase Auth pero ausente en `public.users`). SQL SELECT confirmó empresa activa ID 1, 1 usuario y 1 membresía `CHIEF_ACCOUNTANT`; el alta propuesta es +1 usuario y +1 membresía, con rol inicialmente recomendado `ACCOUNTANT`. El script [`backend/scripts/phase0-provision-erp-user.php`](backend/scripts/phase0-provision-erp-user.php) tiene preflight **solo lectura** por defecto, contexto RLS transaccional y modo de escritura protegido que **NO se ejecutó**. Se requiere confirmar rol y nombre, y validar preflight/sintaxis local antes de usar `--apply`. [Procedimiento](docs/FASE-0-ALTA-USUARIO-ERP.md). **Ninguna escritura, seeder, migración ni cambio de contraseña realizado.**  \n\n> **INCIDENCIA F0 (2026-10-20) — HTTP 500 en login local:** con Laravel iniciado en puerto **8010** y Vite en **5180**, el POST /auth/login ahora responde 500, distinto del rechazo 422 de credenciales observado antes. Diagnóstico SQL de solo lectura confirma que `public.cache` y `public.cache_locks` **no existen**; `routes/api.php` usa `throttle:10,1` y Laravel 11 puede usar cache `database` por defecto. La ausencia de tabla cache es **causa probable condicional**, pendiente confirmar `CACHE_STORE` y excepción local. `artisan tinker` no existe (no instalado) y no se requiere. Se actualizó `backend/.env.example` para sugerir `CACHE_STORE=file` en desarrollo, se creó el diagnóstico solo lectura `scripts/phase0-login-diagnostics.php` para inspeccionar cache/correo sin contraseñas y se corrigió el mensaje Vue para no confundir HTTP 500 con credenciales inválidas. **Código pendiente de ejecutar/probar; ninguna migración, edición de datos ni reset de usuario.** [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **ESTADO F0 VIGENTE — FRONTEND VALIDADO 2026-10-20:** el usuario ejecutó `npm run test:unit` (**16/16 PASS**), `npm run typecheck` (**PASS**) y `npm run build` (**PASS**, **1.615 módulos**, **2,66 s**). Quedan aprobadas las pruebas unitarias de HTTP 401, estado de autenticación, seguridad del formulario, asistente de empresas y cálculos decimales, además de los chequeos de tipos y build; **no equivale a validación visual end-to-end ni despliegue**. Supabase revisado en modo `SELECT`: 1 empresa, 1 usuario, 1 membresía, 28 tokens, 34 auditorías, 2 asientos, 4 líneas y 18 migraciones intactos. Las 5 funciones con aviso de `search_path` mutable están inventariadas en [Auditoría de funciones](docs/FASE-0-AUDITORIA-FUNCIONES.md); **no se ejecutaron cambios DDL**. Plan de recuperación [documentado, sin respaldo generado](docs/FASE-0-PLAN-RECUPERACION.md). **F0 EN PROGRESO:** navegador, recuperación y revisión SQL.  \n\n> **F0 — 2026-10-20: frontend 401 y continuidad (código pendiente de test):** se integró la invalidación central de Bearer vencidos (HTTP 401) en `useAuthSession` y `AccountingApiClient`, se impide mostrar datos de una sesión almacenada antes de validarla, y `LoginView` ya no contiene credenciales demostrativas precargadas. El estado de vencimiento informa el límite de 12 h y no confunde 403 ni fallos de red con cierre de sesión; los borradores de alta quedan vinculados al mismo usuario. Se añadieron tests `auth-session-expiry.test.mjs` a `npm run test:unit`. **Falta ejecutar los tests, el typecheck y el build en el frontend**. Se añadió [Plan de recuperación sin ejecución](docs/FASE-0-PLAN-RECUPERACION.md); el usuario había decidido no generar backup todavía. Security Advisor detecta cinco funciones `public` con `function_search_path_mutable` (análisis pendiente, sin DDL aplicado). F0 sigue abierta. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **ESTADO F0 VIGENTE — TTL 12 h VERIFICADO 2026-10-20:** el usuario ejecutó con éxito `phase0-login-rollback.php --execute`: `config/sanctum.php` aplica 720 minutos; token nuevo con `expires_at` 12 horas, Bearer autorizado con 200, 13 h de antigüedad devuelve **401**, logout revoca token y su reutilización devuelve **401**, auditó ambos eventos, **ROLLBACK confirmado**. SELECT independiente en Supabase confirmó **1 empresa, 1 usuario, 1 membresía, 1.764 cuentas, 28 tokens, 34 auditorías, 2 asientos, 4 partidas y 18 migraciones** intactos. Los 28 tokens antiguos conservan `expires_at=NULL` en la BD (sin revocación física); el límite global de Sanctum los rechaza por antigüedad en el backend actualizado. **No hay verificación de despliegues**, ni respaldo/recuperación aprobada; F0 sigue **EN PROGRESO**. El siguiente control es planificar recuperación sin alterar la única base. [Evidencia](docs/FASE-0-DIAGNOSTICO.md).  

> **Seguridad F0 — TTL Sanctum 12 h aprobado 2026-10-20:** el usuario aprobó máximo **720 minutos** para tokens Bearer. En `main` se configuró `sanctum.expiration=720` y los logins nuevos fijan `expires_at=now()+12h`. Se amplió la prueba transaccional `scripts/phase0-login-rollback.php` para validar antigüedad simulada de 13h (401), revocación en logout (401), auditorías y rollback sin tocar datos persistentes. **Código pendiente de sincronizar y verificar en el equipo del usuario; no anunciar PASS todavía**. Los 28 tokens existentes conservan `expires_at=NULL` pero pasarán a ser rechazados si superan las 12h desde emisión al habilitar el nuevo backend. Ningún token fue borrado, ni se aplicaron migraciones. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Riesgo de sesiones detectado F0 — 2026-10-20:** Supabase confirma **28 tokens Sanctum sin `expires_at` (28/28)**, y `config/sanctum.php` tiene `expiration=null`; `LoginController` emite Bearer sin fecha de expiración. `LogoutController` sí intenta revocar token activo, pero falta prueba funcional de cierre de sesión y política de vencimiento. **No se modificaron tokens ni política de expiración** para evitar desconectar sesiones activas. Se recomienda acordar TTL y transición controlada, además de completar recuperación/backup y controles API. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **F0 2026-10-20 — Autenticación aprobada:** se ejecutó `phase0-login-rollback.php --execute` con **PASS**: POST /auth/login devolvió HTTP 200, se emitió un token Sanctum, el Bearer autorizó GET /tenant/companies y se confirmó auditoría `AUTH_LOGIN`; la transacción hizo **ROLLBACK**, sin filas de prueba persistentes. Supabase ratificó mediante SELECT los conteos intactos: **1 empresa, 1 usuario, 1 membresía, 28 tokens, 34 auditorías, 2 asientos, 4 partidas y 18 migraciones**. Queda resuelto el fallo RLS de auditoría detectado previamente; Fase 0 continúa pendiente de recuperación/respaldo, revisión de tokens y autorización más amplia. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Incidencia F0 2026-10-20 (validación login aún pendiente):** `phase0-login-rollback.php` superó preflight solo lectura pero falló en `--execute`: una inserción de auditoría en `audit_logs` fue rechazada por RLS (`SQLSTATE 42501`, confirmado en logs Supabase) y la transacción quedó abortada (`25P02` en consulta posterior). **Rollback y conteos preservados**, confirmados por Supabase: **1 empresa, 1 usuario, 1 membresía, 28 tokens, 34 auditorías, 2 asientos y 18 migraciones**. Se corrigió `AuditLoggerService` para establecer/restaurar contexto RLS local dentro de `DB::transaction` (SAVEPOINT si hay transacción exterior) y sanear logs SQL; el script ahora muestra solo fase y SQLSTATE. **Corrección pendiente de ejecutar y aprobar**; no se hicieron migraciones ni cambios directos en BD. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **F0 — 2026-10-20, validación vigente:** el usuario confirmó **5 pruebas HTTP/servicio aprobadas y 9 aserciones** (`ReadOnlyTenantApiSmokeTest.php`) tras unificar la consulta de empresas del login. La inspección SQL de Supabase identificó **18 tablas sin RLS** (14 catálogos, 4 globales). Ninguna tiene SELECT público (`anon`/`authenticated`), los 14 catálogos son solo lectura para `app_backend`, y las tablas `companies`, `users` y `personal_access_tokens` requieren autorización estricta en Laravel; `migrations` sigue inaccesible para el rol operativo. Línea base: **28 tokens y 34 registros de auditoría**. Se preparó `scripts/phase0-login-rollback.php` (preflight lectura / ejecución opcional transaccional con rollback, IDs explícitos y sin imprimir secretos) para validar emisión de token y Bearer; **pendiente ejecutar y verificar**. Fase 0 aún no aprobada. [Evidencia](docs/FASE-0-DIAGNOSTICO.md).  

> **Actualización vigente F0 — 2026-10-20:** **PASS del aislamiento interempresa A/B bajo PostgreSQL**, con comprobación SQL de lectura, restricción de UPDATE cruzado, membresía autorizada y RLS fail-closed, seguidos de **ROLLBACK confirmado**. Supabase comprobó por SELECT que subsisten los conteos originales (**1 empresa, 1 usuario, 1 membresía, 1.764 cuentas, 2 asientos, 4 partidas**) y **18 migraciones aplicadas**. Tras revisar rutas se detectó que `LoginController` repetía el error `boolean = integer` y lectura sin contexto RLS corregidos antes para la lista de empresas. Se incorporó `ListUserCompaniesService` común a login y selector y se movió la consulta antes de emitir un token; una quinta prueba en `ReadOnlyTenantApiSmokeTest.php` valida el servicio sin emitir tokens. **Cambios en `main`, prueba de regresión pendiente de ejecución**; no se hicieron nuevas migraciones. F0 sigue abierta por revisión global de seguridad HTTP, tablas sin RLS y recuperación. [Evidencia](docs/FASE-0-DIAGNOSTICO.md).  

> **Actualización F0 2026-10-20 (vigente):** **18 migraciones aplicadas**; `ReadOnlyTenantApiSmokeTest.php` **PASS 4/4 (7 aserciones)**; RLS `company_user` conserva política de aislamiento y SELECT por usuario, sin privilegios públicos. En la base única de desarrollo hay **1 empresa y 1.764 cuentas**, de modo que el aislamiento con una segunda empresa aún no está comprobado. Se desarrolló `backend/scripts/phase0-tenant-isolation-rollback.php`: preflight inicial **solo lectura** y modo `--execute` explícito que crea registros sintéticos *solo dentro de una transacción*, verifica aislamiento A/B y membresías, revierte con **ROLLBACK** y compara conteos antes/después. **No ejecutado todavía.** Revisar preflight y riesgos antes del modo transaccional. Se mantiene F0 abierta (recuperación, seguridad HTTP completa y suite segura pendientes). [Evidencias](docs/FASE-0-DIAGNOSTICO.md).  

> **Prueba HTTP F0 2026-10-20:** `ReadOnlyTenantApiSmokeTest.php` produjo **3 PASS / 1 FAIL**. `GET /api/v1/tenant/companies` devolvió 500 (`SQLSTATE[42883] boolean = integer`) por comparación booleana Eloquent bajo PostgreSQL/PDO. Corregido en `ListUserCompaniesController.php` usando `IS TRUE` y contexto de usuario `set_config(..., true)` dentro de transacción. Se preparó nueva migración **pendiente** `2026_10_20_010000_scope_company_listing_to_authenticated_user.php` con política `SELECT` limitada por `user_id`; la política de tenant para escrituras queda intacta. **17 migraciones aplicadas, 1 pendiente; test de regresión no reevaluado aún.** [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Validación F0 2026-10-20 — RLS:** el usuario ejecutó `phase0-audit-tenant-read-only.php` con resultado **PASS**: rol `app_backend`, sin empresa 0 cuentas/0 asientos; empresa inexistente 0/0; empresa activa 1.764 cuentas/2 asientos; transacción solo lectura sin cambios persistentes. **Pendiente** verificar autorización HTTP, especialmente `GET /api/v1/tenant/companies`: código fuera de `SetTenantContext` podría recibir lista vacía por política RLS de `company_user`. Se agregó prueba **solo GET** `backend/tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php`, aún no ejecutada; requiere resultados reales y no presupone PASS. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Resultado actualizado F0 — 2026-10-20:** **17 migraciones aplicadas** y rotación de `app_backend` confirmada en Supabase (batch 8). Conexión Laravel `pgsql` contra pooler 6543 operativa: el usuario ejecutó `php artisan test tests/Feature/Security/PublicDataApiPrivilegesTest.php` con resultado **PASS (1 prueba / 11 aserciones)**. Se verificó identidad y privilegios efectivos sin modificación de datos; autenticación operativa **resuelta**. Se preparó auditoría adicional **solo lectura** `php scripts/phase0-audit-tenant-read-only.php`, aún sin ejecutar, que comprueba RLS para cuentas y asientos sin empresa, con empresa inexistente y con empresa real; no sustituye pruebas completas de aislamiento entre dos empresas. **F0 no aprobada**: pendiente aislamiento funcional completo, recuperación/respaldo, revisión de 18 tablas sin RLS, y demás pruebas. Tras la verificación retirar del `.env` `DB_APP_BACKEND_NEW_PASSWORD`; mantener `DB_PASSWORD` y sincronizar despliegues. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Registro histórico (antes de la rotación y prueba superada) — Incidencia de autenticación 2026-10-20:** tras corregir `DB_URL`, PHPUnit ya intenta usar `app_backend` en el puerto 6543 pero la contraseña es rechazada, sin ejecutar aserciones. Catálogo Supabase confirma rol existente, `LOGIN`, no vencido y `NOBYPASSRLS`. Se preparó **una tercera migración Laravel en `main`, todavía PENDIENTE**, `2026_10_20_000000_rotate_app_backend_credential.php`, para rotar la clave sin exponerla en GitHub. Estado previo confirmado: **16 aplicadas**; repositorio actualizado: **17 archivos, 1 nueva pendiente**. La rotación requiere `DB_APP_BACKEND_NEW_PASSWORD` local y actualización coordinada de `DB_PASSWORD`; pruebas seguras y F0 aún abiertas. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Validación 2026-10-20, tras segunda migración:** el usuario ejecutó por Artisan la migración `2026_10_19_010000_add_company_economic_profile`; Supabase confirma **16 migraciones, batch 7 y 32 tablas**. La nueva `company_economic_activities` existe con RLS ENABLE/FORCE, una política para `app_backend`, FKs, restricciones de sector/CIIU, CIIU único por empresa y máximo una actividad principal por empresa. `company_settings` contiene los cinco nuevos campos. `anon`/`authenticated`: **0 tablas, secuencias o funciones** con permisos efectivos; `app_backend`: SELECT en 31 tablas, USAGE en 20 secuencias y EXECUTE en 5 funciones. Datos preservados: **1 empresa, 1 configuración, 2 asientos y 4 partidas**. Consulta F0 del ledger: **0 asientos inválidos, 0 partidas huérfanas, 0 referencias inválidas**. Existen **18 tablas sin RLS**; revocar privilegios públicos no equivale a habilitarlo. **No se ha probado la creación de empresas vía Laravel ni completado la suite de tests; no se realizó respaldo de desarrollo por decisión expresa del usuario.** La Fase 0 permanece abierta.  

> **Validación de seguridad 2026-10-20 (más reciente):** el usuario ejecutó con Artisan la migración `2026_10_19_000000_restrict_supabase_data_api_grants` y Supabase confirma **15 migraciones aplicadas** (seguridad, batch 6). `anon` y `authenticated`: **0 tablas, 0 secuencias y 0 funciones** con permisos efectivos; `app_backend` conserva SELECT sobre 30 tablas, USAGE sobre 19 secuencias y EXECUTE sobre 5 funciones. Se preservan **2 asientos y 4 partidas**. La migración F3 `2026_10_19_010000_add_company_economic_profile` sigue pendiente. Sin prueba HTTP/funcional de Laravel aún y sin respaldo realizado (riesgo aceptado por el usuario en desarrollo). Los registros históricos siguientes reflejan fechas previas a esta validación.  

> Esta hoja de ruta establece trabajo futuro. Las casillas en blanco **no** significan que el código existente carezca de esa funcionalidad; indican que la actividad de verificación/implementación del plan todavía no se ha aprobado.

[Volver al README principal](README.md) · [Diagnóstico Fase 0](docs/FASE-0-DIAGNOSTICO.md) · [Seguimiento detallado](#10-checklist-de-seguimiento) · [Hitos](#8-hitos-y-puertas-de-calidad)

---

## 1. Propósito, alcance y principios

**Objetivo:** evolucionar el sistema actual hacia un ERP contable peruano multiempresa, seguro, bimonetario, confiable para la operación diaria, conforme a los requerimientos aplicables de SUNAT/PCGE/NIIF y con un frontend profesional.

**Proyecto de destino, exclusivamente:**
- GitHub: [jwichpas/contabilidad](https://github.com/jwichpas/contabilidad), rama `main`.
- Supabase: **contabilidad-database**, referencia `bnjwxphwtkhblvhsnylj`.
- Arquitectura existente: **Laravel 11 + PHP / Laravel Sanctum**, **Vue 3 + TypeScript + Tailwind CSS 4**, **PostgreSQL (Supabase)**.
- **No confundir ni mezclar** este proyecto con `Nexus-contable` / `Nexus Contable Dev`.

**Principios obligatorios:**
1. Preservar el proyecto existente y mejorar incrementalmente; no reescribir Laravel/Vue sin una justificación documentada.
2. Usar `main` como rama de trabajo solicitada; no crear ramas por defecto. Los cambios deben ser pequeños, probados y trazables.
3. **Laravel es el único gestor del esquema:** toda creación o modificación de tablas, columnas, índices, constraints, triggers, vistas, RLS y funciones SQL debe vivir en una migración nueva de `backend/database/migrations/`, ejecutada con Artisan y con plan de respaldo/reversión. **No hacer DDL de cambios funcionales directamente en Supabase.** Consultar [política de BD](docs/ARQUITECTURA-BD-LARAVEL.md).
4. Aislamiento estricto por empresa en consultas, relaciones y accesos. Nada de secretos o tokens privilegiados en el frontend.
5. Libro Diario como fuente de verdad; toda rectificación contable mediante flujo autorizado y trazable, no por edición destructiva.
6. Precisión monetaria decimal exacta de extremo a extremo; nada de `float`/`double` para importes o tipos de cambio.
7. Cumplimiento normativo **versionado por fecha de vigencia y tipo de contribuyente**. No asumir que una norma citada en el README está vigente sin verificarla.
8. No declarar una fase completada por existencia de archivos: requerir pruebas, evidencia, revisión contable y aprobación.

### Alcance funcional final

- Empresas, usuarios, roles, permisos, parámetros fiscales, monedas y maestros.
- Plan de cuentas PCGE versionado, cuentas de movimiento, dimensiones contables y centros de costos.
- Motor de asientos, subdiarios, CUO, partida doble, tipos de cambio, extornos, cierres y auditoría.
- Libro Diario, Mayor, Balance de Comprobación, estados financieros y reportes.
- Cuentas por cobrar/pagar, caja/bancos y conciliación bancaria.
- Catálogos SUNAT correctos para libros electrónicos, PLE, RCE/RVIE y conciliación SIRE según obligatoriedad.
- NIIF completas y NIIF para PYMES mediante reglas y configuraciones aplicables; activos fijos, diferencias contable-fiscal e impuesto diferido.
- Frontend responsive orientado a digitación intensiva, integración API, automatización de pruebas y despliegue controlado.

**Fuera del alcance de esta hoja de ruta:** una reescritura de stack, mezclar datos de otro repositorio o realizar cambios en producción sin controles. Integraciones adicionales podrán priorizarse luego con aprobación.

---

## 2. Línea base observada (revisión previa; pendiente de ratificación en Fase 0)

Esta información se documenta para no perder los hallazgos, **no** como certificación definitiva del estado del sistema.

| Dimensión | Evidencia observada |
|---|---|
| Repositorio | `jwichpas/contabilidad`, rama `main`; frontend y backend separados |
| Base de datos | `contabilidad-database`, PostgreSQL; 31 tablas del esquema `public` |
| Migraciones | 14 registradas en la base de datos |
| Datos de prueba | 1 empresa; 1 usuario; 1,764 cuentas de empresa; 3,401 plantillas PCGE; 2 asientos y 4 líneas |
| Seguridad | 18 tablas `public` con RLS deshabilitado; verificar y corregir grants públicos |
| Rendimiento | 17 claves foráneas sin índice de soporte reportadas; 8 índices sin uso observado (no eliminarlos precipitadamente) |
| SQL | 5 funciones reportadas con `search_path` mutable |
| Contexto tenant | `SetTenantContext.php` usa `set_config(..., false)`; requiere revisar alcance de sesión/transacción y conexión |
| Precisión | `CreateJournalEntryController.php` usa conversiones `float`/`number_format` pese al contrato de precisión decimal |
| Concurrencia | Correlativo del asiento generado mediante `COUNT + 1`, sujeto a carreras |
| Contabilidad | Existen migración, controlador y servicio de POST de asientos; sus garantías deben probarse, no presumirse |
| UI | Existen vistas Vue para plan de cuentas, asientos y tipos de cambio; falta validación funcional end-to-end |

**Riesgo de máxima prioridad:** las tablas `users`, `companies`, `personal_access_tokens` y otras sin RLS presentan privilegios amplios otorgados a `anon`/`authenticated` en PostgreSQL. Se requiere diseñar `REVOKE`, RLS y políticas apropiadas **sin bloquear** Laravel ni el rol restringido `app_backend`. No ejecutar una activación masiva de RLS sin plan de autorización y pruebas.

---

## 3. Hoja de ruta general

Duración indicativa: **32 semanas (~8 meses) para un equipo pequeño**, con solapamientos controlados. No son fechas comprometidas. La **Fase 0 comenzó el 2026-10-19**; las ventanas S1–S32 son estimaciones y se reajustarán según los resultados.

| Fase | Nombre | Prioridad | Ventana estimada | Duración | Estado |
|---|---|---|---|---|---|
| **0** | Diagnóstico y línea base | Inmediata, previa a cambios | S1 | 1 sem. | 🟡 En progreso |
| **1** | Seguridad, autenticación y multiempresa | Crítica | S2–S3 | 2 sem. | ⬜ No iniciada |
| **2** | Integridad y motor contable | Crítica | S4–S6 | 3 sem. | ⬜ No iniciada |
| **3** | Empresas, PCGE y maestros | Alta | S7–S9 | 3 sem. | ⬜ No iniciada |
| **4** | Modernización UX/UI | Alta | S9–S12 | 4 sem. | ⬜ No iniciada |
| **5** | Libros y operación contable | Alta | S12–S17 | 6 sem. | ⬜ No iniciada |
| **6** | SUNAT, PLE y SIRE | Alta | S17–S22 | 6 sem. | ⬜ No iniciada |
| **7** | Estados financieros, NIIF y activos fijos | Alta | S21–S29 | 9 sem. | ⬜ No iniciada |
| **8** | Calidad final, preview y despliegue | Final | S29–S32 | 4 sem. | ⬜ No iniciada |

**Dependencias:** Fase 1 depende de la línea base F0; F2 de la seguridad F1; F3 de contratos y motor F2. Las tareas de interfaz F4 pueden solaparse con F3 sin integrar funciones no estabilizadas. SUNAT F6 depende de la trazabilidad contable F5. Los informes y NIIF F7 pueden comenzar parcialmente durante F6 si los libros base están conciliados. F8 requiere criterios de aceptación de las fases comprometidas.

### Gantt orientativo (32 semanas)

```text
Semanas     01 02 03 04 05 06 07 08 09 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32
F0          ██
F1             ██ ██
F2                   ██ ██ ██
F3                            ██ ██ ██
F4                                  ██ ██ ██ ██
F5                                           ██ ██ ██ ██ ██ ██
F6                                                          ██ ██ ██ ██ ██ ██
F7                                                                      ██ ██ ██ ██ ██ ██ ██ ██ ██
F8                                                                                              ██ ██ ██ ██
```

La tabla de ventanas prevalece si la tipografía del Gantt no se visualiza exactamente alineada.

---

## 4. Fases detalladas y criterios de salida

### FASE 0 — Diagnóstico, inventario y línea base (1 semana)

**Objetivo:** establecer estado verificado, riesgos, dependencias y pruebas iniciales antes de modificar el sistema.

**Trabajo:**
- Inventariar tablas, vistas, índices, funciones, triggers, roles, grants y políticas RLS del proyecto Supabase correcto.
- Comparar migraciones del repositorio con las 14 migraciones registradas y verificar **sin destruir datos** restricciones, triggers, RLS y esquema de la única base de desarrollo. Reconstruir desde cero sobre esta base no está permitido.
- Inventariar controladores, endpoints, servicios, vistas, tests y dependencias.
- Ejecutar y registrar resultados de `composer test`, análisis estático/lint, `pnpm build` y `pnpm typecheck` (si el entorno lo permite).
- Verificar posibilidad de respaldo y recuperación de la **única base Supabase de desarrollo** y emplear datos ficticios, pruebas SQL de solo lectura y tests con transacciones/rollback. **No crear base aislada** (decisión del usuario).
- Revisar vigencia normativa del PCGE 2019/2026, NIIF 18/19, PLE y SIRE en fuentes oficiales.
- Clasificar funcionalidades en **implementadas y probadas / implementadas sin validar / parciales / pendientes**.

**Entregable:** diagnóstico versionado, matriz de riesgos, línea base de tests, inventario de datos y orden de correcciones.

**Puerta F0:** arquitectura y riesgos críticos identificados, recuperación evaluada y baseline reproducible no destructivo en la **base de desarrollo única**. No ejecutar migraciones destructivas, `migrate:fresh` ni borrados en la base activa. Las medidas urgentes de manejo de secretos pueden adelantarse si son necesarias para reducir exposición.

### FASE 1 — Seguridad, autenticación y aislamiento multiempresa (2 semanas)

**Objetivo:** impedir exposición indebida y accesos cruzados de datos.

**Trabajo:**
- Segmentar tablas expuestas/no expuestas; revocar permisos de `anon` y `authenticated` donde sean innecesarios.
- Definir y probar RLS para las 18 tablas identificadas, según su rol (privada, catálogo público o dato de empresa); evitar políticas genéricas permisivas.
- Endurecer `users`, `companies`, `personal_access_tokens`; proteger secretos, contraseñas, claves y tokens.
- Revisar la interacción de `SetTenantContext`, `set_config`, `app_backend`, transacciones y pool de conexiones.
- Revisar `SECURITY DEFINER`, `search_path`, permisos `EXECUTE` y políticas para tablas con `company_id`.
- Implementar autorización por rol y empresa (administrador, contador, asistente, auditor, consulta).
- Pruebas negativas: empresa A no lee/escribe empresa B, incluidas relaciones y solicitudes directas API.
- Verificar que Laravel y los trabajos asíncronos conservan su acceso legítimo tras el endurecimiento.

**Entregable:** migraciones de seguridad, pruebas de aislamiento y matriz de acceso a datos.

**Puerta F1:** cero vías conocidas de lectura/escritura anónima indebida, acceso tenant validado, pruebas de autenticación y autorización aprobadas.

### FASE 2 — Motor contable exacto, concurrente y auditable (3 semanas)

**Objetivo:** asegurar partida doble, idempotencia y trazabilidad antes de ampliar módulos.

**Trabajo:**
- Quitar conversiones a `float` y redondeos ambiguos de importes y tasas en controlador/servicio/API; usar strings decimales + BCMath y `NUMERIC`.
- Reemplazar `COUNT + 1` por correlativos seguros bajo concurrencia, transacciones y restricciones únicas.
- Incorporar clave idempotente por operación para impedir duplicación por reintentos.
- Auditar `PostJournalEntryService`, orden de persistencia, triggers diferidos, estado de periodos y consistencia de operaciones auxiliares.
- Consolidar flujo de borrador → revisión/validación → POST → extorno, con autorización y auditoría, sin editar asientos asentados.
- Modelar subdiarios, CUO, referencia documental, origen, periodo y numeración oficial donde corresponda.
- Validar cuentas operativas, partida doble, dimensiones obligatorias, moneda funcional, tipo de cambio y operación de extorno.
- Probar simultaneidad, recuperaciones por fallos, casos límite de redondeo y saldos en MN/ME.

**Entregable:** motor de contabilización probado y contratos de API estables.

**Puerta F2:** ninguna duplicación en pruebas concurrentes; ninguna transacción desbalanceada o parcial; todos los casos críticos de precisión y extorno cubiertos por tests.

### FASE 3 — Alta de empresas, PCGE y datos maestros (3 semanas)

**Objetivo:** configurar una empresa sin scripts manuales.

**Trabajo:**
- Wizard de empresa: identificación fiscal, **persona natural/jurídica y forma legal**, **régimen NRUS/RER/RMT/General según elegibilidad**, **sector/subsector y CIIU Rev. 4** (una actividad principal y varias secundarias), ejercicio, ingresos/UIT, parámetros, moneda funcional y secundarias. Especificación: [perfil de empresa y libros SUNAT](docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md).
- Selección y copia de plantilla PCGE por empresa, manteniendo matrices globales inmutables.
- Cuentas agrupadoras sin movimiento y cuentas operativas con granularidad de 6 dígitos o superior configurable.
- Reglas por cuenta: requerir o no entidad, área, centro de costos, banco, cuenta bancaria, medio de pago y documento.
- Maestros de clientes, proveedores, bancos, trabajadores, entidades, áreas, centros de costos y subdiarios.
- Control de adopción/migración entre versiones del PCGE y mapeos trazables.
- Pruebas con empresas ficticias **comercial, servicios, industrial, agrícola, ganadera, construcción, extractiva y transporte/logística**, incluyendo mezcla de actividades y separación de perfiles tributarios por empresa.
- Mantener **historial de regímenes tributarios** y perfiles de ingresos/UIT por ejercicio. Normalizar el valor genérico `ESPECIAL` («Otros Regímenes Especiales») hacia regímenes concretos, sin romper registros históricos ni confundirlo con `RER`.

**Entregable:** asistente funcional y empresa apta para iniciar registros.

**Puerta F3:** crear empresa de prueba y registrar su primer asiento, sin modificaciones manuales en SQL.

### FASE 4 — UX/UI contable en Vue (4 semanas; parcialmente paralela)

**Objetivo:** una interfaz profesional, consistente y eficiente para contadores.

**Trabajo:**
- Separar layouts de autenticación y aplicación.
- Selector de empresa persistente en sidebar usando estado global de Vue (p. ej. Pinia) con validación de permisos.
- Mejorar navegación y carga de datos con Vue Router y estrategia de caché/queries apropiada.
- Diseñar **un editor de asiento** con cabecera única y tabla de partidas agregadas; eliminar formularios repetitivos.
- Autocompletado de cuentas/entidades, validación contextual y totales Debe/Haber en tiempo real.
- Listas avanzadas: filtros, paginación, columnas, búsqueda, exportación y estados vacíos/carga/error.
- Biblioteca visual accesible, responsiva, atajos de teclado y confirmación de cambios destructivos.
- Pruebas de navegadores desktop/móvil y de accesibilidad.

**Entregable:** flujo usable de configuración, asiento, consulta y exportación.

**Puerta F4:** completar un asiento desde una sola pantalla, sin cambiar empresa en cada vista ni repetir formularios por línea.

### FASE 5 — Libros, cuentas por cobrar/pagar y operaciones (6 semanas)

**Objetivo:** habilitar el ciclo contable mensual con saldos trazables.

**Trabajo:**
- Libro Diario (incluido formato simplificado donde aplique), Libro Mayor y Balance de Comprobación (movimientos y saldos). Disponibilidad de reportes separada de su obligatoriedad tributaria.
- Auxiliares por entidad/documento; cuentas por cobrar, cuentas por pagar y aplicación de cobros/pagos.
- Caja y bancos, cuentas 104xxx, medios de pago y conciliación bancaria.
- Apertura, ajustes, regularizaciones, cierres y reapertura autorizada.
- Reportes PDF/Excel y vistas imprimibles con filtros y origen verificable.
- Reconciliación del mayor y saldos con el Libro Diario, fuente de verdad.
- Catálogo de **tipos de libros contables** y estados de obligación por empresa/ejercicio; calcular los mínimos desde reglas versionadas SUNAT, no únicamente desde un booleano.
- Evitar saldos editables manualmente desligados del ledger.
- Conciliar mensualmente débito IGV local, crédito fiscal admitido/no admitido, prorrata si corresponde y **SFE aplicado a IGV local**, sin mezclar SFMB, solicitudes de devolución ni caja; referencia: [módulo SFE/SFMB](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md).

**Entregable:** ciclo contable operativo para una empresa ficticia de punta a punta.

**Puerta F5:** movimientos y saldos concilian; cierre impide contabilización no autorizada; reportes reproducibles.

### FASE 6 — SUNAT, PLE, RCE/RVIE y conciliación SIRE (6 semanas)

**Objetivo:** preparar información y archivos tributarios según formatos y obligación aplicables.

**Trabajo:**
- Versionar tablas SUNAT de **libros electrónicos** por formato, código y vigencia; no confundir con catálogos de emisión electrónica.
- Determinar libros obligatorios según **régimen tributario, ingresos anuales brutos/netos según norma, tramo UIT del ejercicio (300/500/1700), fecha de vigencia y actividad**, cubriendo NRUS, RER, RMT y General, así como contabilidad completa y registros adicionales.
- Diferenciar el libro obligatorio del **medio de llevado** (PLE, SIRE, físico/electrónico); gestionar fecha de inicio de obligatoriedad, rectificaciones, cambios de régimen y sustento legal por empresa. No depender de `is_sire_obliged`/`is_ple_obliged` manuales como único dato.
- Registro de Compras y Registro de Ventas con comprobantes, serie, número, fechas, RUC, IGV y moneda.
- Generación/validación de archivos PLE y de libros sujetos a obligación.
- Flujos de importación, comparación y conciliación para RCE/RVIE y SIRE, conforme a interfaces oficiales disponibles.
- Reglas de duplicados, observaciones, rectificaciones, periodos y control documentario.
- Pruebas con casos peruanos (gravado, exonerado, inafecto, notas, diferencias).
- Incorporar **SFE y SFMB de exportadores de bienes/servicios**, incluyendo empresas mixtas con ventas nacionales, límite por exportaciones computables (FOB/DAM, facturas de servicios, notas), arrastre, PDB Exportadores, compensación y devolución, con pruebas por periodo y rastreo SUNAT. La exportación calificada es **inafecta** al IGV, no gravada con tasa cero. [Especificación normativa](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md).
- Separar crédito fiscal arrastrable de empresas locales, SFE, SFMB determinado, límite disponible, compensaciones, solicitudes pendientes y cobro real; no reconocer dinero en bancos por el cálculo ni crear solicitudes automáticas sin constancia oficial.
- Validación de formato contra especificaciones SUNAT vigentes y muestras aceptadas.

**Entregable:** registros y exportaciones validadas, conciliadas con asientos y documentos.

**Puerta F6:** archivos conformes al formato oficial aplicable, trazables y reproducibles; diferencias justificadas.

### FASE 7 — Estados financieros, NIIF, financiamiento y activos fijos (9 semanas orientativas; alcance ampliado)

**Documento normativo-funcional vinculante para esta fase:** [Plan integral F7 — NIIF 18, NIIF para PYMES, NPIF, NIIF 9 y activos fijos con tratamiento tributario peruano](docs/F7-PLAN-INTEGRAL-NIIF-NPIF-PRESTAMOS-ACTIVOS-FIJOS.md). **Estado: PLANIFICADO, no implementado.** El alcance adicional requiere reestimar la duración antes de iniciar F7.

**Objetivo:** producir EEFF consistentes, registrar préstamos y activos a lo largo de su ciclo completo, y conciliar tratamiento contable-financiero versus tributario peruano.

**Bloque A — Marcos contables y presentación:** determinar por empresa/ejercicio si corresponde NIIF completas, NIIF para las PYMES (ediciones 2015/2025) o NPIF peruana, con sustento de ingresos/UIT y resoluciones del CNC. Preparar NIIF 18 para periodos aplicables (vigencia internacional 2027), cinco categorías de resultados y subtotales, excepciones por actividad principal, comparativos, medidas gerenciales y notas; sin imponer NIIF 18 sobre NIIF PYMES/NPIF. NIIF 19 solo para subsidiarias elegibles.

**Bloque B — Préstamos e instrumentos:** NIIF 9 (o sección equivalente según marco): principal contractual, comisión de desembolso, flujo neto, costo amortizado y tasa de interés efectiva/TIR, periodos de gracia, interés compuesto, cronograma contractual versus contable, pagos, renegociaciones, asientos PCGE, NIC 21, revelaciones y tratamiento tributario de gastos financieros.

**Bloque C — Módulo integral de activos fijos y obra en curso:** expediente documental SUNAT (tipo, serie y correlativo), ficha y componentes, contratos, presupuestos, compras, materiales, mano de obra, valorizaciones, avances y puesta en uso; depreciación financiera NIC 16 y tributaria SUNAT separadas; mejoras vs mantenimiento, cambios de vida/residual, revaluaciones, deterioros, bajas, ventas, leasing, propiedad de inversión e intangibles cuando corresponda; NIC 23, 36, 37, 40, 38, NIIF 16, 5, 13, etc., según el hecho económico.

**Bloque D — Fiscalidad y reporte:** bases contables/fiscales por activo, diferencias temporarias e impuesto diferido NIC 12, IGV, deducibilidad, tasas y límites versionados, Registro de Activos Fijos SUNAT (formatos aplicables, incluido 7.1), trazabilidad CUO, asientos y conciliación a libros/EEFF.

**Implementación incremental F7.0–F7.6:** marcos normativos -> activos básicos -> construcciones -> operaciones posteriores -> impuesto diferido y registro SUNAT -> préstamos NIIF 9 -> EEFF NIIF 18 y versiones PYME/NPIF. Las subtareas, normas aplicables, estructuras conceptuales, pruebas y fuentes oficiales constan en el [anexo F7](docs/F7-PLAN-INTEGRAL-NIIF-NPIF-PRESTAMOS-ACTIVOS-FIJOS.md).

**Entregable:** EEFF conciliados, cronogramas de crédito, auxiliares de activo contable/fiscal, impuesto diferido y reportes SUNAT trazables.

**Puerta F7:** EEFF = libros; préstamo = flujos y TIE reproducibles; activos = costos y depreciación financiera/fiscal auditables; obra en curso sin depreciación financiera antes de disponibilidad; NIC 12 y Registro de Activos Fijos conciliados; pruebas multiempresa A/B aprobadas.

### FASE 8 — QA integral, rendimiento, preview y preparación productiva (4 semanas)

**Objetivo:** liberar una versión candidata estable y respaldada por evidencia.

**Trabajo:**
- Ejecutar suites unitarias, integración, end-to-end, seguridad, aislamiento tenant, concurrencia y recuperación.
- Revisar índices, planes de ejecución, carga, límites, logs y alertas.
- Automatizar CI/CD: pruebas + lint + typecheck + build antes de publicar.
- Ensayar reconstrucción por migraciones, rollback controlado y restauración de respaldos.
- Publicar un preview, verificar operación con usuarios de prueba y aprobar manuales.
- Definir checklist de paso a producción, responsables, soporte inicial y criterios de rollback.

**Entregable:** release candidate verificada, documentación y acta de aceptación.

**Puerta F8:** cero vulnerabilidades críticas conocidas, integridad contable probada, backups restaurables, pruebas obligatorias aprobadas y decisión explícita de despliegue.

---

## 5. Arquitectura objetivo y límites

- **API:** Laravel; controladores delgados, FormRequest/DTO, servicios de dominio e interfaces bien definidas.
- **Frontend:** Vue 3 + TypeScript + Tailwind; módulos funcionales, composables, estado tenant global, formularios y tablas accesibles.
- **BD:** Supabase/PostgreSQL como servidor; **DDL exclusivamente mediante migraciones de Laravel** con conexión `pgsql_migrations`, sin cambios de esquema desde el editor SQL de Supabase. Transacciones, `NUMERIC`, claves foráneas compuestas, triggers contables y RLS según corresponda.
- **Seguridad:** Sanctum, autorización por empresa/rol, privilegios mínimos, secretos fuera del cliente y auditoría.
- **Contabilidad:** ledger inmutable para POST, borradores separados cuando aplique, correlativos concurrentes, idempotencia y ajustes con trazabilidad.
- **Normativa:** catálogos vigentes con versión, fuentes y fecha de aplicación; EEFF y libros por reglas verificables.
- **Entrega:** un único repositorio, migraciones reproducibles, pruebas, documentación y previews controladas.

### Reglas de cambio

Un cambio que afecte asientos, saldos, períodos, RLS o usuarios requiere: (1) caso de uso y riesgo, (2) prueba previa, (3) migración o código versionado, (4) validación multiempresa y (5) evidencia de salida. No ejecutar cambios irreversibles directamente en Supabase sin aprobación y mecanismo de recuperación.

---

## 6. Riesgos iniciales y tratamiento

| ID | Riesgo | Gravedad | Tratamiento / fase |
|---|---|---|---|
| R01 | Tablas `public` sin RLS con grants públicos (incluidos tokens) | **Crítica** | F0: inventario; F1: `REVOKE` y políticas específicas, pruebas directas API |
| R02 | Contexto tenant persistente por sesión en conexiones reutilizadas | **Crítica** | F1: transacciones, limpieza garantizada y pruebas de pooling |
| R03 | Uso de `float` para importes y tasas | **Alta** | F2: decimal exacto y casos de redondeo |
| R04 | Correlativo `COUNT + 1` bajo concurrencia | **Alta** | F2: asignación serializada/única y reintentos idempotentes |
| R05 | Documentación normativa potencialmente desactualizada | **Alta** | F0/F6/F7: fuentes oficiales, versionado y vigencias |
| R06 | Funciones SQL con `search_path` mutable | Alta | F1: función por función y privilegios |
| R07 | Índices insuficientes o innecesarios | Media | F8: medir antes de agregar/eliminar |
| R08 | Cobertura de pruebas limitada o no verificada | Alta | F0 baseline; controles continuos F1–F8 |
| R09 | Acoplamiento de módulos / regresiones UX | Media | F3/F4: contratos, tests y diseño modular |
| **R10** | **Contraseña de BD versionada en `backend/phpunit.xml`** | **Crítica** | F0: registrar exposición; rotación urgente y retiro del secreto, incluidos rastros de Git, antes de ejecutar pruebas |
| R11 | Tests usan la base única de desarrollo | Alta | F0: revisar cada test, forzar entorno de pruebas y rollback, confirmar conexión y respaldos antes de ejecutarlos; no crear otra BD |
| R12 | README cita erróneamente resolución del PCGE 2026 | Media | F0: corregido en README; norma oficial **002-2026-EF/30**, PCGE 2026 obligatorio desde **2028**, adopción anticipada posible |
| R13 | Runner GitHub Actions no disponible en primera ejecución | Media | F0: workflow estático ya creado, run [39354337434](https://github.com/jwichpas/contabilidad/actions/runs/39354337434) sin runner ni pasos; ejecución manual hasta resolver disponibilidad |
| R15 | Rol `app_backend` con contraseña predeterminada posible en instalación nueva | Alta | F0: revisar migración original y secretos; F1: evitar valores de contraseña por defecto y rotar credenciales expuestas mediante flujo seguro |

**Política de manejo:** un riesgo crítico abierto puede bloquear la habilitación de usuarios reales o el despliegue, incluso si el módulo parece funcional.

---

## 7. Método de implementación, gobernanza y evidencias

**Estados permitidos por fase y actividad:** `NO INICIADA` → `EN PROGRESO` → `EN VALIDACIÓN` → `COMPLETADA`; adicional `BLOQUEADA` si existe impedimento.

**Definición de terminado (DoD) de una tarea:**
- Código y/o migración versionados en `main`, cuando corresponda.
- Prueba automatizada o protocolo manual con resultado registrado.
- Verificación de seguridad, precisión, empresa/tenant y normativa según el cambio.
- Sin regresiones críticas conocidas; documentación actualizada.
- Evidencia enlazada (commit, ejecución CI, captura, consulta de prueba o reporte) y responsable/revisor indicado.

**Reglas de seguimiento:**
1. Actualizar casillas y estado solo **después de la evidencia**, nunca por expectativa.
2. Registrar fecha, responsable, commit y resultado del gate de fase.
3. Conservar los hallazgos no resueltos en la tabla de riesgos.
4. Replanificar duración y dependencias si la F0 descubre trabajo no previsto.
5. Mantener el plan en este archivo y enlazar decisiones relevantes a issues/commits, sin crear ramas adicionales automáticamente.
6. No desplegar por cada ajuste menor: agrupar validaciones y publicar previews deliberados.

**Plantilla para registro de avance:**

```md
### Actualización YYYY-MM-DD — Fase X
- Responsable / revisor:
- Estado: NO INICIADA | EN PROGRESO | EN VALIDACIÓN | COMPLETADA | BLOQUEADA
- Actividades cerradas y evidencia (URL de commit/CI):
- Pruebas realizadas / resultados:
- Riesgos nuevos o mitigados:
- Bloqueos y decisiones:
- Próximo paso:
- Gate de salida aprobado por:
```

---

## 8. Hitos y puertas de calidad

| Hito | Alcance | Estado inicial | Condición verificable |
|---|---|---|---|
| **H1 — Base segura** | F0–F2 | ⬜ Pendiente | Inventario, RLS/grants corregidos, aislamiento y contabilización exacta/concurrente probados |
| **H2 — Sistema operativo** | F3–F5 (núcleo) | ⬜ Pendiente | Alta empresa + plan + asiento + Diario/Mayor/Balance + cierre sin operaciones manuales |
| **H3 — Cumplimiento tributario** | F5–F6 | ⬜ Pendiente | Libros y reportes electrónicos validados y conciliados |
| **H4 — Versión candidata** | F7–F8 | ⬜ Pendiente | EEFF/NIIF/AF comprometidos, tests integrales, preview y aceptación formal |

**Condición de desbloqueo:** cada hito requiere evidencias enlazadas y visto bueno explícito. El cronograma no reemplaza las puertas de calidad.

### Registro de aprobación (completar cuando suceda)

| Hito / Fase | Fecha de aprobación | Responsable | Evidencia | Estado |
|---|---|---|---|---|
| F0 | — | — | [Diagnóstico en progreso](docs/FASE-0-DIAGNOSTICO.md) | EN PROGRESO; pendiente de aprobación |
| F1 | — | — | — | NO INICIADA |
| F2 | — | — | — | NO INICIADA |
| F3 | — | — | — | NO INICIADA |
| F4 | — | — | — | NO INICIADA |
| F5 | — | — | — | NO INICIADA |
| F6 | — | — | — | NO INICIADA |
| F7 | — | — | — | NO INICIADA |
| F8 | — | — | — | NO INICIADA |
| H1–H4 | — | — | — | PENDIENTES |

---

## 9. Orden de ejecución y primera decisión

**Prerequisito completado (2026-10-19):** este README está en GitHub, verificado y enlazado desde `README.md`. **Fase 0 iniciada**, con evidencia y hallazgos en [docs/FASE-0-DIAGNOSTICO.md](docs/FASE-0-DIAGNOSTICO.md). Ninguna tarea se ha aprobado aún.

**Secuencia recomendada:** F0 → F1 → F2 → F3 → F4/F5 → F6 → F7 → F8, respetando los solapamientos de la hoja de ruta.

**Importante:** la Fase 0 se inició por instrucción explícita del usuario el 2026-10-19. Hasta este registro se efectuaron consultas de solo lectura y actualizaciones de Markdown; **no se ejecutaron migraciones, no se modificó Supabase ni código aplicativo, no se corrieron pruebas contra la base compartida y no se desplegó**. F1 continúa sin iniciar.

---

## 10. Checklist de seguimiento

> Marcar `[x]` exclusivamente tras completar las verificaciones y aportar evidencia. **Inicio: 0/27 actividades aprobadas (0%).**

### Fase 0 — Diagnóstico y línea base
- [ ] **F0.1** Inventariar Supabase, grants/RLS y respaldo con restauración verificada.
- [ ] **F0.2** Contrastar migraciones, código, módulos y estado funcional real.
- [ ] **F0.3** Ejecutar baseline de pruebas y revisar documentación normativa.

### Fase 1 — Seguridad
- [ ] **F1.1** Corregir exposición pública, privilegios y políticas RLS sin bloquear backend.
- [ ] **F1.2** Corregir contexto tenant transaccional, pooling y funciones privilegiadas.
- [ ] **F1.3** Probar separación entre empresas, roles y permisos.

### Fase 2 — Motor contable
- [ ] **F2.1** Eliminar punto flotante del circuito monetario y validar precisión.
- [ ] **F2.2** Implementar numeración segura, idempotencia y atomicidad.
- [ ] **F2.3** Validar POST, extornos, cierre y controles de partida doble.

### Fase 3 — Empresas y maestros
- [ ] **F3.1** Implementar asistente de empresas y parámetros fiscales.
- [ ] **F3.2** Consolidar plantillas PCGE y cuentas operativas parametrizadas.
- [ ] **F3.3** Completar maestros, dimensiones y empresas ficticias.

### Fase 4 — Interfaz
- [ ] **F4.1** Separar layouts, navegación y selector global de empresa.
- [ ] **F4.2** Sustituir formularios repetidos por editor único de asientos.
- [ ] **F4.3** Mejorar tablas, validaciones, accesibilidad y responsive.

### Fase 5 — Gestión contable
- [ ] **F5.1** Consolidar Diario, Mayor y Balance de Comprobación.
- [ ] **F5.2** Completar CxC, CxP, bancos y conciliación.
- [ ] **F5.3** Validar cierres, reconciliación y reportes PDF/Excel.

### Fase 6 — SUNAT
- [ ] **F6.1** Versionar catálogos SUNAT y generar archivos PLE aplicables.
- [ ] **F6.2** Incorporar registros RCE/RVIE y flujos de conciliación SIRE.
- [ ] **F6.3** Validar formatos, duplicidades, observaciones y conciliación.
- [ ] **F6.4** Implementar y probar SFE/SFMB (exportaciones bienes/servicios y empresas mixtas): origen del crédito, aplicación a IGV local, límites/arrastres, PDB, compensación/devolución y trazabilidad. [Documento de diseño](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md).

### Fase 7 — NIIF, NPIF, préstamos y activos fijos

> Checklist de detalle F7.0–F7.6 ampliado; no cambia el conteo histórico de 27 hitos de alto nivel de este plan. [Especificación](docs/F7-PLAN-INTEGRAL-NIIF-NPIF-PRESTAMOS-ACTIVOS-FIJOS.md).

- [ ] **F7.0** Motor de selección de marco: NIIF completas / NIIF PYMES 2015-2025 / NPIF, por empresa, ejercicio y norma peruana aplicable.
- [ ] **F7.1** Activos fijos: altas, expedientes SUNAT, componentes, depreciación financiera NIC 16 y tributaria independiente.
- [ ] **F7.2** Construcciones en curso: contratos, costos, mano de obra, NIC 23 cuando corresponda y puesta en uso.
- [ ] **F7.3** Mejoras, cambios de estimaciones, bajas, ventas, deterioro, revaluación, arrendamiento y normas conexas.
- [ ] **F7.4** NIC 12 y fiscalidad peruana: diferencias temporarias, impuesto diferido, Registro de Activos Fijos y conciliaciones.
- [ ] **F7.5** Préstamos NIIF 9: costo amortizado, TIR/TIE, comisión, gracia, cronograma, devengos y asientos.
- [ ] **F7.6** EEFF NIIF 18 con categorías y subtotales, revelaciones y reportes alternativos NIIF PYMES/NPIF.

### Fase 8 — Liberación
- [ ] **F8.1** Completar pruebas y optimización de rendimiento/seguridad.
- [ ] **F8.2** Automatizar CI/CD, migraciones y recuperación.
- [ ] **F8.3** Validar preview, documentación y aceptación final.

### Resumen editable de seguimiento

| Indicador | Valor inicial | Método de actualización |
|---|---|---|
| Actividades aprobadas | **0/27 (0%)** | Contar `[x]` en sección 10 |
| Fases aprobadas | **0/9** | Registrar puerta de calidad y evidencia de sección 8 |
| Hitos aprobados | **0/4** | Registrar aprobación de H1–H4 |
| Fase actual | **F0 — EN PROGRESO, sin aprobaciones** | [Registro de diagnóstico](docs/FASE-0-DIAGNOSTICO.md) |
| Bloqueos críticos conocidos | **R01, R02, R10 (contraseña antigua aún pendiente de rotación)** | Actualizar matriz de riesgos y evidencia |
| Próxima acción prioritaria | **Rotar contraseña expuesta, revisar tests transaccionales y verificar recuperación** | Se usará exclusivamente la BD de desarrollo actual |

---

### Continuación 2026-10-19 — Base única de desarrollo

- **Decisión de trabajo:** `contabilidad-database` es la única base durante el desarrollo; no se creará BD aislada ni nueva rama. Se permiten pruebas transaccionales y verificaciones SQL controladas; **no** se permiten `migrate:fresh`, `db:wipe`, truncados ni seeders destructivos sobre los datos existentes.
- **Cambio aplicado en GitHub:** contraseña literal retirada de `backend/phpunit.xml` en commit [cc363a17](https://github.com/jwichpas/contabilidad/commit/cc363a17a351114bc61c3a29f1901f127b095b16). **Pendiente crítico:** rotar la contraseña real y actualizar conexiones; retirar del HEAD no borra el historial.
- **Base validada mediante SELECT:** 2 asientos, 4 partidas, cero descuadres MN/ME, cero orfandades verificadas; 1,212 cuentas operativas y solo 21 de seis dígitos. Ninguna prueba automatizada ejecutada aún.
- **Normativa verificada:** [Resolución CNC 002-2026-EF/30](https://busquedas.elperuano.pe/dispositivo/NL/2550786-1), PCGE 2026 obligatorio desde 2028, adopción anticipada posible; README principal corregido.
- **Evidencias y pendientes:** [Registro actualizado de Fase 0](docs/FASE-0-DIAGNOSTICO.md). F0.1–F0.3 aún abiertas; 0/27 marcadas; F1 no iniciada.

### Actualización 2026-10-19 — F0: preflight, respaldo y matriz funcional

- Se agregó [preflight estático de F0](backend/scripts/phase0-preflight.php), independiente de Laravel/Composer/PostgreSQL. Validada su sintaxis en PHP 8.4 y ensayados dos casos simulados: 8 verificaciones satisfactorias y detección de contraseña inline. **Aún no se ejecutó sobre un checkout completo del repositorio con credenciales reales.**
- Se agregó [script protegido de respaldo](backend/scripts/phase0-backup.sh): exporta `public` en formato custom de `pg_dump`, genera catálogo con `pg_restore --list` y SHA-256 fuera del repositorio. Sintaxis Bash comprobada; **no se ha ejecutado contra la BD ni se ha hecho prueba de restauración**.
- [Matriz de estado funcional de F0](docs/FASE-0-MATRIZ-FUNCIONAL.md): inventario por módulo con evidencia, rutas ya existentes y ausencias localizadas. Precaución: Vue ya incluye selector global de empresa y un editor de asientos; **no rehacer sin pruebas**.
- [Comando Artisan F0](backend/app/Console/Commands/AuditPhaseZero.php) mejorado: comprueba el proyecto correcto, abre transacción de solo lectura y compara nombres de migraciones.
- Nuevos hallazgos para **F2**: frontend `JournalEntryView.vue` usa `parseFloat`/`toFixed`; motor de extornos disponible como servicio, pero sin ruta API localizada.
- **Sin modificación de esquema**, migraciones o datos en Supabase. F0.1, F0.2 y F0.3 siguen **EN PROGRESO** (0/27 tareas aprobadas).

### Actualización 2026-10-19 — Laravel como único gestor de esquema

- **Regla obligatoria:** las tablas, índices, restricciones, triggers, políticas RLS y demás cambios DDL se crean o modifican desde **migraciones Laravel**, jamás desde el editor SQL de Supabase. Las verificaciones `SELECT` y advisories de Supabase continúan permitidas.
- Política formal: [docs/ARQUITECTURA-BD-LARAVEL.md](docs/ARQUITECTURA-BD-LARAVEL.md). Se mantendrá exclusivamente la base de desarrollo `contabilidad-database` y la rama `main`.
- Nuevo comando `php artisan contabilidad:auditar-fase0` en `backend/app/Console/Commands/AuditPhaseZero.php`, que realiza consultas de solo lectura en la conexión administrativa Laravel. **La sintaxis PHP fue comprobada localmente, pero el comando todavía no se ha ejecutado desde el proyecto desplegado.**
- `backend/config/database.php`: `pgsql_migrations` ya no hereda el `DATABASE_URL` de la conexión de aplicación; `backend/phpunit.xml` dejó de fijar host y usuario privilegiado; `backend/.env.example` diferencia roles y puertos.
- Catálogo PostgreSQL confirmado por SQL de solo lectura: **31 tablas, 94 índices, 5 funciones, 6 triggers no internos, 14 políticas y 14 migraciones Laravel**.
- F0.1–F0.3 siguen abiertas por respaldos sin verificar, ejecución de tests bloqueada y revisión funcional incompleta. **Fase 1 no iniciada.**

### Actualización 2026-10-19 — Workflow estático de verificación

- Se creó [`phase0-static-baseline.yml`](.github/workflows/phase0-static-baseline.yml) para comprobar Vue y Laravel **sin conexión a Supabase** ni despliegues.
- El [primer workflow](https://github.com/jwichpas/contabilidad/actions/runs/39354337434) acabó fallido porque no se asignó ningún runner ni se ejecutaron pasos; no es evidencia de error del código.
- Para evitar fallos repetidos en cada push se mantuvo **solo ejecución manual**, pendiente de resolver disponibilidad de GitHub Actions.
- Sigue pendiente rotar la credencial real de la BD; `backend/phpunit.xml` ya no tiene contraseña inline.
- **F0 sigue abierta (0/27)**. Ver [diagnóstico completo](docs/FASE-0-DIAGNOSTICO.md).

### Actualización 2026-10-19 — Inicio Fase 0

- **Estado:** EN PROGRESO; F0.1, F0.2 y F0.3 con trabajo de inventario en curso, ninguna completada.
- **Evidencias:** [docs/FASE-0-DIAGNOSTICO.md](docs/FASE-0-DIAGNOSTICO.md).
- **Descubrimientos críticos:** R10 (contraseña de BD en PHPUnit), R11 (tests apuntan a BD compartida), R01/R02 reconfirmados.
- **Puerta pendiente:** controles de respaldo/recuperación de la BD existente, comparación no destructiva de migraciones, ejecución real de tests y validación restante de PLE/SIRE.

### Correcciones implementadas en código — aún sin aplicar a PostgreSQL (2026-10-19)

- **Middleware multiempresa:** `backend/app/Http/Middleware/SetTenantContext.php` ahora usa `TenantContext::run()` para ejecutar verificación de membresía y controlador en una misma transacción; evita el contexto de sesión persistente y elimina la caché de autorizaciones de 60 s. Commit [2bb34d6](https://github.com/jwichpas/contabilidad/commit/2bb34d6b98a3d7bd6ffe57609de00410d4fd7cfa). **Integración PostgreSQL pendiente de probar**.
- **Migración Laravel de protección de tablas:** `backend/database/migrations/2026_10_19_000000_restrict_supabase_data_api_grants.php` retira los accesos de tablas y secuencias a los roles públicos `anon` y `authenticated`, manteniendo los permisos de `app_backend`. Commit [75a6407](https://github.com/jwichpas/contabilidad/commit/75a64070aad34fe68c6cc272bd78232fae903304). **Pendiente de ejecutar desde Artisan; no se ejecutó ninguna migración desde Supabase.**
- **Pruebas incorporadas:** `backend/tests/Unit/SetTenantContextMiddlewareTest.php` y `backend/tests/Feature/Security/PublicDataApiPrivilegesTest.php`. Se amplió `phpunit.xml` para registrar el suite Unit y el workflow para ejecutarlo cuando haya runner. **Resultados de ejecución pendientes**.
- **Último SELECT de validación de riesgo:** 14 migraciones aplicadas, 31 tablas, 18 sin RLS, y ambas funciones public API continúan con privilegio SELECT sobre 31 tablas. No indicar que la vulnerabilidad está cerrada hasta aplicar la migración Laravel y ejecutar su prueba.
- **Estado de gobernanza:** Fase 0 continúa EN PROGRESO; reparaciones críticas de Fase 1 iniciadas a nivel de código, no aprobadas ni aplicadas. Checklist sigue en 0/27 completadas.

### Ejecución de correcciones de código — 2026-10-19

**Se realizaron cambios funcionales en `main`, no solo documentación.** Sin cambiar directamente el esquema de Supabase.

| Área | Cambio en código | Evidencia y límites |
|---|---|---|
| Seguridad multiempresa | `SetTenantContext` ejecuta el flujo en una transacción con contexto local y comprueba la membresía por petición (sin caché de 60 s) | [Commit 2bb34d6](https://github.com/jwichpas/contabilidad/commit/2bb34d6b98a3d7bd6ffe57609de00410d4fd7cfa); pruebas de integración aún pendientes |
| Permisos de datos | Nueva migración **Laravel**, `2026_10_19_000000_restrict_supabase_data_api_grants.php`, retira GRANT de roles públicos; prueba ajustada para usar `pg_catalog.has_table_privilege` | [Migración](backend/database/migrations/2026_10_19_000000_restrict_supabase_data_api_grants.php). **No aplicada**. La BD todavía expone SELECT a ambos roles públicos en 31 tablas |
| Precisión backend | `CreateJournalEntryController` elimina todos los casts a `float` y `number_format`, valida 4/6 decimales y utiliza el objeto BCMath `Decimal` | [Controlador](backend/app/Http/Controllers/Api/V1/Accounting/CreateJournalEntryController.php), commit `e605d0e`; falta prueba Laravel real |
| Compatibilidad PHP | `Decimal.php` ya no usa constantes tipadas de PHP 8.3; así respeta el mínimo PHP 8.2 de Composer | [Objeto decimal](backend/app/Support/Math/Decimal.php), commit `2d3bacb` |
| Precisión frontend | Vue serializa montos y tasas como **strings**, calcula totales/conversiones/correcciones con `SystemDecimal` / `BigInt` y compara saldos a 4 decimales sin `parseFloat` | [Editor](frontend/src/modules/accounting/views/JournalEntryView.vue), [utilidad](frontend/src/shared/types/numeric.types.ts). Compilación y pruebas E2E pendientes |
| Pruebas preparadas | Unitarias de middleware y Decimal en Laravel; pruebas de `BigInt` en Node; workflow actualizado para incluirlas | [Backend Unit](backend/tests/Unit/DecimalExactnessTest.php) y [Frontend smoke](frontend/scripts/decimal-smoke.test.mjs). GitHub Actions sigue sin runner verificado |

**Verificación de PostgreSQL por SQL de solo lectura:** 14 migraciones registradas; 31 tablas; 18 sin RLS; 2 asientos / 4 partidas; el acceso SELECT de `anon` y `authenticated` sigue vigente en las 31 tablas. **No marcar R01 solucionado** hasta ejecutar la migración con Artisan en la misma BD, comprobar que se mantienen los permisos `app_backend` y pasar los tests.

**Avance parcial de F1/F2 en código:** estas correcciones se priorizaron por vulnerabilidades ya verificadas, sin declarar cerrada Fase 0, F1 o F2. La ejecución completa requiere un runner Laravel disponible; el único proyecto de desarrollo continúa siendo `contabilidad-database`. No ejecutar DDL desde Supabase.

### Continuación F0/F2 — cierre de pérdida de precisión y credenciales administrativas

- **Corrección en motor contable:** `Decimal::from()` ya **rechaza cifras decimales significativas que exceden la escala**, en lugar de truncarlas silenciosamente con BCMath. Se permiten ceros redundantes. [Commit d0a30dd](https://github.com/jwichpas/contabilidad/commit/d0a30dde01ef3427596c1c880c286207e9b733ed).
- **Pruebas nuevas:** `backend/tests/Unit/DecimalExactnessTest.php` verifica importes de 4 y tipos de cambio de 6 decimales, incluso cuando el exceso contiene cifras no cero. [Commit 7789224](https://github.com/jwichpas/contabilidad/commit/778922457133b8ce12b2e9161b5790e4efe55544). Se probaron localmente 4 casos de la **lógica de escala aislada** con PHP, 4/4 correctos; **no se ejecutó PHPUnit**, dado que no está disponible Composer/BCMath ni el runner remoto.
- **Credenciales separadas:** `config/database.php` deja de heredar `DB_HOST`, `DB_USERNAME` o `DB_PASSWORD` de la conexión de aplicación en `pgsql_migrations`. Requiere explícitamente sus variables `DB_MIGRATIONS_*`. [Commit 3a92751](https://github.com/jwichpas/contabilidad/commit/3a92751148fc487cbdf1ecfd4ca841c147d36e19b9251525c6b7).
- **Preflight fortalecido:** rechaza la configuración que vuelva a heredar credenciales del backend. [Commit ad386d3](https://github.com/jwichpas/contabilidad/commit/ad386d385534eda6b7a1f1fd4ca841c147d36e8e).
- **Bloqueos abiertos:** rotar contraseña expuesta y actualizar entorno Laravel; disponer de runner para `composer test`, `php artisan contabilidad:auditar-fase0` y `pnpm build`; aplicar la migración de permisos **solo mediante Artisan**, no desde Supabase. No se ha aplicado una nueva migración ni alterado tablas.
- **Estado:** F0 sigue `EN PROGRESO`; **F2 tiene correcciones en código sin aprobación**. No marcar tareas completas hasta ejecutar y registrar resultados.

### Verificación adicional de seguridad — 2026-10-19

- Migración Laravel pendiente `2026_10_19_000000_restrict_supabase_data_api_grants.php`: controla permisos de tablas, secuencias y ejecución de funciones `public`, preservando acceso de `app_backend`.
- Se corrigió una consulta PostgreSQL de secuencias mediante una CTE `MATERIALIZED`; comprobación por `SELECT` correcta. [Commit](https://github.com/jwichpas/contabilidad/commit/8e4c580d9e99fd5fb4f3eceb993d37f40ff89d80).
- Archivo PHP exacto de GitHub verificado por SHA de objeto Git, sintaxis PHP válida, **7/7 escenarios simulados** (aceptación, rechazo de proyecto/rol, y rollback por permisos persistentes o pérdida de acceso del backend). **No son pruebas sobre la base real**.
- La base Supabase reporta **14 migraciones aplicadas; la migración de seguridad aún no figura en `public.migrations`**. El script no se ha ejecutado con Artisan.
- Bloqueo real: ausencia de un ejecutor Laravel operativo y de conexión administrativa con credenciales renovadas. No ejecutar DDL desde el editor de Supabase, no dar la seguridad por aprobada y mantener F0/F1 abiertas.

### Incorporación de perfil empresarial, sectores y obligaciones SUNAT — 2026-10-19

- **Origen:** solicitud de contemplar los cuatro regímenes que aparecen en la imagen informativa, más segmentación de empresas por actividad (comercio, servicios, ganadería, industria, etc.).
- **Diagnóstico confirmado:** `company_settings.tax_regime` ya existe con `GENERAL`, `MYPE_TRIBUTARIO`, `RER` y `ESPECIAL`; Vue presenta selector tributario y booleanos SIRE/PLE. **No están implementados** forma jurídica, sector/CIIU, perfiles de ingresos/UIT ni motor de libros exigibles.
- **Requerimiento obligatorio añadido a F3/F5/F6:** [docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md](docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md). Separar naturaleza legal, régimen tributario, segmento económico y nivel de ingresos/obligaciones anuales, con reglas por vigencia SUNAT.
- **Normativa:** https://emprender.sunat.gob.pe/comprobantes-libros/registros-libros-electronicos/libros-registros-contables-obligatorios y régimen General: https://emprender.sunat.gob.pe/ruc/regimenes-tributarios-mype/regimen-general-renta-rg.
- **Ejecución:** especificación comprometida en `main`, **sin nueva migración ejecutada ni cambios a Supabase**. La migración pendiente de seguridad continúa pendiente de Artisan.

### Control de integración pendiente — clasificación económica de empresas

- **Dos migraciones Laravel preparadas y en orden de nombre:** `2026_10_19_000000_restrict_supabase_data_api_grants.php` (seguridad) y `2026_10_19_010000_add_company_economic_profile.php` (perfil económico). Ambas **sin aplicar**; `public.migrations` aún muestra **14** y no existen la nueva tabla ni la columna `legal_form`.
- **Cambio de API ya integrado:** `GET/PUT /api/v1/company/settings` conserva sus rutas existentes; respuesta adicional `economic_profile_ready` evita errores antes de migrar. La actualización económica es atómica e incluye actor `created_by/updated_by` y auditoría de la operación.
- **Protección tenant:** nueva tabla con `company_id`, FKs, políticas RLS `ENABLE/FORCE`, permisos de `app_backend`, unicidad de CIIU por empresa e índice único parcial que impide dos actividades principales.
- **Vue integrado:** pestaña «Tributario & SIRE» con forma jurídica, sector, CIIU, actividades secundarias y selección de principal. Si falta la migración, muestra aviso de configuración pendiente y permite usar la configuración previa. Al cambiar de empresa descarta cargas atrasadas.
- **Tests sin conexión:** cinco casos `backend/tests/Unit/CompanyEconomicProfileTest.php` para invariantes; falta ejecutar Pest y `pnpm typecheck/build` por indisponibilidad del runner. No considerar las pruebas aprobadas sin resultados.
- **Alcance pendiente:** esto es la **edición del perfil de empresa existente**, NO el wizard de alta completo ni el motor de libros obligatorios; esos continúan en F3/F5/F6. No se ha efectuado despliegue ni DDL por Supabase.

### Avance en Vue y API — Asistente de alta de empresas (2026-10-20)

- **Interfaz Vue terminada en código:** [`CompanyOnboardingWizard.vue`](frontend/src/modules/company/views/CompanyOnboardingWizard.vue) con cinco pasos (identificación → actividad económica/CIIU → tributación → monedas/PCGE → confirmación), progreso, errores por campo, edición de actividades principal/secundarias, resumen final y borrador de sesión.
- **Integración real en la navegación:** [`App.vue`](frontend/src/App.vue), [sidebar](frontend/src/shared/layout/AppSidebar.vue), encabezado y módulo `company-create` con URL `#nueva-empresa`. Una creación confirmada por la API se incorpora al selector de empresas y se activa.
- **API Laravel preparada:** `GET /api/v1/tenant/company-onboarding/capabilities` y `POST /api/v1/tenant/companies`, protegidos con Sanctum y membresía activa `ADMIN`, con validación RUC, CIIU, régimen, ejercicio, ingresos, monedas y adopción PCGE.
- **Alta transaccional:** al ejecutarse el POST se prevé crear empresa, membresía ADMIN, `company_settings`, monedas, actividades y copia privada de la plantilla PCGE como una sola unidad; en caso de error, rollback. Auditoría del evento registrada en el flujo. **Sin ejecución real todavía**.
- **Nueva migración Laravel pendiente (actualizada sin aplicar):** `2026_10_19_010000_add_company_economic_profile.php` ahora incorpora `initial_fiscal_year`, `income_reference_year`, `annual_income` e `initial_pcge_version`, además de forma jurídica y actividades.
- **Control de habilitación:** el asistente puede consultarse y guardar borradores antes de migrar, pero la confirmación de alta permanece deshabilitada hasta que `CompanyOnboardingGuard` confirme que existe el esquema y que el usuario es ADMIN. No se simulan catálogos de monedas ni plantillas PCGE.
- **Pruebas preparadas:** [`company-onboarding.test.mjs`](frontend/scripts/company-onboarding.test.mjs) (6 casos), [`CompanyOnboardingRulesTest.php`](backend/tests/Unit/CompanyOnboardingRulesTest.php) y existentes de perfil. Ejecutar `npm run test:unit`, `npm run typecheck`/`npm run build` en frontend y pruebas Pest Laravel cuando dispongamos de runner.
- **Estado honesto:** IMPLEMENTADO EN CÓDIGO; **no desplegado, no compilado ni probado end-to-end**. Supabase se conserva sin DDL. F3.1 sigue **EN VALIDACIÓN/PARCIAL** y no se marca `[x]` hasta crear empresa ficticia y registrar primer asiento desde UI.

### Estado verificado por SQL de solo lectura, F0 y F3 (2026-10-20)

- **Migraciones aplicadas por el usuario:** seguridad (batch 6) y nuevo perfil económico (batch 7); el inventario es **16/16 aplicadas**, sin nuevas pendientes al momento de la consulta.
- **Permisos R01 observados:** `anon` y `authenticated` sin permisos efectivos sobre las **32 tablas, secuencias y 5 funciones del esquema public**. Accesos `app_backend` conservados e incrementados solo para nueva tabla/secuencia (31 tablas con SELECT; 20 secuencias con USAGE; 5 funciones con EXECUTE).
- **Perfil empresarial:** cinco columnas añadidas a `company_settings`, tabla `company_economic_activities`, FKs y unicidades presentes, RLS habilitado/forzado y política tenant de `app_backend` confirmadas.
- **Integridad de movimientos:** 2 asientos, 4 partidas; **ningún asiento desbalanceado ni inválido**, ninguna partida huérfana ni referencia de cuenta inválida en el control F0.
- **Pendientes verificables:** ejecutar la auditoría Laravel de solo lectura `php artisan contabilidad:auditar-fase0`; comprobar credenciales/rutas `app_backend` y probar funcionalmente alta y selector de empresas; revisar 18 tablas sin RLS. Contraseñas rotadas/recuperación de respaldo aún por certificar.
- **Corrección previa a la siguiente ejecución de F0:** CTE `MATERIALIZED` en `AuditPhaseZero.php` y `PublicDataApiPrivilegesTest.php` para evitar errores PostgreSQL de secuencias. SQL comprobado por consulta de solo lectura. Es necesario `git pull --ff-only` en el equipo del usuario para tomar ambos archivos.
- **Criterio de aprobación:** las migraciones exitosas **no cierran** por sí mismas la Fase 0. Los controles de pruebas de aislamiento, integridad y recuperación siguen abiertos.

### Avance en código de Fase 3 — Perfil económico multiempresa (2026-10-19)

- **Preparado en `main` (NO aplicado en PostgreSQL):** [migración Laravel `2026_10_19_010000_add_company_economic_profile.php`](backend/database/migrations/2026_10_19_010000_add_company_economic_profile.php), que incorpora `company_settings.legal_form` y `company_economic_activities` con códigos sectoriales/CIIU, una actividad principal, varias secundarias, validaciones SQL, RLS forzado y permisos exclusivos de `app_backend`. No modifica las migraciones antiguas ni los registros existentes.
- **Backend desarrollado:** modelo tenant [`CompanyEconomicActivity`](backend/app/Models/CompanyEconomicActivity.php), catálogo y reglas [`CompanyEconomicProfile`](backend/app/Support/Company/CompanyEconomicProfile.php), consultas GET y guardado PUT transaccional en controladores de configuración; rechaza perfiles inválidos, códigos CIIU duplicados y NRUS en personas jurídicas.
- **Frontend desarrollado:** [`CompanySettingsView.vue`](frontend/src/modules/company/views/CompanySettingsView.vue) muestra forma jurídica, macrosector, CIIU, principal/secundaria y permite añadir hasta diez actividades. **Compatibilidad con despliegue antes de migrar:** API envía `economic_profile_ready=false`; Vue muestra aviso y no intenta consultar ni guardar columnas/tablas faltantes.
- **Pruebas preparadas:** [`CompanyEconomicProfileTest.php`](backend/tests/Unit/CompanyEconomicProfileTest.php) contiene cinco grupos de casos de validación sin necesitar base de datos. **Pendiente ejecutar PHPUnit y compilar Vue**; GitHub Actions sigue sin runner disponible.
- **Corrección multiempresa:** el formulario recarga al cambiar `activeCompanyId` y descarta respuestas atrasadas de otra empresa.
- **Mañana:** las migraciones se ejecutan **en orden desde Artisan**, primero la de seguridad `2026_10_19_000000_restrict_supabase_data_api_grants`, después `2026_10_19_010000_add_company_economic_profile`. Verificar tablas/permisos y ejecutar pruebas con la única base `contabilidad-database`.
- **Estado formal:** F3.1 **en implementación, no aprobada**. F0/F1/F2/F3 no han superado sus puertas de calidad; el checklist maestro permanece sin marcas hasta contar con evidencias reales.

### Análisis tributario SUNAT — SFE y SFMB exportadores (2026-10-20)

- **Especificación vinculante F3/F5/F6:** [docs/F6-IGV-SFE-SFMB-EXPORTADORES.md](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md), derivada de los arts. 33–35 del TUO del IGV, reglamento DS 126-94-EF y orientación oficial SUNAT de compensación/devolución.
- **Corrección jurídica:** exportaciones calificadas son operaciones **inafectas** IGV, no gravadas al «0 %». El SFE de crédito fiscal admitido se deduce del IGV bruto de ventas locales; el remanente SFMB no implica autorización automática de compensación/devolución.
- **Empresas mixtas y de cualquier sector:** sector económico, régimen, actividad exportadora y operaciones validadas son dimensiones diferentes. El SFMB requiere sustentos de periodo; una empresa solo local mantiene saldo ordinario arrastrable salvo otros supuestos normativos.
- **Hito F6.4 agregado:** motor mensual, límite tasa IGV+IPM sobre exportación computable, arrastres entre periodos, diferencias entre factura y embarque, registro de servicios cuando corresponda, PDB, estados de solicitudes, compensación y caja.
- **Estado:** exclusivamente análisis/arquitectura y criterios de pruebas registrados en GitHub; **no se ha desarrollado aún el motor SFMB, migraciones específicas, UI fiscal ni modificaciones de tablas.** No declarar F6.4 completo hasta pruebas comparadas con casos SUNAT y conciliación contable.

## 11. Referencias y control documental

- Código y arquitectura actual: `backend/`, `frontend/`, `backend/database/migrations/`, `backend/tests/`, `README.md`.
- **Política obligatoria de esquema Laravel:** [docs/ARQUITECTURA-BD-LARAVEL.md](docs/ARQUITECTURA-BD-LARAVEL.md).
- **Matriz funcional F0:** [docs/FASE-0-MATRIZ-FUNCIONAL.md](docs/FASE-0-MATRIZ-FUNCIONAL.md).
- **Requerimiento vinculante empresa/sector/libros SUNAT:** [docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md](docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md).
- **Preflight y respaldo F0:** `php backend/scripts/phase0-preflight.php` y `backend/scripts/phase0-backup.sh` (este último no ejecutado aún).
- **Comando de diagnóstico F0:** `php artisan contabilidad:auditar-fase0` (solo lectura; sin mutaciones).
- Referencias internas: `plan-contable-2019.md`, `plan-contable-2026.md`, `tablas-sunat.md`.
- Guías oficiales a comprobar en F0: [SUNAT](https://www.sunat.gob.pe/), [MEF — Consejo Normativo de Contabilidad](https://www.gob.pe/mef), [Supabase RLS](https://supabase.com/docs/guides/database/postgres/row-level-security), [Laravel](https://laravel.com/docs/11.x).
- **Control de versiones:** v1.7 — 2026-10-19 — Control estricto de decimales y credenciales de migración; v1.6 — 2026-10-19 — Cambios funcionales de seguridad y precisión en Laravel/Vue; v1.5 — 2026-10-19 — Preflight, respaldo no destructivo y matriz F0; v1.4 — 2026-10-19 — Laravel como único gestor de esquema y auditoría F0 read-only; v1.3 — 2026-10-19 — Workflow CI estático y riesgo runner; v1.2 — 2026-10-19 — Política de base única y F0 SQL; v1.1 — 2026-10-19 — Inicio F0 y enlace de diagnóstico; v1.0 — 2026-10-19 — plan aprobado para documentación previa a Fase 0. Los ajustes futuros deben registrar fecha, motivo y relación con decisiones/hallazgos.

**Estado al 2026-10-19: _FASE 0 EN PROGRESO SOBRE LA BD DE DESARROLLO ÚNICA; 0/27 ACTIVIDADES APROBADAS; HITOS H1–H4 PENDIENTES_.**
