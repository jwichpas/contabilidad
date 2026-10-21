# Fase 1 — Evidencia de auditoría Supabase Dev

Fecha 20/10/2026. Proyecto `contabilidad-database` (`bnjwxphwtkhblvhsnylj`). **Auditoría mediante SELECT y Security Advisor, sin DDL ni escrituras.**

## Evidencia en base real

- Conexión de administración para lectura: `postgres` / BD `postgres`.
- Tablas `public`: **32**. Registros de `public.migrations`: **18**.
- Tablas `public` con **ENABLE y FORCE RLS**: **14**, coinciden con las tablas tenant observadas.
- Evaluación de `has_table_privilege` sobre las **32** tablas: tanto `anon` como `authenticated` tienen **SELECT denegado en todas**. El rol `app_backend` tiene SELECT en 31; `migrations` es la excepción.
- Las 18 tablas sin RLS incluyen catálogos oficiales y tablas globales (por ejemplo `companies`, `users`, `personal_access_tokens`). Que no tengan RLS **no implica acceso público** porque se verificó que `anon` y `authenticated` no poseen SELECT. Queda pendiente revisar INSERT/UPDATE/DELETE, grants indirectos y el aislamiento al usar `app_backend`.
- Security Advisor: **cinco** hallazgos `function_search_path_mutable`: `app_current_company_id`, `trg_fn_enforce_accounting_account_hierarchy`, `trg_fn_prevent_journal_entry_mutation`, `trg_fn_validate_journal_line_context` y `trg_fn_enforce_double_entry_balance`.
- Otra advertencia separada: `auth_leaked_password_protection` en Supabase Auth. El inicio de sesión ERP usa Laravel Sanctum.

## Límites y siguientes pruebas

Esta comprobación solo determina las propiedades del catálogo PostgreSQL y los privilegios SELECT efectivos; **no simula `app_backend` ni demuestra aislamiento por filas**. La sesión usada tiene identidad administrativa, por lo que una lectura de negocio con ella no probaría el comportamiento RLS de la aplicación. No se han corrido POST, migraciones, seeders, UPDATE ni rollback de datos de prueba contra Supabase Dev.

Próximo paso: ejecutar una batería de diagnóstico controlada con el rol real `app_backend` y contexto de tenant, primero solo lectura y luego transacciones reversibles sobre registros ficticios, con protección contra efectos externos. Preparar la migración Laravel incremental para las cinco funciones una vez probado su impacto en triggers y aprobado el cambio. Docker seguirá reservado para restauraciones y pruebas destructivas.

Referencia del aviso: https://supabase.com/docs/guides/database/database-linter?lint=0011_function_search_path_mutable

## Ampliación comprobada de permisos DML — 20/10/2026

Consulta real de solo lectura sobre las 32 tablas de `public`, usando `has_table_privilege` para `anon` y `authenticated`: **0 tablas** con permiso efectivo `INSERT`, **0** con `UPDATE` y **0** con `DELETE` para cualquiera de esos dos roles. Combinado con la comprobación SELECT anterior, **0 de 32 tablas** presentan permisos efectivos DML públicos para esos roles. Esto no valida privilegios sobre secuencias, funciones, vistas ni políticas ejecutadas bajo `app_backend` y no sustituye pruebas HTTP. No hubo escrituras a Supabase.


## F1.2 — Validación real de dos empresas — 21/10/2026

**Nueva evidencia: empresa B creada en la base única Supabase Dev por migración Laravel.** El usuario ejecutó `php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_20_235900_create_dev_company_b_via_laravel.php --force` y obtuvo **DONE (9 s)**. Migración confirmada por SELECT administrativo en `public.migrations`, **batch 10**. No se utilizó un INSERT administrativo directo para dar de alta la empresa; la migración asignó temporalmente `pgsql` al código de aplicación, con `app_backend`, `TenantContext` y transacción; el migrador registró el lote mediante `pgsql_migrations`.

Inventario confirmado con agregados de solo lectura desde Supabase:

