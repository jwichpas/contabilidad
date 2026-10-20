# Fase 0 — Diagnóstico y línea base (registro 001)

> **Proyecto:** `jwichpas/contabilidad` · **Base:** Supabase `contabilidad-database` (`bnjwxphwtkhblvhsnylj`) · **Rama:** `main`  
> **Fecha:** 2026-10-19 (America/Lima) · **Estado:** **EN PROGRESO**, no aprobada  
> **Documento rector:** [README-PLAN-MAESTRO.md](../README-PLAN-MAESTRO.md), apartado «FASE 0».  
> **Alcance actual:** consultas SQL de solo lectura en Supabase, actualización de documentos, retiro de credencial literal de PHPUnit y creación de workflow de chequeos estáticos en GitHub. No se ejecutaron migraciones, escrituras en BD, cambio de permisos ni despliegues.

## Incidencia SQLSTATE 42883 boolean=integer — regresión aprobada (2026-10-20)

El usuario ejecutó `php -l` sin errores en `ListOperationalAccountsController.php`, `AccountingAccount.php` y `ReadOnlyTenantApiSmokeTest.php`, seguido de:

```text
php artisan test tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php
PASS — 7 tests, 29 assertions, 19.56 s
```

Las dos pruebas incorporadas verifican que `GET /api/v1/accounting/accounts/operational`, el árbol activo y los principales catálogos/maestros devuelvan 200 sin `SQLSTATE[42883]`. El commit [22702cf](https://github.com/jwichpas/contabilidad/commit/22702cf65a5dab451ebb3b95a0bcb8ebe6e2f9fc) corrigió **29 comparaciones booleanas SQL en 19 archivos** mediante `IS TRUE`/`IS FALSE`; no se modificaron políticas RLS, migraciones, `PDO::ATTR_EMULATE_PREPARES` ni datos de PostgreSQL.

**Consulta de comprobación adicional en Supabase (SELECT):** `companies=1`, `users=1`, `memberships=1`, `accounting_accounts=1764`, `journal_entries=2`, `journal_entry_lines=4`, `migrations=18`, `personal_access_tokens=29`, `audit_logs=40`. Los conteos de tokens y auditorías son mayores que líneas base antiguas y pueden variar con los inicios de sesión; no atribuir sus variaciones a pruebas GET sin evidencia.

**Validación visual pendiente:** con Laravel en puerto 8010 y Vite 5180, comprobar en navegador que Libro Diario → Nuevo Asiento cargue las cuentas operativas, permita seleccionarlas y no registre HTTP 500. La aprobación de 7 pruebas no equivale a prueba E2E.

Detalle técnico: [FASE-0-SQL-BOOLEAN-FIX.md](FASE-0-SQL-BOOLEAN-FIX.md).

## Alta ERP aprobada para preparación; escritura pendiente — 2026-10-20

El usuario autorizó preparar el alta de `elcontador@gmail.com` en Laravel, ligado a la empresa ficticia existente. **No autorizó aún el rol y nombre final ni la escritura sin confirmación; no se ejecutó ningún INSERT/UPDATE.** La cuenta aparece en la pantalla de Supabase Auth (`auth.users`), pero el script local de Laravel confirmó `[USUARIO] Correo NO registrado en la BD de Laravel` (`public.users`). El limitador de login ya usa `CACHE_STORE=file`.

**SQL SELECT independiente:** empresa ID=1 activa, razón social `Corporación Peruana de Distribución S.A.C.`; 1 usuario, 1 membresía activa con rol `CHIEF_ACCOUNTANT`, 28 tokens, 2 asientos, 18 migraciones. El conteo de auditorías ahora es 39 (no usar 34 como baseline permanente). Privilegios `app_backend`: SELECT/INSERT/UPDATE para `users` y `company_user`, USAGE en secuencias; `company_user` tiene ENABLE/FORCE RLS; los roles permitidos son ADMIN, CHIEF_ACCOUNTANT, ACCOUNTANT, DATA_ENTRY y AUDITOR. La FK compuesta obliga a crear primero usuario sin empresa activa, después membresía bajo contexto RLS LOCAL, después establecer `current_company_id`.

**Código preparado en main:** `backend/scripts/phase0-provision-erp-user.php` con preflight de solo lectura predeterminado, validación de empresa fija 1, email único, rol explícito, base contada con RLS correcto y guardias de contexto; modo `--apply` exige dos variables de entorno específicas, contraseña fuerte nueva distinta de Supabase Auth, transacción y comprobación antes de COMMIT. El script no toca los usuarios existentes y no importa `auth.users`. No hay evidencia de `php -l`/preflight local aún. Más detalles en [FASE-0-ALTA-USUARIO-ERP.md](FASE-0-ALTA-USUARIO-ERP.md).

**Próxima puerta:** solicitar aprobación concreta de rol `ACCOUNTANT` y nombre visible provisional `Contador de Desarrollo`; luego ejecutar preflight local y, por último, dar instrucciones de aplicación controlada. No ejecutar seeders/migraciones ni resetear el usuario antiguo.

## Incidencia de login HTTP 500 en desarrollo — 2026-10-20

**Síntoma reportado:** el navegador (Vue/Vite en localhost:5180) recibe `POST /api/v1/auth/login` con **HTTP 500** al tener Laravel activo en `0.0.0.0:8010`. El comando `php artisan tinker` no está disponible (el paquete de Tinker no figura en `composer.json`), por lo que no debe instalarse únicamente para diagnosticar.

**Diagnóstico respaldado por evidencia:** `backend/routes/api.php` protege login con `throttle:10,1`; `backend/config/cache.php` no existe (se aplican valores de Laravel 11); el archivo de ejemplo de entorno no definía `CACHE_STORE`; un **SELECT** de Supabase confirmó `public.cache=false` y `public.cache_locks=false`. Si el backend local utiliza `CACHE_STORE=database` (valor habitual por defecto), el limitador de intentos puede fallar con HTTP 500 al acceder a una tabla de caché ausente. **Causa probable, NO confirmada aún**: falta comprobar el valor efectivo de `CACHE_STORE` y la excepción concreta del entorno local. No presumir que el correo/contraseña son incorrectos a partir de HTTP 500.

**Acciones implementadas en GitHub/main (NO ejecutadas en equipo del usuario):**

- `backend/.env.example`: documenta `CACHE_STORE=file` para desarrollo, `APP_URL=http://localhost:8010`. No modifica `backend/.env` local ni tokens, datos o política RLS.
- `backend/scripts/phase0-login-diagnostics.php`: comando PHP CLI con bootstrap Laravel, **solo SELECT**, verifica driver cache/limitador, rol `app_backend`, presencia de tabla `public.cache` y presencia opcional de un correo sin imprimirlo, sin hashes ni contraseñas. Uso: `php scripts/phase0-login-diagnostics.php [correo]`.
- `frontend/src/shared/auth/useAuthSession.ts`: diferencia HTTP **500**, **429** y **422**, sin mostrar errores SQL internos ni atribuir los 500 a credenciales.

**Procedimiento recomendado:** actualizar `main` con `git pull --ff-only`, confirmar/ajustar manualmente `CACHE_STORE=file` en `backend/.env`, ejecutar `php artisan config:clear`, reiniciar el servidor Laravel en 8010 y probar login una vez. Si persiste, ejecutar `php -l scripts/phase0-login-diagnostics.php` y `php scripts/phase0-login-diagnostics.php "correo@ejemplo.com"` para obtener diagnóstico sin Tinker; compartir solo la salida no sensible. El correo de la cuenta y la contraseña **no deben registrarse en Git** ni enviarse en capturas posteriores.

**Estado:** pendiente verificar en Windows/servidor. No ejecutar migraciones, Tinker/paquetes adicionales, seeds, reseteos de usuario ni cambios DB. El preflight frontend anterior sigue aprobado, pero la nueva UX HTTP 500 requiere revalidación. Fase 0 continúa en progreso.

## Frontend compilado y pruebas 16/16 aprobadas — 2026-10-20

**Validación real ejecutada por el usuario en Windows PowerShell** (`C:\Proyectos\contabilidad\frontend`), después de sincronizar los cambios con `main`:

- `npm run test:unit` → **16 pruebas aprobadas, 0 fallos, 0 omitidas**. Incluye cinco pruebas nuevas de sesión HTTP 401 / no exposición de credenciales / protección ante respuestas tardías, seis validaciones del asistente de creación de empresas y cinco controles de decimales contables.
- `npm run typecheck` → `vue-tsc --noEmit`, **finalizó sin errores**.
- `npm run build` → `vue-tsc -b && vite build`, **PASS**; Vite 6.4.4 transformó **1.615 módulos** y compiló en **2,66 segundos**; `dist/index.html` y recursos CSS/JS generados satisfactoriamente.

**Puerta frontend cerrada para:** pruebas automatizadas existentes, tipos TypeScript, build de producción. **No afirmar prueba E2E/navegador ni despliegue**: queda pendiente inspeccionar desde una pestaña real que 401 muestre login y aviso, que 403 no cierre sesión, que se conserven borradores al caducar y se descarten al cambiar de usuario. Si se prueba manualmente, no pegar tokens ni contraseñas en la conversación.

**Seguridad PostgreSQL:** inspección `SELECT` de `pg_proc` sobre las cinco funciones reportadas por Supabase confirma `SECURITY DEFINER=false` y `proconfig=NULL`; las referencias actuales a tablas usan nombres de esquema explícitos. Auditoría individual y propuesta sin ejecución: [FASE-0-AUDITORIA-FUNCIONES.md](FASE-0-AUDITORIA-FUNCIONES.md). No se ejecutó DDL.

**Estado F0:** pendientes comprobación visual en navegador, estrategia de recuperación/respaldo aprobada y eventual remediación segura de advertencias SQL; permanece EN PROGRESO.

## Validación final de TTL Sanctum 12 h — APROBADA (2026-10-20)

**Resultado real informado por el usuario en PowerShell, tras sincronizar código de `main`:**

- `php -l config/sanctum.php`, `php -l app/Http/Controllers/Api/V1/Auth/LoginController.php`, `php -l scripts/phase0-login-rollback.php`: **sin errores de sintaxis**.
- `php scripts/phase0-login-rollback.php`: **preflight OK**, Sanctum configura **720 minutos (12 horas)**; comprobación solo lectura.
- `php scripts/phase0-login-rollback.php --execute`: **PASS**. Login HTTP 200 emitió token con `expires_at` a 12 horas; Bearer autorizó `GET /tenant/companies`; simular un token de 13 horas devolvió **HTTP 401**; logout revocó el token; reutilizar el Bearer revocado devolvió **HTTP 401**; los eventos `AUTH_LOGIN` y `AUTH_LOGOUT` se registraron dentro de la transacción; **ROLLBACK confirmado**.

**Conteos antes y después en ejecución local:** `companies=1`, `users=1`, `memberships=1`, `tokens=28`, `audits=34`, `entries=2`.

**Verificación adicional independiente por SELECT Supabase:** `companies=1`, `users=1`, `memberships=1`, `accounts=1764`, `tokens=28`, `audits=34`, `entries=2`, `lines=4`, `applied_migrations=18`. Los **28 tokens antiguos** continúan con `expires_at=NULL`; el cambio a `sanctum.expiration=720` los rechazará por antigüedad al usar un backend con esa configuración, aunque no se borren físicamente.

**Puerta de calidad cerrada:** autenticación de API Bearer, emisión y vencimiento a 12 h, token expirado 401, logout y token revocado 401, auditoría, preservación de registros al terminar. **No implica que todos los despliegues hayan actualizado la configuración ni que sesiones web/cookie tengan el mismo límite**. Tampoco acredita aún controles de recuperación, backup completo, autorización de todos los endpoints ni la suite global. Fase 0 continúa EN PROGRESO.

**Próxima prioridad F0:** elaborar y aprobar procedimiento de respaldo/recuperación de datos. Existe `backend/scripts/phase0-backup.sh`, que hace `pg_dump` de `public` a un archivo local externo al repo y verifica `pg_restore --list` y SHA-256. **No se ha ejecutado y no garantiza restauración completa de usuarios/roles/configuración fuera de `public`**. El usuario optó anteriormente por no crear un respaldo por ahora; no ejecutar exportaciones ni restauraciones sin autorización.

## Avance F0 — frontend HTTP 401 y recuperación documental (20/10/2026)

**Código nuevo en GitHub/main, NO compilado ni probado en navegador todavía:**

- `frontend/src/shared/auth/sessionInvalidation.ts`: notificación central de HTTP 401 con el Bearer realmente usado; descarta respuestas antiguas si la sesión cambió.
- `frontend/src/shared/auth/useAuthSession.ts`: invalida token, usuario, empresas y claves de sesión al recibir 401 en listados/servicios o cambio de empresa; muestra aviso de vencimiento (máximo 12 h), no confunde fallo de red con 401, comprueba siempre la vigencia del Bearer al restaurar la pestaña y evita que respuestas asíncronas de un token antiguo sobrescriban un login reciente. En expiración conserva borradores del wizard solo para el mismo usuario; logout explícito descarta el borrador. **No guarda ni publica tokens en logs.**
- `frontend/src/modules/accounting/api/httpClient.ts`: conecta los clientes de contabilidad, asientos y configuración con la invalidación central al recibir 401; 403 continúa siendo una denegación de permiso, no logout.
- `frontend/src/App.vue`: pantalla de comprobación antes de montar información contable almacenada localmente.
- `frontend/src/shared/auth/LoginView.vue`: eliminadas credenciales precargadas y botón para autorrelleno de demostración; el formulario inicia vacío. También se retiró una afirmación visual no verificada de certificación SGSI. **Si la contraseña expuesta en versiones antiguas fue utilizada por un usuario real, se debe rotar por canal seguro; eliminarla del código NO elimina el historial de Git.**
- `frontend/scripts/auth-session-expiry.test.mjs` y `frontend/package.json`: cinco pruebas unitarias nuevas de gestión de 401, comparación de tokens, ausencia de credenciales precargadas y barrera de inicio; incorporadas al comando `npm run test:unit` sin instalar nuevas dependencias.

**Pendiente de validación por el usuario:** `cd frontend; npm run test:unit; npm run typecheck; npm run build` después de `git pull --ff-only`. NO declarar PASS hasta recibir los resultados. No se ejecutaron despliegues.

**Recuperación:** nuevo documento `docs/FASE-0-PLAN-RECUPERACION.md` con alcances de backups de Supabase, limitaciones de `pg_dump --schema=public`, condiciones de RPO/RTO, restauración exclusivamente en destino aislado autorizado y una comprobación PowerShell de herramientas **sin generar respaldos**. Se respeta la decisión anterior de no crear respaldo. No se ha probado restauración.

**Avisos actuales de seguridad Supabase:** el Security Advisor reporta un aviso `function_search_path_mutable` para **cinco funciones** del esquema `public`, todas sin `SECURITY DEFINER` según el inventario anterior. Requiere análisis individual y eventual migración separada; **no se cambia search_path ni se ejecutan DDL en F0 sin procedimiento de recuperación acordado**. Referencia de remediación: https://supabase.com/docs/guides/database/database-linter?lint=0011_function_search_path_mutable

**Estado:** Fase 0 sigue **EN PROGRESO**. Autenticación y RLS ya verificadas; frontend y recuperación pendientes.

## Actualización ejecutada en el entorno Laravel — 2026-10-20

**Estado más reciente (prevalece sobre la línea base histórica del 19/10 situada debajo).**

El usuario ejecutó en Windows/PowerShell, desde `C:\\Proyectos\\contabilidad\\backend`, tras sincronizar `main` con GitHub:

- `php scripts/phase0-preflight.php` → **9 OK, 0 fallidas**, con tres advertencias sobre contraseña predeterminada de migración inicial, uso de `SET ROLE` en pruebas y uso de `DELETE` en pruebas de monedas. **Preflight estático sin conexión a BD**, no constituye prueba funcional.
- `php artisan contabilidad:auditar-fase0` → **finalizó** usando conexión `pgsql_migrations` y una transacción `READ ONLY`. Confirmó **32 tablas, 18 sin RLS, 99 índices, 5 funciones, 6 triggers, 15 políticas y 16 migraciones**.
- **Control de acceso:** `anon_select_grants=0`, `authenticated_select_grants=0`, `anon_sequences=0`, `authenticated_sequences=0`, `anon_functions=0`, `authenticated_functions=0`.
- **Integridad del libro:** `entries=2`, `lines=4`, `invalid_entries=0`, `orphan_lines=0`, `invalid_account_references=0`.
- **Inventario de migraciones:** archivos=16; aplicadas=16; pendientes=0; aplicadas sin archivo fuente=0. La seguridad se migró en el batch 6 y el perfil económico en el batch 7.
- **El `git status --short` previo al `git pull --ff-only` mostraba dos PDFs PCGE eliminados en el equipo:** `PLAN CONTABLE GENERAL EMPRESARIAL 2019 - CUENTAS.pdf` y `PLAN CONTABLE GENERAL EMPRESARIAL 2026 - CUENTAS.pdf`. El `pull` fue fast-forward y **no restauró ni eliminó esos archivos**. Debe consultarse al usuario antes de descartarlos, restaurarlos o confirmarlos en un commit.

### Clasificación de las 18 tablas sin RLS (revisada por consulta SQL de solo lectura)

- **14 catálogos/maestros de referencia**, de solo lectura para `app_backend`: `accounting_account_templates`, `accounting_standards`, `currencies`, `financial_statement_rubrics`, nueve `sunat_table_*` y `tax_calendar_deadlines`. El acceso público directo se revocó, pero debe garantizarse que la API Laravel no exponga endpoints indebidos.
- **4 tablas globales/sensibles:** `companies`, `users`, `personal_access_tokens`, `migrations`. `app_backend` mantiene SELECT/INSERT/UPDATE en `companies` y `users`, y también DELETE en `personal_access_tokens`; no tiene acceso a `migrations`. **No aplicar RLS genérico sin rediseñar altas, autenticación y navegación multiempresa.** Probar el control de acceso a través de Laravel y definir políticas por finalidad; proteger especialmente tokens.
- En ninguna de las 18 se detectó SELECT de `anon`. El resto de tablas tiene RLS y permisos sectorizados que aún necesitan pruebas de aislamiento.

**Acciones F0 aún pendientes:** realizar prueba no destructiva y autorizada de recuperación/respaldo, ejecutar la prueba **solo lectura** de privilegios `backend/tests/Feature/Security/PublicDataApiPrivilegesTest.php`, revisar funciones/RLS y autenticar la API con `app_backend`, efectuar aislamiento multiempresa en entorno controlado, resolver advertencias de seguridad y registrar resultados. Las pruebas de `TenantIsolationTest.php` crean empresas/usuarios y utilizan `SET ROLE`; `CompanyCurrencyConfigurationTest.php` modifica/elimina datos; **no ejecutar toda la suite contra la base compartida sin revisión**.

**Resultado:** diagnóstico ejecutado con éxito; F0.1, F0.2 y F0.3 siguen abiertos hasta validar las puertas de calidad. No se ejecutaron nuevas modificaciones de BD durante esta revisión del documento.

---

## Incidencia de PHPUnit: conexión de pruebas con credenciales administrativas (2026-10-20)

**Síntoma reportado:** `php artisan test tests/Feature/Security/PublicDataApiPrivilegesTest.php` falla antes de ejecutar una sola aserción (`0 assertions`): la conexión `pgsql` de PHPUnit intenta usar `postgres` en `aws-1-ca-central-1.pooler.supabase.com:5432` y obtiene `password authentication failed`. Repetir tras `php artisan config:clear` produce el mismo error.

**Diagnóstico técnico:** `backend/phpunit.xml` establece `APP_ENV=testing`, `DB_CONNECTION=pgsql`. Laravel puede cargar `.env.testing`; además `config/database.php` prioriza `DB_URL` o `DATABASE_URL` sobre `DB_HOST/DB_PORT/DB_USERNAME`. Una variable del proceso o un archivo local no versionado puede entonces sobreescribir la conexión de aplicación `app_backend`. El resultado de la prueba **NO prueba que los GRANT estén mal**; el diagnóstico read-only de F0 ya confirmó su revocación y el rol `app_backend`.

**Mitigación sin alterar PostgreSQL:** usar `php scripts/phase0-check-test-connection.php` tras `git pull --ff-only`. El script fuerza un bootstrap `testing` de Laravel y muestra solo host, puerto, usuario, conexión y si hay URL con prioridad; nunca muestra passwords ni abre conexiones. Si reporta usuario distinto de `app_backend`, corregir `.env.testing`, `.env`, `DB_URL`/`DATABASE_URL` y variables de entorno local, manteniendo `DB_MIGRATIONS_*` separados. **No volver a correr las pruebas Feature con `postgres`**, incluso si la autenticación administrativa pudiera funcionar. No ejecutar la suite completa mientras esta condición persista.

**Estado:** autenticación de la conexión `pgsql` en modo `testing` pendiente de validación; `pgsql_migrations` funcionó en migraciones y auditoría, pero **no sustituye la conexión operacional ni sus controles RLS**.

## Incidencia autenticación `app_backend` — diagnóstico y rotación preparada (2026-10-20)

- El usuario ejecutó `php scripts/phase0-check-test-connection.php` y **confirmó** `APP_ENV=testing`, archivo `.env`, conexión por defecto `pgsql`, host shared pooler `aws-1-ca-central-1.pooler.supabase.com`, puerto `6543`, usuario `app_backend.bnjwxphwtkhblvhsnylj` y **sin `DB_URL` prioritario**.
- La prueba `php artisan test tests/Feature/Security/PublicDataApiPrivilegesTest.php` sigue fallando en **autenticación** (`password authentication failed for user "app_backend"`, 0 aserciones). **No es un fallo de GRANT ni de RLS**, cuyos conteos se comprobaron por la conexión administrativa.
- Verificación segura del catálogo Supabase: `app_backend` **existe**, `rolcanlogin=true`, `rolvaliduntil=NULL`, `rolbypassrls=false`, `rolsuper=false`. La documentación oficial Supabase admite roles personalizados en el shared pooler: `[ROLE].[PROJECT_REF]`. La contraseña de Postgres **no se recuperó ni imprimió**.
- La migración original `0001_01_00_000000_setup_extensions_and_app_backend_role.php` solo asigna contraseña al **crear** el rol. Si ya existe, `ALTER ROLE` ajusta otros atributos pero **no sincroniza** la clave con `DB_PASSWORD` ni con `DB_APP_BACKEND_ROLE_PASSWORD`. Esta es una posible causa de la discrepancia; un pooler también puede mantener credenciales anteriores temporalmente.
- Se **preparó en `main`, sin aplicar**, `backend/database/migrations/2026_10_20_000000_rotate_app_backend_credential.php`. Requiere secreto nuevo `DB_APP_BACKEND_NEW_PASSWORD` de 32–256 caracteres, conexión administrativa verificada, seguridad NOBYPASSRLS, uso de SCRAM y evita publicar clave en repositorio o en excepciones SQL. Solo se ejecuta mediante `php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_20_000000_rotate_app_backend_credential.php --force` tras actualizar localmente `DB_PASSWORD` al mismo valor y configurar la nueva variable temporal.
- Tras aplicar se debe permitir actualización de caché del shared pooler, confirmar conexión `pgsql` real, repetir únicamente prueba de privilegios y retirar la variable temporal `DB_APP_BACKEND_NEW_PASSWORD`. Coordinar credenciales de cualquier backend desplegado para no interrumpirlo.
- Prueba Pest de privilegios se reforzó con una comprobación del usuario efectivo `current_user='app_backend'`, para evitar aprobar erróneamente una ejecución bajo `postgres`. **F0 sigue abierta**; credencial y pruebas pendientes.

## Rotación ejecutada en PostgreSQL — 2026-10-20

**El usuario ejecutó por Artisan** `php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_20_000000_rotate_app_backend_credential.php --force` y reportó `DONE` (1 s). Se verificó por SELECT directo en Supabase:

- `public.migrations`: **17 aplicadas**, `2026_10_20_000000_rotate_app_backend_credential` registrada en **batch 8**.
- Rol `app_backend`: `rolcanlogin = true`, `rolbypassrls = false`, `rolsuper = false`, sin caducidad. No se consultó ni reveló contraseña ni verificador.
- Roles `anon` y `authenticated`: **0 tablas, 0 secuencias y 0 funciones** con privilegios efectivos en `public`.
- `app_backend`: **31 tablas con SELECT, 20 secuencias con USAGE y 5 funciones con EXECUTE**; sin cambios con respecto a validación previa.
- Datos preservados: **1 empresa, 2 asientos contables y 4 líneas**.

**Pendiente:** comprobar autenticación real vía Laravel `pgsql` contra Supavisor 6543 con la nueva contraseña y ejecutar `php artisan test tests/Feature/Security/PublicDataApiPrivilegesTest.php`. No afirmar que ya se confirmó la conexión de la aplicación. Después de las comprobaciones y de actualizar cualquier despliegue, eliminar del `.env` la variable temporal `DB_APP_BACKEND_NEW_PASSWORD` manteniendo `DB_PASSWORD`. La Fase 0 permanece abierta.

## Persistencia del fallo Supavisor tras la rotación — 2026-10-20

El usuario confirmó que `php scripts/phase0-check-test-connection.php` identifica `testing`, `pgsql`, host pooler correcto, puerto 6543, usuario `app_backend.bnjwxphwtkhblvhsnylj`, sin URL prioritaria. Sin embargo, `php artisan test tests/Feature/Security/PublicDataApiPrivilegesTest.php` sigue fallando en autenticación de `app_backend`, con **0 aserciones**. No se debe interpretar como fallo RLS/GRANT.

Supabase verificó por SQL de solo lectura que `app_backend` tiene un verificador de contraseña **SCRAM-SHA-256** configurado, permite LOGIN, no está vencido y carece de `BYPASSRLS` y privilegios de superusuario. No se extrajo ninguna contraseña ni verificador.

Según la [documentación oficial Supavisor](https://supabase.com/docs/guides/troubleshooting/supavisor-error-password-authentication-failed-after-password-rotation), la caché de credenciales del pooler compartido puede conservar la clave anterior tras una rotación. La **verificación por conexión directa** permitiría distinguir caché de un fallo real de la clave; la conexión directa exige IPv6 en las condiciones normales del proyecto.

Se agregó `backend/scripts/phase0-diagnose-app-backend-auth.php` para comprobar de forma local que `DB_PASSWORD` coincide con `DB_APP_BACKEND_NEW_PASSWORD` (sin imprimir secretos), y solo si coinciden probar un `SELECT current_user` con conexión PostgreSQL **directa** como `app_backend` (sin proyecto añadido al nombre del rol). Los resultados `[OK]`, `[FALLO_AUTH_DIRECTA]` y `[SIN_CONCLUSION_DIRECTA]` determinan el camino de diagnóstico. No ejecutar nuevas migraciones ni rotaciones hasta tener este resultado. Evitar reintentos continuos del pooler por el interruptor de seguridad (circuit breaker).

## Conexión operacional restablecida; prueba de permisos aprobada — 2026-10-20

**Resultado informado por el usuario:**

```text
PASS Tests\\Feature\\Security\\PublicDataApiPrivilegesTest
Tests: 1 passed (11 assertions)
Duration: 2.53s
```

La prueba Pest se ejecutó por Laravel desde `backend/` con el rol operacional `app_backend` autenticado a través del pooler Supavisor de puerto 6543, tras aplicar la rotación. La prueba comprueba `current_user = 'app_backend'` y permisos efectivos de `anon`, `authenticated` y `app_backend` sobre tablas, secuencias y funciones de `public`. **Se cierra la incidencia de autenticación operacional y el control puntual de permisos.** No extrapolar el resultado al aislamiento funcional completo ni a cierre general de F0.

Se añadió `backend/scripts/phase0-audit-tenant-read-only.php` como **siguiente control seguro, todavía no ejecutado por el usuario**: desde `pgsql`, exige `current_user='app_backend'`; inicia transacción `READ ONLY`, comprueba visibilidad de `accounting_accounts` y `journal_entries` sin `app.current_company_id`, con ID de empresa inexistente y con ID de empresa real; hace `ROLLBACK` siempre, sin realizar operaciones de escritura. El control prueba `fail-closed` sobre las dos tablas y visibilidad de registros de referencia, pero **no sustituye una prueba entre dos empresas reales**.

**Higiene pendiente:** retirar la variable temporal `DB_APP_BACKEND_NEW_PASSWORD` del archivo local, conservar `DB_PASSWORD`, coordinar valores secretos de cualquier backend desplegado. No ejecutar suite completa que crea empresas, usa `SET ROLE` o hace `DELETE` sobre base compartida hasta validar seguridad transaccional. Prohibido divulgar secretos en documentación y logs.

## Auditoría RLS fail-closed aprobada — 2026-10-20

El usuario ejecutó en `backend/` el control `php scripts/phase0-audit-tenant-read-only.php` como `app_backend` con transacción `READ ONLY` y `ROLLBACK`:

- **Sin contexto de empresa:** 0 cuentas y 0 asientos visibles.
- **Empresa inexistente:** 0 cuentas y 0 asientos visibles.
- **Empresa activa:** 1.764 cuentas contables y 2 asientos visibles.
- **Resultado:** `[PASS] RLS fail-closed comprobado con SELECT; sin cambios persistentes en PostgreSQL.`

Se certifica **para estas consultas** que `accounting_accounts` y `journal_entries` tienen comportamiento fail-closed básico con el rol operativo. **Aún no se certifica aislamiento real de dos empresas ni rutas HTTP.** Solo hay una empresa en los registros de desarrollo actuales.

### Siguiente prueba de autorización HTTP sin escritura — preparada, no ejecutada

Se añadió `backend/tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php` para ejecutar exclusivamente endpoints `GET` con usuario de desarrollo existente:

- Una petición sin autenticación a empresas o Libro Diario debe obtener 401.
- El usuario autenticado debe recibir su empresa existente en `GET /api/v1/tenant/companies`.
- El usuario autenticado no debe poder leer el Libro Diario de una empresa inexistente (403).
- El usuario autorizado puede consultar su Libro Diario (200).

**Hallazgo previo de código/RLS que exige comprobar la segunda aserción:** `ListUserCompaniesController` consulta el pivote `company_user` mediante relación Eloquent, pero `GET /api/v1/tenant/companies` está fuera del middleware `SetTenantContext`. La política vigente `company_user_tenant_isolation_policy` exige `company_id=app_current_company_id()`. Al no haberse establecido contexto SQL, puede devolver una lista vacía a pesar de existir membresía. **No cambiar RLS ni concesiones globales sin una corrección de diseño y prueba funcional.** La prueba diagnóstica tiene permiso de fallar y debe orientar la corrección; no se dará por aprobada anticipadamente.

La suite completa permanece bloqueada hasta revisar la seguridad transaccional de pruebas con escrituras. Los 18 objetos sin RLS, la recuperación y la verificación end-to-end con dos empresas continúan pendientes.

## Error reproducido en HTTP F0 — 2026-10-20: booleanos y lista de empresas

**Salida exacta de la prueba del usuario:** `php artisan test tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php` → **3 pruebas aprobadas, 1 fallida, 6 aserciones, 10.50 s**.

- Aprobadas: usuarios no autenticados reciben rechazo; usuario autenticado no puede leer Libro Diario de empresa inexistente; usuario autorizado puede leer asientos de su empresa.
- Falló `it lists the authenticated users existing active company`: **HTTP 500**, `SQLSTATE[42883] operator does not exist: boolean = integer`. Eloquent construía `companies.is_active = 1` y `company_user.is_active = 1` debido a la conversión de booleanos en parámetros SQL; PostgreSQL no admite esa igualdad. **Error confirmado por la traza**, anterior a comprobar el posible bloqueo RLS.
- Además, la ruta `GET /api/v1/tenant/companies` estaba fuera de `SetTenantContext` y la política `company_user_tenant_isolation_policy` requiere empresa activa. Por tanto, tras corregir booleanos era probable que el SELECT devolviese lista vacía.

**Correcciones preparadas en GitHub/main, todavía sin ejecutar en la base:**

1. `backend/app/Http/Controllers/Api/V1/Tenant/ListUserCompaniesController.php`: consulta con `companies.is_active IS TRUE` y `company_user.is_active IS TRUE` (sin parámetros enteros), usa `DB::transaction` con `set_config('app.current_user_id', user autenticado, true)` para mantener el alcance SQL local a la transacción; no pide empresa activa para una lista que abarca varias empresas. Mantiene los campos de respuesta existentes.
2. **Nueva migración Laravel pendiente**, `backend/database/migrations/2026_10_20_010000_scope_company_listing_to_authenticated_user.php`: añade política `FOR SELECT TO app_backend` exclusivamente sobre `company_user` con condición `user_id = current_setting('app.current_user_id')::bigint` dentro de una transacción. **No reemplaza la política RLS por empresa ni concede INSERT, UPDATE o DELETE**, ni cambia permisos de `anon`/`authenticated`. Requiere la conexión administrativa `pgsql_migrations` y control de identidad.
3. Volver a ejecutar **solo** `tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php` después de confirmar la migración; comprobar resultado HTTP 200 y empresa existente. **No** ejecutar toda la suite antes de resolver el control.

**Estado:** correcciones listas en código, resultado de prueba final PENDIENTE. La nueva migración NO está aplicada por el usuario. Las 17 anteriores siguen aplicadas; no tratar el fallo HTTP 500 como incidencia de conexión PostgreSQL.

## Validación entre empresas: preflight y prueba transaccional preparados — 2026-10-20

**Verificaciones más recientes, ya aprobadas por el usuario:**
- `2026_10_20_010000_scope_company_listing_to_authenticated_user` aplicada por Artisan: **DONE**.
- `ReadOnlyTenantApiSmokeTest.php`: **4 PASS / 7 aserciones**, 8,35 segundos, cubre acceso sin autenticación, listado de empresas, acceso no autorizado a empresa inexistente y lectura del Libro Diario propio.
- Confirmación SQL en Supabase: **18 migraciones** (la última en batch 9); RLS de `company_user` contiene tanto la política de tenant por `company_id` como la nueva `FOR SELECT` limitada por `user_id`; `anon`/`authenticated` siguen sin SELECT; **1 empresa, 1 usuario, 1 membresía, 1.764 cuentas, 2 asientos y 4 partidas**.

**Nuevo desarrollo en `main` (NO ejecutado en la BD):**
`backend/scripts/phase0-tenant-isolation-rollback.php`. Sin argumentos hace solo preflight: confirma usuario operativo `app_backend`, usuario administrativo `postgres`, entorno de desarrollo correcto, RLS ENABLE/FORCE, una empresa existente y los conteos de referencia. No escribe. Con `--execute` y solo si pasan las salvaguardas abre una única transacción como `app_backend`, inserta explícitamente empresa B y una cuenta contable sintéticas, sin consumir secuencias, verifica que A no pueda leer/modificar los datos de B y viceversa; comprueba que una membresía exista solo al ser creada, que el listado por usuario devuelva ambas empresas y que sin contexto se vuelva a negar acceso. **Siempre ejecuta ROLLBACK** y compara conteos del inicio y del final desde conexión administrativa independiente. No utiliza `SET ROLE`, no cambia RLS ni migra, no ejecuta transacciones de negocio. 

**Limitación:** las escrituras son transitorias, pero una transacción reversible no equivaldría a garantía universal de ausencia de efectos externos de futuros triggers, notificaciones o integraciones. Se inspeccionaron los triggers de `companies`, `company_user` y `accounting_accounts`: solo hay un trigger propio de jerarquía en `accounting_accounts`. No se ha ejecutado el modo `--execute` y no debe declararse PASS hasta obtener evidencia de su resultado real y de los conteos finales. Tampoco es una prueba end-to-end de dos usuarios mediante HTTP; la protección de rutas requiere validación adicional.

**Comandos seguros:** ejecutar primero `php -l scripts/phase0-tenant-isolation-rollback.php` y `php scripts/phase0-tenant-isolation-rollback.php` (solo preflight). Revisar resultados antes de decidir `--execute`. No ejecutar la suite `TenantIsolationTest.php` entera sin remediar sus casos que usan `SET ROLE` y mutaciones.

## Fase 0 — Aislamiento interempresa A/B APROBADO — 2026-10-20

**Resultado real informado por el usuario (PowerShell en `backend/`):**

```text
php scripts/phase0-tenant-isolation-rollback.php --execute
[PREFLIGHT] Linea base: {"companies":1,"users":1,"memberships":1,"accounts":1764,"entries":2,"lines":4}
[PASS] Empresas A/B: no hay lectura ni UPDATE cruzados, membresia valida, listado transversal y RLS fail-closed.
[VERIFY] Conteos finales: {"companies":1,"users":1,"memberships":1,"accounts":1764,"entries":2,"lines":4}
[OK] ROLLBACK confirmado; no quedan filas de prueba.
```

Antes de ello se verificó sintaxis PHP sin errores y preflight de solo lectura satisfactorio. **Supabase verificó independientemente mediante SELECT posterior** los mismos conteos de empresas, usuarios, membresías, cuentas, asientos, partidas y **18 migraciones**. Esta evidencia permite cerrar el control **RLS entre dos tenants a nivel SQL** para las operaciones ejercitadas, preservando datos existentes. No equivale a una certificación completa de seguridad, ni a probar todas las rutas HTTP.

**Hallazgo de revisión estática siguiente:** `LoginController` contenía la misma lectura insegura de `companies.is_active = 1`/`company_user.is_active = 1` que ocasionó HTTP 500 en el listado de empresas, y consultaba `company_user` sin el contexto de usuario RLS. En el método de login, esto sucedía **después de emitir un token Sanctum**; un fallo de la consulta podía generar token pese a respuesta HTTP 500.

**Correcciones de backend agregadas a `main`, pendientes de ejecutar el test de regresión:**
- Nuevo servicio `backend/app/Services/Tenant/ListUserCompaniesService.php`: consulta común entre `LoginController` y `ListUserCompaniesController`, dentro de `DB::transaction()` con `set_config('app.current_user_id', user autenticado, true)`, filtros PostgreSQL `IS TRUE`. No se alteran políticas ni tablas.
- `LoginController` ejecuta el listado antes de crear el token.
- `ReadOnlyTenantApiSmokeTest.php` agrega una prueba de servicio sin escrituras ni emisión de tokens, verificando que el selector de empresas devuelve la empresa activa y que el conteo de tokens permanece intacto.

**Pendiente inmediato:** ejecutar `php -l` sobre los tres archivos modificados y `php artisan test tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php` (esperado 5 PASS, aserciones aún por verificar). **No ejecutar la suite de login completa** porque genera tokens, auditoría y otras escrituras; requiere revisión específica bajo aislamiento. Otros pendientes generales F0: estrategia de recuperación, pruebas funcionales autenticadas y revisión de tablas globales sin RLS.

## Fase 0 — 5/5 pruebas de lectura aprobadas; revisión de tablas globales — 2026-10-20

El usuario ejecutó `php artisan test tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php` tras unificar `ListUserCompaniesService` y los controladores: **PASS (5 pruebas, 9 aserciones, 11.57 s)**. La quinta verifica que el servicio devuelva las empresas del usuario sin emitir ni modificar tokens. **Esto no ejecuta POST /auth/login ni crea un token; ese control sigue pendiente.**

**Auditoría SQL independiente de Supabase (solo SELECT, sin mutar):**
- **18 tablas** de `public` sin RLS. **14 catálogos**: `app_backend` solo tiene `SELECT`, no escritura, y los roles `anon` y `authenticated` no tienen SELECT.
- **4 tablas globales/sensibles:** `companies` y `users` conceden SELECT/INSERT/UPDATE (sin DELETE) a `app_backend`; `personal_access_tokens` concede SELECT/INSERT/UPDATE/DELETE a `app_backend`, necesario para Sanctum pero exige controles en Laravel; `migrations` no concede SELECT/INSERT/UPDATE/DELETE a `app_backend`.
- En las 18, `anon` y `authenticated` tienen `SELECT=false`. No se debe interpretar ausencia de permiso público como RLS habilitado.
- En `public`, las cinco funciones inspeccionadas muestran `SECURITY DEFINER=false`; `anon` no tiene EXECUTE; los cinco EXECUTE de `app_backend` son los propios de tenant/triggers. No se modificó ninguna política.
- Línea base adicional: `personal_access_tokens=28` filas, `audit_logs=34` filas, **18 migraciones**. No se consultaron valores de tokens ni contraseñas.

**Preparación siguiente, código en GitHub/main SIN ejecutar en Supabase:**
`backend/scripts/phase0-login-rollback.php`: por defecto **solo lectura** (preflight de proyecto/roles/RLS/conteos); con `--execute` explícito crea un **usuario y membresía sintéticos** dentro de una transacción `pgsql`, prueba el login HTTP 200, emisión de token Sanctum, Bearer de acceso a la propia empresa y auditoría `AUTH_LOGIN`, ejecuta siempre `ROLLBACK` y compara los conteos desde la conexión administrativa. Usa IDs explícitos también para tokens y auditoría para evitar adelantar secuencias. No toca usuarios existentes ni imprime contraseñas/tokens. **El login HTTP completo y el modo --execute NO se han probado aún**, no declarar PASS anticipadamente. Una transacción no garantiza revertir efectos externos futuros, como mensajes enviados por listeners; se evita finalizar kernel y el entorno es local.

**Seguridad de auditoría global (hallazgo para revisión):** el SELECT agregado detectó 2 eventos de auditoría globales (`company_id IS NULL`) y 32 eventos asociados a empresa. La política de lectura de `audit_logs` permite filas globales a `app_backend` aun sin empresa; no se localizaron accesos directos públicos y no se consultó ningún contenido sensible. Antes de exponer auditoría vía API hay que definir permisos explícitos por usuario y finalidad.

**Preflight adicional:** `users`, `company_user`, `personal_access_tokens` y `audit_logs` no tienen triggers propios no internos actualmente, según catálogo PG. La ejecución opcional del script de login configura caché, rate limiting y sesión en memoria (`array`) para minimizar efectos externos. No es prueba de ausencia universal de efectos de eventos/librerías.

**Pendientes relevantes:** prueba HTTP de login real con rollback, revisión de autorización y rate limiting, verificación de recuperación y respaldo; seguridad completa de tablas globales no se logra sin controles de aplicación y eventual diseño de esquema. Fase 0 continúa en progreso.

## Incidencia F0: auditoría del login sin contexto RLS — 2026-10-20

**Pruebas reales del usuario:**
- `php -l scripts/phase0-login-rollback.php`: sin errores de sintaxis.
- `php scripts/phase0-login-rollback.php` (preflight): **OK**, solo lectura.
- `php scripts/phase0-login-rollback.php --execute`: **FAIL**. La respuesta HTTP de login fue 200, pero una consulta posterior a `personal_access_tokens` falló con `SQLSTATE 25P02` (transacción abortada). Se informó rollback y los conteos finales coincidieron exactamente con la línea base: `companies=1`, `users=1`, `memberships=1`, `tokens=28`, `audits=34`, `entries=2`.

**Diagnóstico confirmado por registros PostgreSQL de Supabase** (consulta agregada sin extraer SQL con credenciales): el primer error fue **SQLSTATE 42501** en una consulta de `audit_logs` que el servidor clasificó como **RLS**; después ocurrió **25P02** en consulta de tokens. La excepción de auditoría fue capturada en `AuditLoggerService` sin revertir antes el error de la transacción que utilizaba el llamador. La salida anterior del script expuso la excepción SQL completa y fue mejorada para mostrar **solo fase y SQLSTATE**.

**Solución preparada y confirmada en código GitHub/main, todavía NO verificada en ejecución:**
- `backend/app/Services/Security/AuditLoggerService.php`: cada escritura de auditoría se realiza dentro de `DB::transaction` (SAVEPOINT si ya existe una transacción) para impedir que un error deje abortado el bloque externo; cuando `companyId` no es nulo establece `app.current_company_id` únicamente de modo LOCAL y restablece su valor previo, de modo que `INSERT ... RETURNING` cumpla la política RLS SELECT además de INSERT. No cambia las políticas ni privilegios de PostgreSQL. Los logs de errores ya no contienen `QueryException::getMessage()` ni SQL con parámetros.
- `backend/scripts/phase0-login-rollback.php`: ahora distingue `QueryException` y emite **solo etapa y SQLSTATE** sin mostrar SQL ni contraseña.

**Verificación independiente de datos tras el error:** Supabase reportó `companies=1`, `users=1`, `memberships=1`, `tokens=28`, `audits=34`, `entries=2`, `migrations=18`; las políticas `audit_logs` SELECT e INSERT permanecen presentes.

**Siguiente paso:** `git pull --ff-only`; validación de sintaxis de los dos archivos; ejecutar primero preflight solo lectura del script, después una sola ejecución `--execute`. Criterio PASS: login HTTP 200, token Sanctum, acceso Bearer a empresas propias, fila de auditoría transaccional, ROLLBACK y conteos idénticos. **No se ha logrado PASS de este control aún**. No ejecutar la suite global ni repetir el script sin revisar cualquier error.

## Fase 0 — Login HTTP + Bearer + auditoría aprobado con rollback — 2026-10-20

**Resultado real ejecutado por el usuario tras el ajuste de `AuditLoggerService`:**

```text
php -l app/Services/Security/AuditLoggerService.php
No syntax errors detected in app/Services/Security/AuditLoggerService.php
php -l scripts/phase0-login-rollback.php
No syntax errors detected in scripts/phase0-login-rollback.php

php scripts/phase0-login-rollback.php
[PREFLIGHT] Conteos: {"companies":1,"users":1,"memberships":1,"tokens":28,"audits":34,"entries":2}
[OK] Preflight solo lectura. No se crearon usuarios, tokens ni auditorias.

php scripts/phase0-login-rollback.php --execute
[PREFLIGHT] Conteos: {"companies":1,"users":1,"memberships":1,"tokens":28,"audits":34,"entries":2}
[CHECK] Login 200: token Sanctum y empresa autorizada obtenidos sin imprimir secretos.
[CHECK] Bearer autentica GET /tenant/companies de la empresa autorizada.
[CHECK] Auditoria AUTH_LOGIN creada dentro de la transaccion.
[VERIFY] Conteos finales: {"companies":1,"users":1,"memberships":1,"tokens":28,"audits":34,"entries":2}
[PASS] Login+Bearer+auditoria con ROLLBACK confirmado.
```

**Confirmación independiente por SELECT en Supabase:** `companies=1`, `users=1`, `memberships=1`, `tokens=28`, `audits=34`, `entries=2`, `lines=4`, `migrations=18`. Los datos quedaron preservados. **Se cierra el incidente 42501 → 25P02** del ensayo anterior y el control puntual de login HTTP/consulta autorizada por token/auditoría. No extrapolarlo a todas las funcionalidades de Sanctum ni a una aprobación general de F0.

**Pendientes de F0:** verificación documentada de respaldos y recuperación; revisión de comportamientos de expiración/revocación de tokens y de autorización de tablas globales (sin aplicar RLS indiscriminado), pruebas del resto de endpoints HTTP y preflight estático para decidir qué partes de la suite con escritura son seguras. No ejecutar la suite global en la única base compartida hasta revisar ese riesgo.

## Hallazgo de seguridad: tokens Sanctum sin vencimiento — 2026-10-20

**Comprobación por SQL de solo lectura:** `personal_access_tokens=28`; **28/28** filas tienen `expires_at IS NULL`; **0** registran vencimiento futuro o pasado; **9/28** no tienen `last_used_at` registrado. Los tokens se emitieron entre 2026-10-19 y 2026-10-20. **No se consultaron valores ni hashes de tokens.**

**Revisión de código:** `backend/config/sanctum.php` establece `'expiration' => null` y `'last_used_at' => env('SANCTUM_TRACK_LAST_USED_AT', false)`; `LoginController` llama `$user->createToken($deviceName)` sin vencimiento por token. En esta configuración los Bearer tokens no expiran automáticamente por edad o columna `expires_at`, salvo revocación explícita. `LogoutController` revoca el token en cierre de sesión, pero falta validar el flujo con token real y la política de invalidez por usuario, baja o inactividad.

**Acción recomendada (NO implementada, decisión pendiente):** acordar duración máxima de token y política de sesiones (por ejemplo 8–12 horas para personal de contabilidad; diferente para integraciones servidor-servidor). Proponer control `SANCTUM_TOKEN_EXPIRATION_MINUTES`, vencimiento de tokens nuevos, pruebas de expiración/revocación y plan de transición para los 28 tokens actuales. **No activar un vencimiento global sin autorización y sin prever desconexión de usuarios existentes.** La revisión no modificó datos ni configuración.

## Política de sesión Sanctum aprobada — máximo 12 horas (2026-10-20)

El usuario **aprobó explícitamente 12 horas** como duración máxima de las sesiones del personal. Implementación en `main`:

1. `backend/config/sanctum.php`: `'expiration' => 720` (minutos). Sanctum aplica antigüedad máxima de 720 minutos a **todos los tokens Bearer**, incluidos los emitidos previamente con `expires_at IS NULL`; no significa que las cookies de sesión `web` queden configuradas con esa misma duración.
2. `backend/app/Http/Controllers/Api/V1/Auth/LoginController.php`: `createToken($deviceName, ['*'], now()->addHours(12))` para guardar también fecha individual `expires_at` en **tokens nuevos**. El límite es absoluto desde la emisión, **no se renueva por actividad**.
3. `backend/scripts/phase0-login-rollback.php`: ahora verifica en preflight que la configuración efectiva sea 720, confirma que el token sintético tiene `expires_at` a ~12 h, prueba el Bearer vigente (HTTP 200), modifica **solo el created_at del token sintético dentro de la transacción** para simular 13 h de antigüedad (esperado 401), luego lo restablece, prueba `POST /auth/logout`, verifica que se revoque el token y que su reutilización sea rechazada (401), verifica ambas auditorías y el `ROLLBACK` final. El script no imprime contraseñas, tokens ni hashes ni modifica cuentas persistentes.

**Estado de aplicación:** el código está confirmado en GitHub/main, **pendiente de sincronizar y ejecutar en el equipo local/deploy**. **No declarar pruebas PASS ni expiración activa del despliegue sin confirmación.** No se ejecutaron migraciones, limpieza ni revocación masiva.

**Impacto al activar la actualización:** los tokens existentes creados hace más de 12 horas serán rechazados en futuras solicitudes aunque la columna `expires_at` siga NULL, y se requerirá volver a iniciar sesión. Los tokens existentes **no se borran por el mero vencimiento**; revocación/limpieza física es un proceso distinto. Documentar el comportamiento HTTP 401 del frontend.

**Consulta SQL independiente posterior a la edición del código:** 28 tokens en total, 28 con `expires_at IS NULL`, 1 empresa, 1 usuario, 34 auditorías y 18 migraciones, todos sin cambios. No se han consultado valores de token ni hashes.

**Procedimiento seguro pendiente:** `git pull --ff-only`; `php -l` en los tres archivos modificados; `php artisan config:clear` solo si Laravel mantiene configuración local cacheada; `php scripts/phase0-login-rollback.php` sin argumentos, luego **una sola** ejecución con `--execute`, verificando PASS y conteos idénticos. Si falla, no repetir ni ejecutar toda la suite antes de diagnosticar.

## 1. Objetivo y alcance de este registro

Iniciar F0 y levantar una línea base verificable: inventario GitHub, situación PostgreSQL, correspondencia inicial de migraciones, capacidades de pruebas, riesgos y bloqueos. **No confundir F0 con corrección de F1.** Ninguna actividad F0.1–F0.3 se marca terminada hasta completar sus respectivas condiciones, especialmente verificación de respaldo de la misma base, pruebas ejecutadas y análisis no destructivo de migraciones y esquema.

## 2. Inventario del repositorio

**Fuente:** árbol recursivo de `main` en GitHub consultado el 2026-10-19.  
Árbol consultado: `55824716a13158aacb4e76aa46d7fc941f552766` (snapshot de esta revisión).

| Concepto | Encontrado |
|---|---|
| Archivos versionados totales | 407 (incluye duplicaciones de skills de `.agents`, `.claude` y `.kiro`) |
| Archivos bajo `backend/` | 132 |
| Archivos bajo `frontend/` | 32 |
| Migraciones Laravel | 14 archivos `backend/database/migrations/*.php` |
| Seeders Laravel | 8 archivos `backend/database/seeders/*.php` |
| Archivos de pruebas funcionales Pest | 7 en `backend/tests/Feature/` |
| Workflows GitHub Actions | 0 archivos bajo `.github/workflows/` |
| Stack | Backend Laravel 11/PHP, Sanctum; frontend Vue 3, TypeScript, Tailwind 4; Supabase/PostgreSQL |

**Pruebas existentes por archivo:**
- `tests/Feature/Accounting/AccountingAccountCatalogTest.php`
- `tests/Feature/Accounting/JournalEntryTest.php`
- `tests/Feature/Accounting/MasterChartOfAccountsTest.php`
- `tests/Feature/CompanyCurrencyConfigurationTest.php`
- `tests/Feature/CompanySetupAndCatalogsTest.php`
- `tests/Feature/Security/Iso27001SecurityComplianceTest.php`
- `tests/Feature/Tenant/TenantIsolationTest.php`

**Módulos de frontend confirmados:** `frontend/src/modules/accounting/` (plan contable, asientos y tipos de cambio) y `frontend/src/modules/company/` (configuración de empresa). La existencia de componentes **no** certifica que estén integrados o funcionen de punta a punta.

**API confirmada en** `backend/routes/api.php`: Sanctum login/logout; selección/listado de empresas, catálogos SUNAT, normas y rubros, cuentas, Libro Diario, tipos de cambio, áreas, centros de costos, entidades y configuración. La prueba de permisos/respuestas de estos endpoints está pendiente.

## 3. Base Supabase — situación observada (sin consultar datos sensibles)

**Fuente:** consultas de catálogo `pg_catalog`/`information_schema` y asesor de Supabase sobre el proyecto correcto.

| Métrica | Resultado |
|---|---|
| Tablas físicas en `public` | **31** |
| Con RLS `ENABLE` | **13** |
| Con RLS `FORCE` | **13** |
| Sin RLS | **18** |
| Roles `anon` y `authenticated` | Tienen permisos `SELECT/INSERT/UPDATE/DELETE/TRUNCATE/TRIGGER/REFERENCES` sobre **las 31 tablas** (RLS restringe filas en las 13 habilitadas) |
| Rol `app_backend` | Existe, admite login y no tiene `BYPASSRLS`; sus permisos requieren validación real desde Laravel |
| Funciones del esquema `public` con `search_path` mutable | **5** |
| Claves foráneas sin índice recomendado | **17** según asesor |
| Índices sin uso registrado | **8** según asesor; aún no se justifica eliminarlos |
| Migraciones registradas por Laravel | **14**, lotes 1–5 |
| Migraciones devueltas por herramienta de migraciones nativas Supabase | **0**; distinto gestor del registro `public.migrations` de Laravel |

**Tablas sin RLS:** `migrations`, `companies`, `users`, `personal_access_tokens`, `currencies`, `sunat_table_01_payment_methods`, `sunat_table_02_identity_document_types`, `sunat_table_03_financial_institutions`, `sunat_table_05_inventory_types`, `sunat_table_06_measurement_units`, `sunat_table_08_electronic_books`, `sunat_table_10_electronic_document_types`, `sunat_table_11_customs_dependencies`, `sunat_table_12_operation_types`, `financial_statement_rubrics`, `accounting_standards`, `tax_calendar_deadlines`, `accounting_account_templates`.

**Datos de desarrollo reportados** (conteos, no contenido): 1 empresa, 1 usuario, 1,764 cuentas de empresa, 3,401 plantillas PCGE, 2 asientos/4 líneas, 7 entidades, 0 centros de costos y 34 eventos de auditoría. Volver a medir durante futuras pruebas controladas en la misma base de desarrollo.

### Correspondencia de migraciones

Se contrastaron **14 nombres** de archivos de `backend/database/migrations/` con **14 entradas** de `public.migrations`:
- **14/14 nombres presentes en ambos lados** en esta revisión.
- Sin faltantes nominales detectados.
- **NO comprobado:** checksum/contenido ejecutado, integridad de rollback, drift de objetos, aplicación automática desde una BD vacía ni reconstrucción desde cero (no destructiva en la base única).

**Nota:** no interpretar el resultado vacío del historial de migraciones nativas Supabase como ausencia de migraciones Laravel. Son mecanismos separados.

## 4. Hallazgos técnicos prioritarios

| Riesgo | Severidad | Evidencia | Acción futura |
|---|---|---|---|
| R01 — Exposición API de tablas sin RLS | **CRÍTICA** | 18 tablas sin RLS; grants extensos en 31 tablas, incluyendo `users` y `personal_access_tokens` | F1: revocar privilegios indebidos, RLS y políticas específicas, probar acceso API |
| R02 — Aislamiento multiempresa dudoso en pool | **CRÍTICA** | `backend/app/Http/Middleware/SetTenantContext.php` usa `set_config(..., false)` sin transacción envolvente visible | F1: controlar contexto por transacción/conexión, verificar que no migra entre peticiones |
| **R10 — Credencial DB versionada en repositorio** | **CRÍTICA** | Se identificó un `DB_PASSWORD` literal; retirado del archivo actual en commit `cc363a17` | **Pendiente:** rotar la contraseña y revisar el historial Git; no difundir el valor anterior |
| **R11 — Pruebas contra BD de desarrollo compartida** | **ALTA** | `backend/phpunit.xml` apunta a `contabilidad-database`; `tests/Pest.php` utiliza `DatabaseTransactions` | No crear BD aislada (decisión del usuario); revisar tests, preservar datos, usar transacciones/rollback y verificar conexión a la base de desarrollo |
| R03 — Pérdida potencial de precisión monetaria | ALTA | `CreateJournalEntryController.php` convierte tasas e importes a `(float)` | F2: strings decimales/BCMath end-to-end |
| R04 — Carrera de correlativos | ALTA | Controlador genera número vía `count() + 1` | F2: contador transaccional o estrategia segura e idempotente |
| R06 — `search_path` mutable | ALTA | 5 funciones reportadas por asesor Supabase | F1: revisión función por función |
| R12 — Error de cita legal del PCGE 2026 | MEDIA | Resolución correcta **002-2026-EF/30**, obligatoria desde 2028 | Corregir README y respetar vigencias en F3/F7 |
| R13 — GitHub Actions no asigna runner | MEDIA | Workflow `phase0-static-baseline.yml` creado; primer run finalizó sin pasos ejecutados ni runner asignado | Revisar disponibilidad de GitHub Actions; workflow dejado en disparo manual para evitar ejecuciones fallidas repetidas |
| R14 — Artefacto de caché de PHPUnit versionado | BAJA | `backend/.phpunit.cache/test-results` en Git | Posteriormente excluirlo del control de versiones |

### Precaución especial por R10/R11

La credencial de PostgreSQL se retiró del archivo HEAD `backend/phpunit.xml` en commit `cc363a17`. **La rotación real permanece pendiente**: el valor antiguo puede seguir funcionando y permanecer en el historial de Git. Nunca copiar la contraseña en mensajes, issues o documentación. `DatabaseTransactions` reduce efectos persistentes pero no garantiza por sí mismo ausencia de efectos colaterales.

## 5. Revisión normativa inicial (no sustituye validación legal completa)

**Fuentes públicas consultadas:**
- MEF — [PCGE](https://www.gob.pe/es/92758-ministerio-de-economia-y-finanzas-pcge) muestra la versión «Plan Contable General Empresarial Modificado 2019» como referencia recuperada.
- MEF — [Resolución CNC 002-2019-EF/30](https://www.gob.pe/institucion/mef/normas-legales/277088-002-2019-ef-30) aprueba PCGE 2019.
- Fundación IFRS — [NIIF 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/) indica periodos iniciados a partir de **1 de enero de 2027**, permitiendo adopción anticipada.
- Ministerio de Economía y Finanzas: comprobar adicionalmente el marco de **adopción local** (no confundir fecha IASB con oficialización nacional).

**Hallazgo normativo confirmado:** la [Resolución CNC 002-2026-EF/30](https://busquedas.elperuano.pe/dispositivo/NL/2550786-1), publicada el 4 de septiembre de 2026, aprueba el PCGE 2026, obligatorio desde el **1 de enero de 2028**, con adopción anticipada posible. El README citaba erróneamente la 003-2026. NIIF 18 y NIIF 19 tienen vigencia internacional general desde 2027 (adopción anticipada posible); falta verificar su adopción local y PLE/SIRE.

## 6. Pruebas y recuperación: estado real

| Prueba / salvaguarda | Estado | Motivo |
|---|---|---|
| Descubrimiento de suites Pest | **INSPECCIONADA** | 7 archivos funcionales |
| Ejecución `composer test` | **NO EJECUTADA** | Aún no se ha validado un runner con credenciales renovadas; contraseña inline retirada del XML |
| Ejecución `pnpm typecheck` | **NO EJECUTADA** | Primer workflow GitHub Actions falló sin asignar runner; no hubo pasos de compilación |
| Ejecución `pnpm build` | **NO EJECUTADA** | Primer workflow GitHub Actions falló sin asignar runner; no hubo pasos de compilación |
| Ejecutar `migrate:fresh` | **NO SE EJECUTARÁ** | Destructivo para la única base; sustituido por catálogo SQL y `migrate:status` |
| Backup completo y prueba de restauración | **NO VERIFICADOS** | El conector accesible no ofrece flujo confirmado para exportación/restauración; no inferir disponibilidad de respaldo |
| Test aislamiento empresas A/B | **NO EJECUTADA** | Pendiente de ejecución transaccional con rollback verificado sobre la base de desarrollo |
| Test del POST y concurrencia | **NO EJECUTADA** | Pendiente de fixture de desarrollo reversible y pruebas de concurrencia seguras |
| Deploy / preview | **NO REALIZADO** | Fuera del diagnóstico de F0 |

**No emitir un «passed» sin registro de salida real.** Existen suites de prueba, pero eso no constituye evidencia de ejecución.

### Comandos previstos para la única base de desarrollo (sin comandos destructivos)

```bash
# Backend (desde backend/), después de proveer un .env.testing seguro
composer install
php artisan migrate:status
# composer test: ejecutar solo después de revisar los efectos de los tests y comprobar rollback
composer lint

# Frontend (desde frontend/)
pnpm install --frozen-lockfile
pnpm typecheck
pnpm build
```

**Protección previa obligatoria:** verificar que PHPUnit usa `contabilidad-database` con contraseña renovada en variables no versionadas; revisar rollback y no ejecutar `migrate:fresh`, `db:wipe`, `db:seed` destructivo ni DDL de borrado sobre la base única.

## 7. Estado de las tareas del plan (sin marcar completadas)

| Actividad | Estado | Avance verificable | Qué falta para `[x]` |
|---|---|---|---|
| **F0.1** Inventario y respaldo | **EN PROGRESO** | Inventario de tablas, RLS, permisos, roles, índices y funciones | Verificar respaldo recuperable para la misma base de desarrollo; no crear otra BD de trabajo |
| **F0.2** Código y migraciones | **EN PROGRESO** | 14/14 migraciones por nombre; inventario de API, frontend, 7 suites | Comparación no destructiva del esquema y verificación funcional real |
| **F0.3** Baseline y normativa | **EN PROGRESO** | Identificados test runners y discrepancias normativas a confirmar | Ejecutar tests seguros, builds, revisión normativa completa |

**Indicadores del plan permanecen en 0/27 actividades aprobadas y 0/9 fases aprobadas.** La Fase 0 se encuentra *iniciada*, no *terminada*.

## 8. Siguiente trabajo dentro de F0 (antes de F1)

1. Gestionar urgentemente **rotación de credencial filtrada** (ya retirada del archivo actual `backend/phpunit.xml`), con verificación de no uso de la contraseña anterior. Como toca seguridad, registrar autorización y trazabilidad; mantener al usuario informado.
2. Utilizar solamente la BD actual `contabilidad-database`, verificar respaldo y preservar todos los registros existentes.
3. Ejecutar suites de backend (revisadas para rollback) y frontend mediante un runner disponible, usando la misma BD de desarrollo y registrando resultados.
4. Comparar esquema/migraciones mediante consultas de solo lectura; prohibida la reconstrucción destructiva en la base actual.
5. Corroborar normativas PCGE y NIIF en documentos oficiales, con fechas y estado de vigencia.
6. Concluir matriz `implementado / implementado sin verificar / parcial / pendiente`, actualizar F0.1–F0.3 y obtener puerta de aprobación F0.

## 9. Registro de cambios

| Fecha | Registro | Resultado |
|---|---|---|
| 2026-10-19 | Inicio Fase 0 y diagnóstico de solo lectura | Inventario técnico y riesgos documentados. F0 permanece abierta; sin modificaciones a DB ni código aplicativo |


## 10. Continuación — Única base de desarrollo (2026-10-19)

**Decisión del usuario:** toda la implementación y las pruebas se realizarán en Supabase **contabilidad-database**, referencia **bnjwxphwtkhblvhsnylj**, hasta finalizar el desarrollo. No se creará una base aislada. Esto no autoriza borrar datos existentes ni ejecutar comandos como `migrate:fresh`, `db:wipe` o seeders destructivos.

**Seguridad:** se eliminó el valor literal de `DB_PASSWORD` de `backend/phpunit.xml` en el commit [cc363a17](https://github.com/jwichpas/contabilidad/commit/cc363a17a351114bc61c3a29f1901f127b095b16). El secreto permanece potencialmente comprometido por versiones previas de Git: **rotación pendiente**, con actualización coordinada de todas las conexiones.

**Verificaciones SQL ejecutadas (todas SELECT, sin escrituras):**

| Métrica | Resultado |
|---|---|
| Asientos / líneas examinadas | 2 / 4 |
| Asientos descuadrados en MN/ME | 0 |
| Asientos con menos de dos líneas o total cero | 0 |
| Suma Debe y Haber local | 2,600.0000 / 2,600.0000 |
| Suma Debe y Haber extranjera | 693.3367 / 693.3367 |
| Partidas sin asiento o asociadas con cuenta de otra empresa | 0 |
| Cuentas sin empresa / con padre faltante | 0 |
| Códigos de cuenta duplicados dentro de empresa | 0 |
| Partidas contabilizadas en cuentas no operativas o inactivas | 0 |
| Plantillas PCGE 2019 / 2026 | 1,764 / 1,637 |
| Cuentas operativas de empresa | 1,212 |
| Cuentas operativas con seis dígitos | 21 (109 de 3; 396 de 4; 686 de 5 dígitos) |
| Triggers en Diario/partidas revisados en catálogo | 5; incluyendo saldo diferido, habilitados |

**Interpretación:** los dos asientos de prueba existentes concilian, pero una muestra de dos no valida concurrencia ni exactitud del motor completo.

**Verificación normativa:** fuente oficial [El Peruano, Resolución CNC 002-2026-EF/30](https://busquedas.elperuano.pe/dispositivo/NL/2550786-1) (04-09-2026): PCGE 2026 obligatorio a partir del 01-01-2028, anticipado permitido. IFRS Foundation confirma vigencia general NIIF 18 y 19 desde 2027; se verificará la oficialización local.

**Estado:** F0.1–F0.3 continúan EN PROGRESO, sin pruebas automáticas ejecutadas, sin respaldo/recuperación comprobados y sin cierre de fase. Este registro no inicia F1.

## 11. Primer intento de CI estática — GitHub Actions

- Se creó [`.github/workflows/phase0-static-baseline.yml`](../.github/workflows/phase0-static-baseline.yml) en `main`: `pnpm typecheck`, `pnpm build`, `composer validate`, `composer lint`, sintaxis PHP, control de contraseña hardcodeada.
- Workflow **sin acceso a Supabase**: no usa secretos, migraciones, seeders ni despliegue.
- Primera ejecución [39354337434](https://github.com/jwichpas/contabilidad/actions/runs/39354337434): **failure** en ambos jobs; en el resultado de API, `steps: []` y `runner_id: 0`. **No se ejecutó ningún paso**; no atribuirlo a fallo de PHP/Vue. Motivo de no asignación de runner por esclarecer en GitHub.
- Se dejó temporalmente el workflow en modo **`workflow_dispatch`** para evitar ejecuciones fallidas en cada push hasta contar con runners disponibles.
- F0.3 sigue EN PROGRESO. No se ha certificado compilación ni ejecución del suite Laravel.

## 12. Regla de esquema Laravel y continuación técnica (2026-10-19)

**Instrucción obligatoria del usuario:** creación/modificación de tablas y cualquier DDL mediante **migraciones Laravel**; Supabase se usa como PostgreSQL administrado y para consultas/advisories de solo lectura. Ver [política formal](ARQUITECTURA-BD-LARAVEL.md).

**Cambios versionados en `main` sin mutar la base:**
- Nuevo `backend/app/Console/Commands/AuditPhaseZero.php`: comando Artisan `contabilidad:auditar-fase0` con `SELECT` de inventario e integridad. Sintaxis PHP revisada mediante `php -l` local (correcta); **ejecución real en Laravel pendiente**.
- `backend/config/database.php`: la conexión administrativa `pgsql_migrations` ya no hereda `DATABASE_URL` del backend normal, que podría usar el rol equivocado.
- `backend/phpunit.xml`: se eliminaron las configuraciones de host, puerto, nombre y usuario de DB fijadas en el XML; se obtienen del entorno no versionado. La contraseña real anterior **no se ha rotado**.
- `backend/.env.example`: ejemplo actualizado para separar rol de aplicación restringido y rol administrativo que migrará desde Artisan.
- La migración inicial del rol `app_backend` contiene un **fallback de contraseña por defecto**; riesgo **R15**, por corregir sin editar destructivamente migraciones ya ejecutadas.

**Inventario adicional directamente verificado en PostgreSQL** (todas las consultas fueron `SELECT`):

| Objeto | Cantidad |
|---|---:|
| Tablas `public` | 31 |
| Índices | 94 |
| Funciones | 5 |
| Triggers no internos | 6 |
| Políticas RLS | 14 |
| Migraciones Laravel aplicadas, sin nombres duplicados | 14 (5 lotes) |
| Tablas sin RLS | 18 |

**Fuentes de validación del procedimiento:** [Laravel 11 — Migraciones](https://laravel.com/docs/11.x/migrations) y [Artisan](https://laravel.com/docs/11.x/artisan). Los 14 nombres de migraciones GitHub coinciden con los registros de la tabla `public.migrations`; la ausencia de diferencias nominales no prueba equivalencia completa de esquema.

**Próxima comprobación real:** ejecutar el comando Artisan de auditoría y `php artisan migrate:status --database=pgsql_migrations` desde un entorno Laravel con credenciales renovadas, sin modificar tablas. Pruebas Pest y compilación Vue continúan pendientes por acceso a ejecutor/runner y resguardo de credenciales. Hasta entonces **no se declara F0 cerrada**.

## 13. Consolidación F0: herramientas reproducibles y revisión funcional (2026-10-19)

**Documento de evidencia por módulo:** [FASE-0-MATRIZ-FUNCIONAL.md](FASE-0-MATRIZ-FUNCIONAL.md). Se registraron rutas de Laravel, vistas y servicios de Vue, brechas funcionales y módulos todavía no probados.

**Código agregado sin modificar PostgreSQL:**
- `backend/app/Console/Commands/AuditPhaseZero.php`: validación de conexión al proyecto, transacción `SET TRANSACTION READ ONLY`, conteos de esquema, integridad del Diario y cotejo de migraciones nominales. **No ejecutado todavía como Artisan**.
- `backend/scripts/phase0-preflight.php`: ocho verificaciones estáticas, sin dependencias y sin conexión a BD. Sintaxis comprobada con PHP local; pruebas con fixture (8/8 pasan con configuración válida y 7/8 tras simular una contraseña en `phpunit.xml`). **No equivale a ejecutar PHPUnit o construir Vue**.
- `backend/scripts/phase0-backup.sh`: exportación vía `pg_dump` del esquema `public` a una ruta segura **fuera de Git**, con revisión `pg_restore --list` y SHA-256. Sintaxis Bash validada, ejecución sin variables falla cerrada. **Sin ejecución real de respaldo, restauración o creación de otra base**.
- `.github/workflows/phase0-static-baseline.yml`: incluido paso de preflight estático; ejecución manual mientras no haya runner.

**Hallazgos nuevos para fases posteriores (no ejecutar en F0):**
1. Frontend `JournalEntryView.vue` ya presenta partidas agregadas en una vista y utiliza `parseFloat`/`toFixed` para conversiones monetarias. En F2 se corregirá precisión de extremo a extremo, sin rehacer la vista por defecto.
2. `PostJournalEntryService::reverse()` existe; no se encontró endpoint correspondiente en `backend/routes/api.php`. Integración faltante en F2.
3. `AppSidebar.vue` y `useAuthSession.ts` ya incluyen selector/estado global de empresa. Revisión F4 debe aprovechar esa implementación.
4. Suite Pest usa `DatabaseTransactions`, pero pruebas de tenancy realizan `SET ROLE` y pruebas de monedas ejecutan `DELETE`; sigue pendiente la validación de contención y rollback.

**Comprobación SQL repetida:** 31 tablas, 14 migraciones registradas, 18 tablas sin RLS, 94 índices, 5 funciones, 6 triggers, 14 políticas; 2 asientos, 4 partidas, 0 descuadrados y 0 huérfanas. Todas consultas `SELECT`.

**Condición de salida F0:** pendiente ejecutar inspección en Laravel sobre la misma base (con credenciales rotadas), verificar archivo de respaldo y posibilidad de recuperación, y completar pruebas frontend/backend. No marcar ninguna tarea terminada aún.
