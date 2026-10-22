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

## Certificación posterior a corrección local — 22/10/2026

**Evidencia manual recibida:** la sesión Windows quedó sin overrides heredados y `phase2-neon-connection-diagnostic.php --check` finalizó **sin FAIL**; `php scripts/phase2-neon-analytic-http.php --period-only=contabilidad-dev` volvió a finalizar con **PASS** en CLOSE, REOPEN, LOCK, 403/409/422 y 4 auditorías, seguido de `[ROLLBACK]` y `[PASS] HTTP POST-ROLLBACK`. El `--verify` de F2.2 confirmó 22 migraciones, FORCE RLS, 3 DRAFT y 0 recibos persistidos.

**Qué valida el código del modo corto actual:** antes del cierre invoca **GET `/api/v1/accounting/fiscal-periods?year=2026` para la empresa A** y exige HTTP 200, un período `OPEN` y al menos dos asientos temporales contados. El PASS posterior del modo corto prueba que esas aserciones también se superaron. **No contiene todavía una aserción separada de GET para la empresa B**; no asumir que GET entre tenants ya fue validado solo por el ensayo de POST/GET del libro diario.

**Verificación independiente de solo lectura en Neon DEV:** 22 migraciones; 3 matrices DRAFT, 0 ACTIVE; 0 asientos, 0 partidas, 0 períodos, 0 auditorías y 0 recibos de idempotencia. No queda residuo del ensayo.

**Próximo control antes de F2.3 [x]:** navegación visual `#periodos` para usuario administrador y rol auditor; comprobar estados, cambio de empresa, 12 meses `Sin registrar` y diálogos de confirmación con motivo. La vista está compilada (`pnpm typecheck`, 16/16 pruebas unitarias, `pnpm build` PASS), pero no se ha acreditado una revisión visual en navegador. También falta caso de lectura GET con tenant B. **No repetir por rutina Docker ni las pruebas largas ya aprobadas.**

---

## Captura de interfaz validada y apertura explícita añadida (22/10/2026)

El usuario mostró la vista local `http://localhost:5180/#periodos` funcionando: 2026, 12 meses «Sin registrar», totales y selector anual. Es coherente con el baseline Neon sin períodos. Se detectó una brecha real de operatividad: no existía ningún botón para crear un mes y los controles `close/reopen/lock` requerían un registro ya existente.

Se añadió **Abrir** por mes exclusivamente para roles ADMIN / CHIEF_ACCOUNTANT. La nueva ruta `POST /api/v1/accounting/fiscal-periods` recibe `year`, `month` y `reason` (10–500 caracteres), crea el período `OPEN` con fecha inicial y final, y registra `FISCAL_PERIOD_OPENED` atómicamente. Protege el tenant con middleware y FORCE RLS, rechaza períodos existentes con 409 y las carreras concurrentes mediante el UNIQUE `(company_id,year,month)`. No sobrescribe `CLOSED/LOCKED`; la reapertura sigue siendo otra operación auditada.

En Vue se habilitó la acción de fila `Abrir` para meses «Sin registrar», con diálogo de confirmación y motivo. Tras crear, actualiza el listado para habilitar `Cerrar`, `Reabrir` y `Bloquear` según el estado. La consulta GET continúa sin crear nada automáticamente.

