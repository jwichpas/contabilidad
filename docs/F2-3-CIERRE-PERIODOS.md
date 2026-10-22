# F2.3 — Cierre, reapertura y bloqueo de períodos contables

**Actualización:** 22/10/2026 · **Rama:** `main` · **Estado:** implementación inicial publicada, **pendiente prueba manual HTTP bajo Neon DEV**. No hay nueva migración: la tabla `fiscal_periods` y sus estados `OPEN / CLOSED / LOCKED` ya existen en el esquema 22.

## Reglas funcionales

| Acción | Estado requerido | Estado nuevo | Auditable | Permisos |
|---|---|---|---|---|
| `close` | `OPEN` | `CLOSED` | `FISCAL_PERIOD_CLOSED` | `ADMIN` o `CHIEF_ACCOUNTANT` |
| `reopen` | `CLOSED` | `OPEN` | `FISCAL_PERIOD_REOPENED` | `ADMIN` o `CHIEF_ACCOUNTANT` |
| `lock` | `CLOSED` | `LOCKED` | `FISCAL_PERIOD_LOCKED` | `ADMIN` o `CHIEF_ACCOUNTANT` |

- API: `POST /api/v1/accounting/fiscal-periods/{periodId}/{action}`, JSON `{"reason":"Motivo documentado de al menos diez caracteres"}`. Se requiere autenticación Sanctum, empresa activa `X-Company-Id` y rol autorizado de **esa misma empresa**.
- No se permite cerrar dos veces el mismo período, reabrir `OPEN`, bloquear un período `OPEN` directamente o reabrir uno `LOCKED`: **HTTP 409**. El rol no autorizado recibe **HTTP 403**. El período de otra empresa responde **404** sin exponer contenido.
- `POST` contable y extornos dirigidos a un período `CLOSED` o `LOCKED` responden **422**, mediante la comprobación `isOpen()` ya existente.
- El motor `FiscalPeriodStatusService` bloquea el mismo período con PostgreSQL `FOR UPDATE` que utilizan `PostJournalEntryService::post` y `reverse`, por lo que cierre y nuevos asientos no pueden adelantar uno al otro sobre la fila del período.
- Antes del cierre se comprueba con NUMERIC PostgreSQL que cada asiento tenga al menos 2 partidas y que Debe/Haber local y extranjera sumen exactamente igual.
- Las mutaciones y los eventos de auditoría son **atómicos** dentro de la misma transacción; si falla el INSERT de auditoría, se revierte también el estado. `closed_at` se establece al cerrar y se limpia al reabrir; en `LOCKED` se conserva.
- `LOCKED` **no tiene operación de reapertura ordinaria**; una corrección extraordinaria necesitaría proceso autorizado y auditado separado, no incluido en esta etapa.

## Diagnóstico de la comprobación diferida (22/10/2026)

**Resultado manual del modo corto:** `--period-only=contabilidad-dev` alcanzó la última fase `F2.3 verify deferred balance triggers`, donde PostgreSQL devolvió `23514 / INSUFFICIENT_JOURNAL_LINES`. Después hubo `ROLLBACK`; la prueba **no está aprobada**.

**Causa confirmada:** el script limpiaba `app.current_company_id` inmediatamente ANTES de ejecutar `SET CONSTRAINTS ALL IMMEDIATE`. Una consulta de solo lectura en Neon confirmó que `trg_fn_enforce_double_entry_balance` es `SECURITY INVOKER`, y `journal_entry_lines` tiene RLS habilitado y forzado. Sin el tenant visible, ese trigger no encuentra las líneas del asiento aunque existan.

