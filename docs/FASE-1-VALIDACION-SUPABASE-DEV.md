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