| Empresa | Membresías | Configuraciones | Monedas | Actividades económicas | Cuentas PCGE |
|---|---:|---:|---:|---:|---:|
| ID **1** (preexistente) | 1 | 1 | 3 | **0** (pendiente de completar perfil) | 1.764 |
| ID **182** (ficticia, nueva) | 1 | 1 | 2 | 1 | 1.764 |

Salida aportada por el usuario para `php scripts/phase1-rls-cross-company-readonly.php` usando la conexión restringida `app_backend`, transacciones READ ONLY con `set_config(..., true)` y `ROLLBACK`:

```text
[PASS] Contexto 1: 0 filas ajenas en 14 tablas; visibles=1842
[PASS] Contexto 182: 0 filas ajenas en 14 tablas; visibles=1776
[PASS] No hubo escrituras; se usó rollback en todas las transacciones.
```

El script `phase1-rls-readonly-check.php` ya había pasado el control fail-closed sin empresa con **0 filas empresariales visibles en 14 tablas**, y dejó **advertencia de 7 eventos globales de audit_logs** visibles sin contexto. La política de SELECT sobre `audit_logs` permite registros `company_id IS NULL` y requiere decisión de acceso específico; los contadores de filas visibles anteriores **incluyen registros globales, cuando existan**, y no equivalen a totales de registros empresariales exclusivamente.

**Alcance logrado:** la lectura SQL interempresa bajo RLS está validada para 14 tablas y dos empresas existentes; el alta Laravel multiempresa con PCGE y datos básicos también quedó observada. **NO significa que Fase 1 esté cerrada**: siguen pendientes pruebas de escritura interempresa, autorización por roles sobre rutas HTTP, reversión de permisos, bloqueo de logs globales si corresponde, cinco funciones `search_path`, evaluación de catálogos/globales y ejecución de CI (GitHub Actions bloqueado por facturación). No usar la suite `CompanyRoleAuthorizationTest.php` directamente contra Supabase Dev: crea usuarios y empresas, está diseñada solo para PostgreSQL efímero.


## F1.3 — Resultado HTTP real y siguiente suite (21/10/2026)

El usuario ejecutó `php artisan test tests/Feature/Security/ExistingCompaniesHttpReadOnlyTest.php` con:

```text
PASS Tests\\Feature\\Security\\ExistingCompaniesHttpReadOnlyTest
✓ it separates real company account IDs and journal entries through Laravel HTTP GET (7.22s)
Tests: 1 passed (16 assertions)
Duration: 7.48s
```

**Evidencia aprobada:** a través de GET Laravel con sesión autenticada, el usuario pudo consultar las dos empresas, sus cuentas operativas y asientos sin mezcla de IDs, sobre Supabase Dev con `app_backend`. Se usó `DatabaseTransactions` y rutas solo de lectura.

**Siguiente trabajo de F1.3, aún NO ejecutado:** se amplió el mismo test con tres casos: 401 anónimo para rutas GET, 400 ante identificadores de empresa falsificados, 403 ante empresa inexistente, recuperación de acceso autorizado, secuencia GET A→B→A y limpieza de `app.current_company_id`/`app.current_user_id` al terminar. El servicio `ListUserCompaniesService` ahora restaura `app.current_user_id` al finalizar un contexto SQL anidado. Publicación de código no es evidencia de PASS; requerir resultados locales.

**No ejecutar pruebas POST de roles sobre Supabase Dev**; permanecen reservadas al PostgreSQL efímero hasta diseñar ensayos transaccionales seguros específicos.


## F1.3 — Evidencia ampliada: 4/4 pruebas HTTP GET — 21/10/2026

Salida reproducida por el usuario después de sincronizar `main` y validar sintaxis:

```text
PASS Tests\\Feature\\Security\\ExistingCompaniesHttpReadOnlyTest
✓ separates real company account IDs and journal entries through Laravel HTTP GET
✓ rejects anonymous access to all reviewed tenant GET routes
✓ denies malformed and unassigned company IDs then permits its own company
✓ does not retain company context or cached accounts across A to B to A reads
Tests: 4 passed (40 assertions)
Duration: 19.00s
```