**Corrección publicada en `main`:** conservar `app.current_company_id` de la empresa DEMO hasta después de `SET CONSTRAINTS ALL IMMEDIATE`, verificar antes `current_user=app_backend` y el tenant correcto, y solo entonces limpiar el contexto. Ninguna migración, trigger ni permiso remoto fue modificado. Esto corrige la comprobación del *harness*; **falta repetir el modo corto**.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-analytic-http.php
php scripts/phase2-neon-analytic-http.php --period-only=contabilidad-dev
```

La prueba concluyente debe informar `[PASS] F2.3 Neon HTTP`, `[ROLLBACK]` y `[PASS] HTTP POST-ROLLBACK`. No repetir `--apply`: Neon ya tiene 22 migraciones.

---

## Incidencia de prueba y modo corto — 22/10/2026

En la primera ejecución manual del usuario, todas las condiciones de conexión fueron **PASS** y los casos históricos F2.2/F2.3d también pasaron, pero la sección nueva terminó con `[FAIL] F2.3d fase=F2.3 HTTP CLOSE/REOPEN/LOCK: SQLSTATE=23514`. El script hizo **ROLLBACK** y el verificador posterior confirmó las **22 migraciones**, 3 DRAFT, cero asientos ni recibos persistidos. Esta evidencia **no aprueba F2.3**.

`23514` indica violación de una restricción del motor PostgreSQL (puede provenir de un trigger). Sin localizar la instrucción concreta no se atribuye todavía a `fiscal_periods`, ni se modifican restricciones ni migraciones por conjetura.

Se agregó `--period-only=contabilidad-dev`: sigue exigiendo todas las guardas de entorno, conexiones y baseline, crea un asiento mínimo en una transacción `app_backend`, omite los HTTP históricos ya aprobados y prueba únicamente F2.3. El mismo `finally` ejecuta siempre ROLLBACK. Cada operación tiene ahora etiqueta de fase propia. Cuando un error es `QueryException`, solo expone SQLSTATE y etiquetas públicas conocidas, nunca SQL con valores.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-analytic-http.php
php scripts/phase2-neon-analytic-http.php --period-only=contabilidad-dev
php scripts/phase2-neon-journal-idempotency.php --verify
```

Si falla, revisar el campo `fase=...` antes de editar la lógica; no repetir el ensayo completo. Si pasa, queda pendiente la prueba de concurrencia con dos conexiones independientes.

---

## Prueba reversible preparada en Neon DEV

Se amplió `backend/scripts/phase2-neon-analytic-http.php` para probar después de los casos F2.2 existentes: POST de un asiento temporal, cierre correcto, cierre repetido 409, POST/extorno prohibidos 422, auditor 403, reapertura 200, POST permitido 201, segundo cierre, bloqueo definitivo, reapertura denegada 409, POST bloqueado 422 y **cuatro eventos de auditoría**. Se fuerza comprobación de triggers de partida doble. Toda la prueba corre dentro de una transacción `app_backend` iniciada por el script y termina con **ROLLBACK**. La base permanente mantiene 3 DRAFT y 0 movimientos.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend

php -l app/Services/Accounting/FiscalPeriodStatusService.php
php -l app/Http/Controllers/Api/V1/Accounting/ChangeFiscalPeriodStatusController.php
php -l scripts/phase2-neon-analytic-http.php

# No aplica ninguna migración.
php scripts/phase2-neon-analytic-http.php --probe=contabilidad-dev
php scripts/phase2-neon-journal-idempotency.php --verify
```

**Esperado F2.3:** `[PASS] F2.3 Neon HTTP: CLOSE, REOPEN, LOCK...`, seguido de `[ROLLBACK]` y `[PASS] HTTP POST-ROLLBACK`.

## Resultado HTTP Neon PASS y ensayo de concurrencia preparado — 22/10/2026

**Ejecución confirmada por el usuario:** `php scripts/phase2-neon-analytic-http.php --period-only=contabilidad-dev` terminó con:

```text
[PASS] F2.3 Neon HTTP: CLOSE, REOPEN, LOCK, 403 RBAC, 409 transiciones, 422 POST/extorno bloqueados y 4 auditorias.
[ROLLBACK] HTTP vouchers, auditoria, periodos y tipos de cambio revertidos.
[PASS] HTTP POST-ROLLBACK: 0 asientos/lineas/links/periodos/TC/auditoria, 3 DRAFT.
```

Esto **aprueba las transiciones HTTP y su auditoría**, no todavía la prueba de concurrencia de F2.3 ni la interfaz para administración de períodos.

**Nuevo ensayo opcional y aislado:** reutiliza los ejecutores ya existentes de PostgreSQL 18 Docker descartable. El modo especial crea una fixture **confirmada solo dentro del contenedor**, para que dos conexiones físicas independientes puedan bloquear la **misma fila** de `fiscal_periods` (no es visible al segundo proceso si nunca se confirmó). No escribe en Neon ni activa matrices remotas.

- **Carrera cierre primero:** A ejecuta `FiscalPeriodStatusService::change(close)` bajo `app_backend` y mantiene `FOR UPDATE`; B prueba `FOR UPDATE NOWAIT` y debe recibir SQLSTATE `55P03`; A confirma; B ve `CLOSED` y `PostJournalEntryService::post` rechaza el intento; luego B reabre con auditoría.
- **Carrera asiento primero:** A retiene `FOR UPDATE`, B recibe `55P03`; A registra un tercer asiento balanceado y confirma; B cierra el período y comprueba los tres asientos, seis líneas y eventos de auditoría correspondientes.
- **Seguridad:** en Docker se crean roles de prueba, los permisos `app_backend` y las conexiones a PostgreSQL 18; finalmente el contenedor se destruye incluso ante fallo. Las fixture Docker pueden hacerse `COMMIT` *exclusivamente porque el servidor es desechable*. No trasladar ese modo a Neon.

**Windows PowerShell + Docker Desktop:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase1-journal-concurrency-docker.php
.\scripts\phase2-active-rls-docker.ps1 -PeriodConcurrency
```

