# F2.2 — Idempotencia de asientos contables: implementación y validación manual

**Fecha:** 22/10/2026 · **Rama:** `main` · **Estado F2.2: APROBADO 22/10/2026.** Docker PostgreSQL 18, Vue, migración 22 y HTTP Sanctum/app_backend real en Neon DEV con idempotencia 201/200/409 y ROLLBACK verificados.

## Certificación F2.2 — Neon HTTP real PASS (22/10/2026)

**Evidencia proporcionada por el usuario:**

- `php scripts/phase2-neon-analytic-http.php --probe=contabilidad-dev`: **PASS** idempotencia en `app_backend`, HTTP **201/200/409**, **1 recibo, 1 asiento y 1 auditoría**; POST/GET tenant A/B, 422 por cuenta/centro ajeno y descuadre, 403 de rol, extorno HTTP 201 y doble extorno 422; desencadenó `ROLLBACK`; comprobación final **0 asientos/líneas/links/periodos/tipos de cambio/auditorías y 3 DRAFT**.
- `php scripts/phase2-neon-journal-idempotency.php --verify`: **PASS** esquema Neon PG18 con **22 migraciones**, 3 constraints, 2 índices UNIQUE, 2 políticas RLS, `app_backend` con SELECT/INSERT solamente, 3 DRAFT, **0 asientos ni recibos persistidos**.
- Docker PG18: segunda conexión no logra tomar advisory lock; RLS entre tenants; UPDATE denegado. Frontend: typecheck/build y 16 unitarias PASS.

**Decisión:** actividad **F2.2 [x]**. Incidencia local no bloqueante para el ensayo: faltan `CACHE_STORE`, `SESSION_DRIVER` y `QUEUE_CONNECTION` en el `.env`; en las pruebas se utilizaron drivers array/sync **temporales y solo en memoria**. Para operación normal del API se debe configurar su cache/sesión/cola y repetir diagnóstico.

---

## Neon DEV — migración 22 PASS y ajuste del ensayo HTTP (22/10/2026)

**Evidencia manual del usuario:** `phase2-neon-journal-idempotency.php --apply=contabilidad-dev` terminó PASS. Segundo `--verify` confirmó 22 migraciones, 3 constraints, 2 índices UNIQUE, 2 políticas FORCE RLS, `app_backend` SELECT/INSERT sin UPDATE/DELETE y 3 DRAFT. Una verificación independiente de Neon comprobó 22 migraciones, cero asientos/líneas/auditoría/recibos y 0 ACTIVE.

**Incidencia resuelta:** el primer `phase2-neon-analytic-http.php --probe=contabilidad-dev` terminó con un error Laravel `Illuminate\\Support\\Manager::createDriver()` antes de publicar un PASS de HTTP y luego efectuó ROLLBACK. El ensayo Docker equivalente funcionaba porque el wrapper aislaba cache/sesión/cola en drivers locales de prueba. Se corrigió el *harness* de Neon para configurar `cache.default=array`, `session.driver=array`, `queue.default=sync` **solo en memoria durante esa ejecución**, sin alterar `.env`, ni la configuración global ni el esquema Neon. El manejador de excepciones ahora identifica la fase, con SQLSTATE ante errores SQL.

**Distinción:** este ajuste permite ejecutar el HTTP real de Laravel sin depender de una caché/sesión local mal configurada; **no certifica** que la configuración normal del servidor web esté correcta. La aplicación real debería usar un `CACHE_STORE` y `SESSION_DRIVER` válidos (por ejemplo `file` en desarrollo), y `QUEUE_CONNECTION=sync` si no hay workers. Si se usa `database`, deben existir las tablas correspondientes.

**Prueba pendiente tras actualizar `main`:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-analytic-http.php
php scripts/phase2-neon-analytic-http.php --probe=contabilidad-dev
php scripts/phase2-neon-journal-idempotency.php --verify
```

**PASS recibido:** HTTP 201 creación, 200 reintento, 409 conflicto, extorno/roles/RLS, ROLLBACK confirmado y cero movimientos. F2.2 figura `[x]` en el plan maestro.

---

## Bloqueo preventivo de configuración local en Windows — 22/10/2026

**Resultado informado:** `php -l` y `--plan` del instalador PASS; los cinco comandos que pretendían verificar/aplicar contra Neon terminaron en `[BLOQUEO] Entorno distinto a Neon contabilidad-dev directo` (o mensaje equivalente). Estos scripts verifican primero la configuración Laravel/Neon; **no ejecutaron migración ni cambios de esquema**.

El fallo compartido no demuestra aún qué variable está mal. Puede ser `APP_ENV`, `NEON_TEST_BRANCH_NAME/HOST`, host directo de `DB_HOST` o `DB_MIGRATIONS_HOST`, puerto, nombres de roles, SSL, `DB_URL`/`DATABASE_URL`, `DB_MIGRATIONS_URL`, pooling o variables de proceso que prevalecen sobre `.env`. La prueba HTTP también exige `APP_KEY` válido y credencial `DB_APP_BACKEND_ROLE_PASSWORD` coincidente con `DB_PASSWORD`.

**Se añadió diagnóstico seguro, 100% local, sin consultas SQL, escrituras, contraseñas ni URLs en la salida:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-connection-diagnostic.php
php scripts/phase2-neon-connection-diagnostic.php --check
```