**Aprobado para operaciones probadas:** listado autenticado de dos empresas, GET de cuentas y asientos separados, HTTP 401 para invitado, 400 para encabezados malformados, 403 para empresa inexistente, y cambio A → B → A sin fuga de contexto PostgreSQL user/tenant ni de caché de cuentas. No cubre acceso indebido con usuarios diferentes ni ejecución real de POST: esas puertas siguen abiertas.

**Siguiente suite preparada, aún no ejecutada:** `backend/tests/Feature/Security/ExistingCompaniesRoleGuardReadOnlyTest.php`. Utiliza una petición GET local simulada y ejecuta directamente `RequireCompanyRole` con una respuesta ficticia; **no llama a controladores de escritura ni realiza DML**. Verifica ADMIN/CHIEF_ACCOUNTANT separados entre empresas, rutas mal configuradas, rol inexistente, empresa ajena y usuario anónimo. Ejecutar exclusivamente ese archivo con `php artisan test`, después de `php -l`; para el resto de tests de autorización que generan usuarios y empresas, usar PostgreSQL efímero de CI.


## F1.3 — Autorización de roles tras migración PHP 8.5/Laravel 13 — 21/10/2026

El usuario ejecutó desde Windows, con `app_backend` y Supabase Dev, las siguientes pruebas **exclusivamente de lectura o middleware con handler simulado**:

```text
PASS Tests\\Feature\\Security\\ExistingCompaniesHttpReadOnlyTest
Tests: 4 passed (40 assertions)
Duration: 38.13s

PASS Tests\\Feature\\Security\\ExistingCompaniesRoleGuardReadOnlyTest
✓ it enforces company-specific ADMIN and CHIEF_ACCOUNTANT roles without executing writes
✓ it fails closed for missing or invalid route roles and unauthenticated users
Tests: 2 passed (18 assertions)
Duration: 11.06s
```

**Acumulado F1 bajo Laravel 13: 6 pruebas, 58 aserciones PASS.** El test de roles valida autorizaciones **al invocar directamente el middleware** `RequireCompanyRole` en contextos de empresa A/B; no ejecuta los controladores de escritura, no modifica membresías ni demuestra todavía respuestas HTTP completas POST/PUT/PATCH o bloqueos de DML en PostgreSQL. El test también valida que las peticiones sin roles válidos no llegan al handler simulado.

El usuario publicó en `main` el lock Laravel 13 / PHP 8.5 en commit `be67509`. Se actualizó el workflow de seguridad F1 a PHP 8.5 (jobs estático y migraciones sobre PG17 efímero), revisión de lock con Composer y exclusión en el workspace CI de la migración excepcional de datos ficticios de Supabase Dev; **resultados de GitHub Actions todavía no confirmados**.

**Pendientes:** comprobar CI real, pruebas de roles y escritura con PostgreSQL 17 efímero, seguridad de los logs globales, cinco funciones con `search_path` mutable y autorización integral de rutas. No cerrar F1 por los 58 asserts de lectura/guard.


## F1.3 — Preparación de pruebas DML reales en PostgreSQL desechable (21/10/2026)