**Linux/WSL + Docker:**

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase1-journal-concurrency-docker.php
bash -n scripts/phase1-journal-concurrency-docker.sh
bash scripts/phase1-journal-concurrency-docker.sh --f23
```

**Estado:** los dos ejecutores de concurrencia están publicados en `main`, pero **aún no se recibió PASS de ejecución**. F2.3 continúa `[ ]` y el plan general sigue en 6/32 (19%). No repetir la prueba HTTP larga ni aplicar migraciones Neon para validar esta carrera.

---

## Concurrencia Docker aprobada y frontend integrado — 22/10/2026

**Evidencia de ejecución manual aportada por el usuario:**

```text
[PASS] Concurrencia real: B recibe 55P03 mientras A retiene FOR UPDATE.
[PASS] Dos conexiones: 10-00001 y 10-00002, dos cabeceras, cuatro partidas, sin duplicados.
[PASS] F2.3 race CLOSE-first: B 55P03; after commit POST rejected in CLOSED, authorized reopen OK.
[PASS] F2.3 race POST-first: CLOSE waits, journal 10-00003 posted, then CLOSED.
[PASS] F2.3 verified: 3 immutable vouchers / 6 lines / 2 closes / 1 reopen, Docker-only.
[LIMPIEZA] PostgreSQL Docker descartable eliminado.
```

**F2.3 motor backend de cierre:** aprobación técnica de HTTP, roles, RLS, FOR UPDATE, partida doble, secuencia de estados, auditoría y ambas órdenes concurrentes en PostgreSQL 18. Los ensayos guardaron los asientos únicamente en el contenedor descartable, no en Neon.

**Frontend nuevo publicado en `main` (pendiente compilación y aceptación UI):**
- `GET /api/v1/accounting/fiscal-periods?year=2026`: consulta de períodos realmente persistidos en la empresa activa, con `status`, `closed_at`, `entry_count`; no crea períodos implícitos ni muestra datos de otros tenants.
- `FiscalPeriodsView.vue`: ruta hash `#periodos` y menú **Períodos contables**, doce meses, navegación por año, indicadores y estados `OPEN/CLOSED/LOCKED`. Un mes sin período persistido aparece como **Sin registrar** y no permite cambiar estados.
- **Acciones solo para ADMIN/CHIEF_ACCOUNTANT**: botones según la transición permitida, confirmación con motivo de 10 a 500 caracteres, advertencia explícita para `LOCKED`, errores 409 de concurrencia y recarga. La autorización efectiva sigue estando en el middleware Laravel.
- El estado activo de empresa se obtiene del selector global ya existente: no se solicita seleccionar otra empresa por cada vista.

**Pruebas manuales siguientes:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l app/Http/Controllers/Api/V1/Accounting/ListFiscalPeriodsController.php
php -l app/Services/Accounting/FiscalPeriodStatusService.php
php artisan route:list --path=api/v1/accounting/fiscal-periods
# Prueba reversible actualizada, incluye GET de período y conteo para empresa A:
php scripts/phase2-neon-analytic-http.php --period-only=contabilidad-dev
php scripts/phase2-neon-journal-idempotency.php --verify