Corregir las condiciones `[FAIL]` en **`backend/.env` privado**, teniendo en cuenta variables heredadas de PowerShell. **No** pegar ni subir `.env`, contraseñas, tokens o conexiones completas; compartir solo etiquetas PASS/FAIL del diagnóstico. No usar `migrate:fresh`, desactivar guards ni aplicar la migración hasta que este preflight pase. Después ejecutar `--verify` (puede decir PENDIENTE porque la tabla aún no existe), y finalmente el instalador con `--apply=contabilidad-dev` como se describe abajo.

**Estado histórico de este bloqueo:** quedó resuelto. F2.2 se aprobó posteriormente con la migración 22 e integración HTTP real en Neon.

---

## Evidencia manual confirmada — 22/10/2026

**PASS informado por el usuario:** Docker PostgreSQL 18 con 20 migraciones; HTTP bajo `app_backend` sin BYPASSRLS y matriz ACTIVE solo dentro de transacción; idempotencia 201→200 replay / 409 conflicto, un recibo para un asiento, protección de recibos por tenant, denegación UPDATE, prueba de segunda conexión sin obtener el advisory lock, extorno y auditoría, ROLLBACK, eliminación efectiva del contenedor. **Vue**: `pnpm typecheck` PASS; **16/16** pruebas unitarias PASS; `pnpm build` PASS con 1.615 módulos compilados.

**Pendiente para cerrar F2.2 en Neon:** aplicar la migración aditiva con **Laravel** y ejecutar HTTP idempotente en Neon DEV con ROLLBACK. No dar por aplicadas las 22 migraciones hasta ver el resultado local.