**Aún por aprobar:** esta funcionalidad de apertura es nueva y no ha sido probada manualmente. Se amplió el ensayo reversible `--period-only=contabilidad-dev`: exige HTTP 422 por motivo corto, 201 al abrir noviembre, 409 al repetir, 403 para auditor, GET correcto para empresa A y aislamiento en empresa B, y una auditoría `FISCAL_PERIOD_OPENED`. El mismo ensayo termina en ROLLBACK, no debe confirmar períodos.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l app/Services/Accounting/FiscalPeriodStatusService.php
php -l app/Http/Controllers/Api/V1/Accounting/OpenFiscalPeriodController.php
php -l scripts/phase2-neon-analytic-http.php
php artisan route:list --path=api/v1/accounting/fiscal-periods
php scripts/phase2-neon-analytic-http.php --period-only=contabilidad-dev
cd ..\frontend
pnpm typecheck
pnpm build
```

**Estado remoto leído tras la captura:** 22 migraciones, 3 matrices DRAFT, 0 ACTIVE, 0 períodos, 0 asientos. Existen 3 eventos de auditoría de autenticación/selector de empresa (1 AUTH_LOGIN y 2 TENANT_SWITCH); **no son residuos de F2.3**. No se hizo INSERT de períodos en Neon durante la implementación.

F2.3 permanece abierto hasta aprobar el nuevo endpoint y verificar el diálogo en navegador.

---
## Cierre formal F2.3 — evidencia E2E + RLS (22/10/2026)

**Actividad F2.3 APROBADA; plan maestro 7/32 (22%).**

**Captura del usuario:** `http://localhost:5180/#periodos` muestra enero de 2026 en estado **Abierto**, contador **1 abierto**, 0 asientos y mensaje `Período abierto y auditado. Ya puede registrar asientos en este mes.` El botón de enero cambia de Abrir a Cerrar. Esto acredita la integración real UI → POST Laravel → GET.

**Verificación SQL independiente de Neon DEV (solo SELECT):** empresa **DEMO A - Comercial Andina S.A.C.**, enero 2026, `fiscal_periods.status=OPEN`, `start_date=2026-01-01`, `end_date=2026-01-31`, `closed_at=NULL`, **0 asientos**, **1 evento FISCAL_PERIOD_OPENED asociado al período**.

**Prueba de RLS con dos contextos y rol real `app_backend`** (solo consultas en una transacción, `set_config(...,true)`): contexto DEMO B consultó `fiscal_periods` → **0 filas visibles**, inclusive cero de DEMO A; contexto DEMO A → **1 fila visible**, la suya. No se emitió escritura remota para esta verificación.

**Otros componentes ya aceptados:** CLOSE/REOPEN/LOCK y rechazo de acciones indebidas HTTP 403/409/422, auditoría atómica; POST/extorno de asientos balanceados, concurrencia CLOSE-first y POST-first con dos sesiones PostgreSQL 18 Docker, ROLLBACK; frontend operativo con confirmación y motivo, compilación Vue 16/16 pruebas y build Vite anteriores. La apertura real completó la validación que faltaba.

**Restricciones al seguir desarrollando:** existe **un período OPEN persistente** en Neon; los ensayos iniciales `phase2-neon-analytic-http.php` y algunos preflights más antiguos aún parten de `0 períodos / 0 auditorías contables`. Antes de usarlos nuevamente, adaptar sus snapshots a un baseline DEV con registros legítimos o mover fixtures íntegramente a Docker efímero. **No borrar el período ni su auditoría para conseguir que pase un preflight.** Este asunto es deuda técnica del *harness*, no una falla del cierre de períodos.

**Siguiente objetivo:** F3.1 asistente de alta de empresa de prueba y sus parámetros fiscales, utilizando `main`, sin nuevas ramas y sin crear datos reales.

---

## Controles posteriores / deuda técnica

1. Las pruebas funcionales del alcance F2.3 están aprobadas (Neon HTTP, Docker concurrencia, interfaz real y aislamiento multiempresa).
2. Actualizar los *harnesses* de ensayo que exigían base de desarrollo vacía antes de futuras pruebas. No restablecer datos de usuario legítimos para ejecutar tests.
3. Las conciliaciones y el cierre financiero de fin de año pertenecen a tareas posteriores del ERP; F2.3 contempla **estado mensual, doble entrada, autorización, auditoría y bloqueo**, no la aprobación normativa de estados financieros.
4. Mantener `LOCKED` sin reapertura ordinaria; cualquier excepción requerirá flujo nuevo de administración y auditoría.

**No ejecutar `migrate:fresh`, ni habilitar matrices analíticas ACTIVE automáticamente.**

[Plan maestro](../README-PLAN-MAESTRO.md) · [Evidencia manual](VALIDACION-MANUAL-Y-EVIDENCIAS.md).