cd ..\frontend
pnpm typecheck
pnpm test:unit
pnpm build
```

**Aceptación visual:** navegar al módulo tras autenticarte; comprobar la pantalla vacía en Neon DEV (cero períodos confirmados), filtros anuales y responsividad; en desarrollo usar fixtures descartables o el API con datos ficticios aislados para probar los diálogos, sin bloquear permanentemente períodos en Neon. **No marcar F2.3 [x] hasta pruebas y aceptación frontend.**

---

## Frontend compilado — bloqueo de preflight local Windows (22/10/2026)

**Resultados manuales aportados:** `php -l ListFiscalPeriodsController.php` PASS; `artisan route:list` confirma rutas GET y POST de períodos; `pnpm typecheck` PASS; `pnpm test:unit` **16/16 PASS**; `pnpm build` producción **PASS** (Vite 6.4.4). La interfaz está construida y compila.

**Pendiente GET HTTP y aceptación visual:** `phase2-neon-analytic-http.php --period-only=contabilidad-dev` devolvió `[BLOQUEO] Solo Neon PG18 DEV app_backend + SSL, credenciales coincidentes.` **antes de conectar a Neon y antes de ejecutar SQL**. Esto no representa un fallo del endpoint GET, RLS, cierre ni auditoría; el bloque de validación compara `APP_ENV`, `DB_CONNECTION`, host/puerto/base/roles APP y ADMIN, URLs, SSL, pooling, APP_KEY y coincidencia `DB_PASSWORD` / `DB_APP_BACKEND_ROLE_PASSWORD`. La prueba HTTP antigua de cierre y la concurrencia Docker ya quedaron aprobadas.

**Siguiente ejecución local (no compartir secretos):**

```powershell
cd C:\Proyectos\contabilidad\backend
php scripts/phase2-neon-connection-diagnostic.php --check
# Si falla por variables heredadas, revisar SOLO sus nombres:
Get-ChildItem Env: |
  Where-Object { $_.Name -match '^(APP_ENV|APP_KEY|DB_|NEON_|DATABASE_URL)' } |
  Select-Object -ExpandProperty Name
```

Si esas variables están presentes en el proceso, pueden prevalecer sobre `backend/.env`. Limpiar solo los overrides de la **sesión actual** luego de verificar que todas las credenciales y configuraciones estén guardadas en `.env`; no revelar valores, regenerar claves ni trasladar credenciales a Git. Volver a ejecutar diagnóstico `--check`; cuando termine 100% PASS, repetir **solamente** `--period-only=contabilidad-dev`. La prueba termina con ROLLBACK.

**F2.3 permanece `[ ]`** hasta comprobar GET real y evaluar UI de navegación, motivo, roles y cambio de estado en el navegador. Plan global **6/32 (19%)**.

---

## Puertas todavía abiertas

1. El flujo HTTP nuevo **superó** la ejecución manual del usuario; F2.3 seguirá `[ ]` hasta verificar las carreras de concurrencia y completar los criterios de aceptación restantes.
2. **Aprobado:** las dos carreras CLOSE-first y POST-first superaron la prueba Docker con 55P03, correlativos únicos y auditoría.
3. **Frontend typecheck/tests/build PASS:** falta únicamente desbloquear preflight de Windows, verificar GET RLS real en Neon y aceptar visualmente los diálogos y controles. Las conciliaciones previas y la reapertura extraordinaria de LOCKED quedan como diseño/procedimiento separado.
4. Corregir `CACHE_STORE=file`, `SESSION_DRIVER=file` y `QUEUE_CONNECTION=sync` en el `.env` privado local si el diagnóstico de Laravel sigue marcando `FAIL`. El *harness* HTTP usa drivers `array/sync` temporales solo para pruebas.

**No habilitar matrices analíticas ACTIVE de forma persistente ni ejecutar `migrate:fresh`.**

[Plan maestro](../README-PLAN-MAESTRO.md) · [Evidencia manual](VALIDACION-MANUAL-Y-EVIDENCIAS.md).