**Evidencia de GitHub Actions:** corrida [#41](https://github.com/jwichpas/contabilidad/actions/runs/39663018271), commit `ae33d476`, evento `push`, concluida con **failure** en los tres jobs (`audit-script`, `postgres-rls-contract`, `laravel-real-migrations`). La consulta de los jobs muestra **0 pasos** y la descarga de logs no está disponible. **No se ha confirmado aún si la causa es configuración de facturación/runners o un fallo técnico; no declarar CI PASS.**

**Código preparado en `main`:**

- `backend/tests/security/phase1-real-cross-tenant-writes.sql`: prueba **RLS real** de `company_areas` con dos empresas ficticias en PostgreSQL 17 `contabilidad_ci`. Usando `SET LOCAL ROLE app_backend` sin BYPASSRLS, verifica lectura autorizada, `INSERT` propio, `INSERT` interempresa denegado, `UPDATE` y `DELETE` de otro tenant con 0 filas afectadas, rechazo al cambiar `company_id`, `UPDATE/DELETE` propios permitidos y denegación sin contexto. Todo está dentro de una transacción revertida con `ROLLBACK`. El script bloquea bases que no sean `contabilidad_ci`, rol distinto de `postgres` o ejecución no TCP.
- `CompanyRoleAuthorizationTest.php`: corregido el orden de integridad referencial: primero crear usuarios sin empresa predeterminada, después membresías y al final `users.current_company_id`. Se corrigieron valores booleanos de prueba para `PDO::ATTR_EMULATE_PREPARES` usando SQL `TRUE/FALSE` tipado. **Solo se ejecutará en CI desechable**, nunca contra Supabase Dev.
- `.github/workflows/phase1-security-static.yml`: ejecuta el nuevo SQL después de migrar el esquema real en PostgreSQL 17 y antes de las suites Pest. Continúa excluyendo exclusivamente del workspace CI la migración ficticia de empresa B (solo Supabase Dev).

**Estado:** cambios publicados, pero **sin ejecución comprobada** del SQL ni de los tests que crean registros. Mantener F1 abierta. La suite Laravel 13 ya aprobada contra Supabase Dev se limita a 6 pruebas y 58 aserciones de lectura/guard con handler simulado. La prueba DML aislada no debe confundirse con un PASS hasta ejecutar su job y observar el log.

## F1.3 — Alternativa local aislada ante bloqueo de GitHub Actions (21/10/2026)

La ejecución [#43](https://github.com/jwichpas/contabilidad/actions/runs/39664475037) se observó con estado `failure` pero **sin jobs publicados** al consultar GitHub; ejecuciones anteriores igualmente muestran jobs fallidos sin pasos ni logs. No asumir que el SQL se ejecutó. Revisar las advertencias de GitHub Actions y la facturación/runners desde el sitio de GitHub; la causa exacta no está confirmada.

Como alternativa independiente, se publicó `backend/scripts/phase1-isolated-writes-docker.ps1` para Windows con Docker Desktop Linux:

- Crea PostgreSQL **17-alpine temporal**, base `contabilidad_ci`, contraseña de prueba aleatoria y puerto **solo 127.0.0.1**.
- Verifica un token aleatorio creado únicamente en ese PostgreSQL y la conexión Laravel a ese mismo contenedor **antes de cualquier migración**. Rechaza `bootstrap/cache/config.php` y rutas/conexiones imprevistas.
- Aplica las **15 migraciones de esquema/servicio independientes del proveedor** usando `php artisan migrate --path=... --database=pgsql_migrations` una a una, sin mover archivos en el repositorio. Las cuatro migraciones reservadas a Supabase (ACL públicas, rotación, listado global y empresa B) quedan intactas y no se aplican en Docker.
- Ejecuta `phase1-real-cross-tenant-writes.sql` con el rol real `app_backend` en dos empresas ficticias, todos los DML revertidos.
- Comprueba nuevamente la conexión `app_backend` de Laravel mediante token de Docker y ejecuta las suites `CompanyRoleAuthorizationTest.php` y `TenantContextNestedTest.php` sobre el contenedor desechable.
- En `finally` restaura variables de entorno y elimina el contenedor. **Ningún DML a Supabase Dev ni producción.**

**Ejecutar exclusivamente el script aislado, no los archivos Pest de escritura directamente en Supabase Dev:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
$tokens = $null
$errors = $null
[System.Management.Automation.Language.Parser]::ParseFile(
    (Resolve-Path .\scripts\phase1-isolated-writes-docker.ps1).Path,
    [ref]$tokens, [ref]$errors
) | Out-Null
if ($errors.Count -ne 0) { $errors | Format-List; throw 'Sintaxis PowerShell incorrecta' }
.\scripts\phase1-isolated-writes-docker.ps1
```

**Estado:** runner/script SQL publicados, **no ejecutados todavía en Windows**, por lo tanto no declarar DML PASS. La CI sigue sin certificar y las cuatro migraciones atadas a Supabase requieren puertas independientes.


## F1.3 — Ejecución Docker real: SQL avanza, 1 fallo HTTP detectado (21/10/2026)

**Evidencia entregada por el usuario al ejecutar `phase1-isolated-writes-docker.ps1`:**

```text
[PASS] Conexion app_backend sujeta a RLS en Docker efimero
[HTTP] Ejecutando roles con escrituras de prueba solo en Docker.
FAIL Tests\\Feature\\Security\\CompanyRoleAuthorizationTest
PASS Tests\\Feature\\Security\\TenantContextNestedTest
Tests: 1 failed, 11 passed (35 assertions)
Expected response status code [403] but received 422.
[LIMPIEZA] Contenedor PostgreSQL efimero eliminado.
```

La prueba fallida fue `it rejects even valid roles if the destination company is inactive`. El 422 de campos `entry_date`/`currency`/`description`/`lines` demuestra que se alcanzó la validación del formulario POST en vez de rechazarse por empresa inactiva. Se inspeccionó `SetTenantContext` y `RequireCompanyRole`: ambos filtran `companies.is_active = true`. **Hipótesis principal del fallo de fixture:** `forceFill(['is_active' => DB::raw('FALSE')])->save()` en Eloquent con cast booleano no almacenó `FALSE` como se pretendía.

**Correcciones en main, aún NO reejecutadas:**
- Test `CompanyRoleAuthorizationTest.php`: cambia a `DB::table('companies')->where('id', ...)->update(['is_active' => DB::raw('FALSE')])`, exige exactamente 1 fila afectada y verifica con SQL `CASE WHEN is_active IS FALSE THEN 1 ELSE 0 END` antes del POST esperado 403. Si sigue devolviendo 422 con la empresa realmente inactiva, hay un problema genuino en el middleware y se investigará, sin relajar el test.
- Script Docker `phase1-isolated-writes-docker.ps1`: **ya no confía solo en exit code de `php artisan test`**, pues imprimió un `[PASS]` falso pese al fallo Pest. Ahora exige reporte `--log-junit` verificable, exactamente **12 casos** de las dos suites, **0 nodos failure/error y 0 suites con fallos**. El reporte ausente, mal formado o incompleto detiene la aprobación; se limpia en `finally`.
- Workflow `phase1-security-static.yml`: valida también el reporte JUnit de sus dos suites; runners de Actions siguen sin evidencia de ejecución.

**Alcance probado:** se observó progreso satisfactorio del SQL de escritura A/B (se alcanzó el siguiente paso HTTP), pero **la fase combinada Docker/HTTP fue FAIL y continúa abierta**. La base PostgreSQL efímera se eliminó. Nada de ello implica acceso a Supabase Dev.

**Reejecución permitida solo mediante ejecutor Docker:**
```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
.\scripts\phase1-isolated-writes-docker.ps1
$code = $LASTEXITCODE
Write-Host ('Exit code F1.3: ' + $code)
if ($code -ne 0) { throw 'F1.3 pendiente: hubo errores' }
```


## F1.3 confirmada y F1.4 preparada (21/10/2026)

### Evidencia F1.3 (ejecución real aportada por el usuario)

```text
[PASS] Conexion app_backend sujeta a RLS en Docker efimero
PASS Tests\\Feature\\Security\\CompanyRoleAuthorizationTest
PASS Tests\\Feature\\Security\\TenantContextNestedTest
Tests: 12 passed (37 assertions)
[PASS] Pest JUnit: 12 pruebas, 0 fallos/errores verificados.
[PASS] F1.3 escritura interempresa y pruebas HTTP en PostgreSQL aislado.
[LIMPIEZA] Contenedor PostgreSQL efimero eliminado.
```

El SQL previo al tramo HTTP validó aislamiento INSERT/UPDATE/DELETE real sobre `company_areas` con empresas ficticias y ROLLBACK (el ejecutor no habría avanzado a HTTP si fallaba `psql`). Los casos HTTP incluyen denegación de mutaciones por rol, revocación inmediata, contexto anidado, empresa inactiva y peticiones anónimas. **Sumado a las 6 pruebas/58 aserciones previas de solo lectura/guard en Supabase Dev: 18 tests y 95 aserciones PASS bajo Laravel 13**, en dos entornos distintos. No confundir la suma con la ejecución de una suite integral única. GitHub Actions continúa pendiente por falta de runners/logs verificables.

### Diagnóstico F1.4, exclusivamente SELECT/metadata en Supabase Dev

Se consultaron el catálogo `pg_proc`, políticas `pg_policy`, conteos agregados de `audit_logs` y los asesores de seguridad de Supabase, **sin DML/DDL**:

- Cinco funciones en `public` sin `proconfig.search_path`: `app_current_company_id`, `trg_fn_enforce_accounting_account_hierarchy`, `trg_fn_prevent_journal_entry_mutation`, `trg_fn_validate_journal_line_context`, `trg_fn_enforce_double_entry_balance`. Todas son `SECURITY INVOKER`; el advisor `function_search_path_mutable` reportó **5 WARN**.
- La política SELECT de `public.audit_logs` es `company_id IS NULL OR company_id = public.app_current_company_id()`. En `audit_logs` hay **40 registros**, de los cuales **7 globales** (`company_id IS NULL`), y **los 7 no tienen `user_id` asociado**. El OR permite a `app_backend` consultar los 7 registros globales aun sin contexto de empresa. No se inspeccionó ni divulgó el contenido de esos eventos.
- Un advisor independiente `auth_leaked_password_protection` advierte que la protección de contraseñas filtradas de Supabase Auth está desactivada. La autenticación de este ERP se realiza mediante Laravel/Sanctum; antes de recomendar cambios en Auth, confirmar si también se utiliza Supabase Auth.

Referencias: [linter de funciones](https://supabase.com/docs/guides/database/database-linter?lint=0011_function_search_path_mutable); [seguridad de contraseñas](https://supabase.com/docs/guides/auth/password-security#password-strength-and-leaked-password-protection).

### Cambios preparados en GitHub: **todavía no aplicados en Supabase Dev**

- `backend/database/migrations/2026_10_21_010000_harden_postgres_function_search_path.php`: fija `search_path = ''` en las 5 funciones, exige `postgres`, controla el ambiente local Dev aprobado o `contabilidad_ci` efímero, modifica de forma transaccional y verifica el helper RLS.
- `backend/database/migrations/2026_10_21_020000_close_global_audit_log_read_leak.php`: reemplaza la política SELECT por `company_id IS NOT NULL AND company_id = public.app_current_company_id()`, conservando intactos los 7 registros globales del sistema. No crea permisos de lectura privilegiada nuevos. Las dos migraciones son endurecimientos **sin down destructivo/automático**.
- `backend/tests/security/phase1-real-cross-tenant-writes.sql`: añade regresiones A, B y sin tenant sobre eventos `F1_GLOBAL_SYSTEM` y `F1_TENANT_A/B`, además de los DML existentes; siguen revertidos por ROLLBACK.
- `backend/tests/security/phase1-search-path-rehearsal.sql`: ahora verifica los cinco `search_path` ya aplicados sin DDL temporal.
- `.github/workflows/phase1-security-static.yml`: incorpora lint PHP de las migraciones nuevas. GitHub Actions sigue sin certificación de ejecución.

**Siguiente puerta antes de aplicar a Dev:** repetir el ejecutor Docker después de sincronizar `main` y observar el PASS de migraciones nuevas + regresión SQL + JUnit 12/12. No ejecutar `php artisan migrate` genérico contra Supabase Dev; cualquier futura aplicación requerirá rutas explícitas `--database=pgsql_migrations --path=...`, respaldos y verificación de los avisos del advisor.

**Ajuste final del ejecutor Docker F1.4:** además de las dos migraciones nuevas, ahora ejecuta explícitamente `phase1-search-path-rehearsal.sql` contra PostgreSQL 17 aislado **antes** de probar escritura cruzada y JUnit. Así el siguiente PASS también validará la configuración persistida de las cinco funciones, sin requerir acceso a Supabase Dev.


## F1.4 — Fallo del primer ensayo y correcciones (21/10/2026)

**Evidencia enviada por el usuario** al ejecutar `phase1-isolated-writes-docker.ps1` contra PostgreSQL 17 efímero:

```text
2026_10_19_010000_add_company_economic_profile ... FAIL
La migración de perfil económico requiere contabilidad-database.
2026_10_21_010000_harden_postgres_function_search_path ... FAIL
La auditoria search_path no confirmo las cinco funciones.
2026_10_21_020000_close_global_audit_log_read_leak ... DONE
[FUNCIONES] ... No estan fijados los cinco search_path: 0/5
[FAIL] docker termino con codigo 3
[LIMPIEZA] Contenedor PostgreSQL efimero eliminado.
```

**Tres causas comprobadas:**

1. La migración de perfil económico `2026_10_19_010000` también está restringida expresamente al proyecto Supabase Dev. La lista de exclusiones del contenedor/CI tenía cuatro migraciones; deben ser **cinco**. No ampliar ni eliminar el guard de la migración Dev.
2. Las cinco funciones fueron comprobadas con una coincidencia literal de `proconfig` para `search_path=` tras `SET search_path = ''`. PostgreSQL serializa ajustes vacíos con comillas según su configuración; la verificación produjo `0/5` y la transacción se revirtió. Se cambió el endurecimiento a `SET search_path = pg_catalog` (entorno seguro y determinista, todas las referencias a tablas dentro de los cuerpos existentes están calificadas explícitamente con `public.`) y ahora se exige `'search_path=pg_catalog' = ANY (proconfig)` tanto en la migración como en la prueba SQL aislada.
3. El ejecutor continuó a la siguiente migración aunque Artisan imprimió `FAIL`. Ahora se consulta inmediatamente `public.migrations` del contenedor temporal después de **cada archivo** y se exige **exactamente una fila** para el nombre migrado. Si falla, detiene el ensayo antes de ejecutar cualquier RLS/JUnit. El workflow Linux verifica asimismo que el total de filas de `public.migrations` coincida con la cantidad exacta de archivos de migración admitidos en CI.

**Estado actual:** las modificaciones están publicadas en `main` pero **NO están ejecutadas de nuevo ni certificadas**. El ensayo F1.4 anterior quedó FAIL y sí limpió Docker. **Supabase Dev sigue intacto:** estas dos migraciones de F1.4 no se aplicaron allí. Mantener sin cambios las 18 pruebas/95 aserciones F1.2-F1.3 ya aprobadas.

**Próxima ejecución en Windows:**
```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
.\scripts\phase1-isolated-writes-docker.ps1
if ($LASTEXITCODE -ne 0) { throw 'F1.4 no aprobado' }
```

El PASS real requiere: todos los archivos elegibles registrados en `migrations`, cinco funciones con `search_path=pg_catalog`, eventos globales invisibles por RLS A/B/sin tenant, escrituras cruzadas denegadas, 12 tests y 0 errores/fallos JUnit, y eliminación del Docker temporal. Si cualquiera falla, no aplicar migraciones a Dev.


**Nota posterior de integridad del script:** una revisión del archivo remoto detectó que la primera publicación del control `migrations` había quedado truncada en una expresión PowerShell y con un segmento duplicado al final. Se reconstruyó **el archivo completo** y se volvió a leer desde GitHub para confirmar un único `try/catch/finally`, un único cierre `if (-not $passed) { exit 1 }`, el bloque íntegro de comprobación de migraciones y las puertas de JUnit. **Aún se requiere analizar la sintaxis PowerShell en Windows antes de ejecutarlo**; la inspección estructural remota no sustituye el parser nativo. Último arreglo del ejecutor: `0086b5d3`.


## F1.4 — Ensayo Docker aprobado; promoción a Supabase Dev pendiente (21/10/2026)

**Evidencia real del usuario, luego de corregir las cinco exclusiones de Supabase, el `search_path` y la integridad del ejecutor:**

```text
[PASS] Conexion app_backend sujeta a RLS en Docker efimero
PASS Tests\Feature\Security\CompanyRoleAuthorizationTest
PASS Tests\Feature\Security\TenantContextNestedTest
Tests: 12 passed (37 assertions)
[PASS] Pest JUnit: 12 pruebas, 0 fallos/errores verificados.
[PASS] F1.3 escritura interempresa y pruebas HTTP en PostgreSQL aislado.
[LIMPIEZA] Contenedor PostgreSQL efimero eliminado.
```

**F1.4 aprobación del ensayo de regresión aislado:** el runner alcanza el paso HTTP solo si cada migración Laravel elegible consta en `public.migrations`, la comprobación `phase1-search-path-rehearsal.sql` verifica las cinco funciones y `phase1-real-cross-tenant-writes.sql` confirma los controles RLS de auditoría (globales, A/B y sin contexto) y DML con rollback. El JUnit de 12/12 valida el resto. Se actualizó la etiqueta final del runner para indicar F1.4. Estos pasos fueron certificados por el PASS final, aunque el extracto aportado es solo el tramo final. No confundirlo con aplicación real en Supabase Dev.

**Preflight remoto read-only sobre Supabase Dev**, realizado después del PASS (sin alterar tablas):

| Verificación | Valor |
|---|---:|
| Funciones F1.4 ya fijadas a `search_path=pg_catalog` | **0/5** |
| Registros de las dos migraciones F1.4 en `public.migrations` | **0/2** |
| Eventos globales preservados en `public.audit_logs` | **7** |
| Política antigua que incluye `company_id IS NULL` | **1** |

**Siguiente puerta: despliegue exclusivamente mediante Artisan local con conexión administrativa `pgsql_migrations`**, sin `migrate` masivo, `migrate:fresh`, `db:seed`, ni SQL manual en el editor de Supabase. Antes de ejecutarlo, confirmar que `php artisan --version` indica Laravel 13 y que el archivo `backend/.env` utiliza el proyecto Dev aprobado y no producción. Ambas migraciones implementan guard de ambiente, identidad PostgreSQL y proyecto; cualquier fallo debe detener el avance.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php artisan --version
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_21_010000_harden_postgres_function_search_path.php --force
if ($LASTEXITCODE -ne 0) { throw 'F1.4 primera migracion fallida' }
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_21_020000_close_global_audit_log_read_leak.php --force
if ($LASTEXITCODE -ne 0) { throw 'F1.4 segunda migracion fallida' }
```

**IMPORTANTE:** en versiones anteriores del ejecutor, `php artisan` podía imprimir FAIL y devolver código cero. Por tanto, **inspeccionar también que cada migración muestre DONE** y validar posteriormente las dos filas en `migrations` mediante una consulta de catálogo independiente; no interpretar únicamente `$LASTEXITCODE` como éxito. La salida de la primera migración se debe verificar antes de ejecutar la segunda. Verificar en el advisor que desaparezcan los cinco `function_search_path_mutable`, que los 7 eventos globales permanezcan íntegros y que la política de SELECT ya no admita `company_id IS NULL`. No cambiar registros ni permisos del usuario ni del plan de cuentas.

**GitHub Actions CI** continúa sin estado PASS verificable por bloqueo externo de runners/facturación; por ello F1 sigue abierta aunque el ensayo Docker esté aprobado.