**Instalador protegido publicado:** `backend/scripts/phase2-neon-journal-idempotency.php` con `--plan`, `--verify` y `--apply=contabilidad-dev`. Verifica roles, host PG18, 3 empresas ficticias, SHA/metadatos, baseline, índice UNIQUE, FK, CHECK, FORCE RLS y permisos de `app_backend`. El modo `--apply` sí confirma **una migración** mediante Artisan; no es un ensayo ROLLBACK.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-journal-idempotency.php
php scripts/phase2-neon-journal-idempotency.php --plan
php scripts/phase2-neon-journal-idempotency.php --apply=contabilidad-dev
php scripts/phase2-neon-journal-idempotency.php --verify
php scripts/phase2-neon-analytic-http.php --probe=contabilidad-dev
php scripts/phase2-neon-analytic-allocations.php --verify
php scripts/phase2-neon-tenant-integrity.php --verify
```

**Puerta:** no ejecutar `--apply` si la primera verificación del entorno falla. Los tres verificadores posteriores deben confirmar 22 migraciones, HTTP 201/200/409 reversible, cero asientos/recibos tras rollback y tres matrices DRAFT.

---

## Problema concreto

Un usuario puede pulsar «Guardar», perder la respuesta por un corte de red e intentar nuevamente. El asiento pudo haberse confirmado en la base de datos aunque el navegador recibiera un error. La restricción `UNIQUE (company_id, fiscal_period_id, entry_number)` impide duplicar el **número**, pero no garantiza un reintento correcto cuando se genera un nuevo correlativo ni evita respuestas HTTP 500 por colisiones. La idempotencia tiene que asociarse al intento original y responder siempre con el mismo asiento.

## Implementación integrada

- Nueva migración Laravel **aditiva**: `backend/database/portable_migrations/2026_10_22_140000_create_journal_post_idempotency.php`; tabla `journal_post_idempotency` con `company_id`, `user_id`, clave (16–128 caracteres), SHA-256 del payload validado y `journal_entry_id`. FK compuesta del recibo a un asiento **de la misma empresa**, UNIQUE sobre empresa+clave y empresa+asiento, RLS `ENABLE/FORCE`; `app_backend` solo puede consultar/insertar, **nunca actualizar/borrar**.
- Servicio `JournalPostIdempotencyService`: exige transacción del middleware de empresa y obtiene un advisory lock por **empresa+clave** antes de crear periodos/tipos de cambio/asientos. Si no existe recibo, ejecuta el POST normal. Solo si este ha sido exitoso e informado a auditoría inserta el recibo **en la misma transacción**. Si se produce 422 o rollback, el recibo no queda confirmado.
- `POST /api/v1/accounting/journal-entries` admite el encabezado opcional **`Idempotency-Key`**. Primera operación válida: **HTTP 201**. Mismo usuario, empresa, clave y mismo payload: **HTTP 200**, devuelve `data` del asiento original (incluso si fue extornado posteriormente), `Idempotency-Replayed: true`, sin nuevo correlativo, cambio, asiento ni auditoría. Clave reutilizada con otro actor o contenido: **HTTP 409**. Encabezado mal formado: **HTTP 400**.
- Compatibilidad: clientes antiguos **sin** el encabezado siguen enviando su POST habitual, pero **no reciben garantía de deduplicación**. No es una característica que se pueda probar o prometer para todos los clientes mientras no adopten el encabezado.
- Editor Vue envía una clave UUID por nuevo intento. En caso de error de red/servidor ambiguo, conserva clave y payload para repetir con la misma identidad; no reutiliza esa clave tras completar el guardado, cambiar de empresa o modificar el asiento.
- Se adaptó `backend/scripts/phase2-active-rls-docker.ps1` para incluir la migración nueva; el contenedor ejecuta **20 migraciones** (19 anteriores y la de idempotencia), sin modificar Neon. `phase2-active-rls-docker.php` añade comprobaciones **201 → 200 replay**, **409 payload diferente**, número de registros, auditoría única, separación RLS A/B, prohibición UPDATE, replay del asiento después de su extorno y **dos conexiones PostgreSQL distintas** para demostrar que el segundo `app_backend` no puede obtener el mismo advisory lock mientras el primero lo mantiene.

## Puerta de aprobación — estrictamente manual

1. Revisión de sintaxis PHP y test de 20 migraciones en un contenedor **PostgreSQL 18 efímero**.
2. PASS completo del flujo ACTIVE + Sanctum + `app_backend` + FORCE RLS, junto con los casos de reintento y conflicto, sin duplicaciones.
3. Frontend `pnpm typecheck`, `pnpm test:unit` y `pnpm build` PASS en equipo local.
4. Solo después preparar la aplicación **explícita** de la migración en Neon `contabilidad-dev` mediante Laravel; verificar 22 migraciones y RLS. **NO usar `migrate:fresh`**. Antes de aplicar, actualizar los scripts read-only que todavía exigen exactamente 21 migraciones para que acepten el nuevo estado.
5. Validar HTTP de idempotencia en Neon DEV con una transacción reversible; demostrar que el esquema migrado funciona con el rol `app_backend` y que rollback preserva todas las matrices `DRAFT`.
6. Confirmar la casilla F2.2 del plan maestro **solo** cuando también se prueben numeración, concurrencia, idempotencia y atomicidad de manera completa.

### Comandos para ejecutar ahora en PowerShell

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend

php -l database/portable_migrations/2026_10_22_140000_create_journal_post_idempotency.php
php -l app/Services/Accounting/JournalPostIdempotencyService.php
php -l app/Http/Controllers/Api/V1/Accounting/CreateJournalEntryController.php
php -l scripts/phase2-active-rls-docker.php
php scripts/phase2-active-rls-docker.php --plan

docker info --format '{{.OSType}}'
.\scripts\phase2-active-rls-docker.ps1

cd ..\frontend
pnpm typecheck
pnpm test:unit
pnpm build
```

**No ejecutar por ahora `php artisan migrate` contra Neon ni activar matrices de destinos.** La aplicación se coordinará una vez que el nuevo Docker+frontend pasen las pruebas manuales.

## Evidencia pendiente

- **PASS histórico:** F2.3h anterior, con 19 migraciones, fue ejecutado exitosamente por el usuario, pero **NO validó idempotencia**.
- **F2.2 idempotencia nueva:** código publicado; se requieren resultados de la prueba Docker ampliada a 20 migraciones y del frontend.
- **Neon al comenzar esta etapa:** 21 migraciones, 3 empresas DEMO, 3 matrices `DRAFT`, 0 `ACTIVE`, 1.362 rutas. No se aplicó la migración de recibos.

[Checklist rector](../README-PLAN-MAESTRO.md#10-checklist-de-seguimiento) · [Pruebas manuales](VALIDACION-MANUAL-Y-EVIDENCIAS.md).
