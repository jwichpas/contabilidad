# Verificación manual obligatoria — Nexus Contable

**Vigente desde:** 2026-10-22 · **Responsable de ejecución:** desarrollador autorizado en su equipo local · **Repositorio:** `jwichpas/contabilidad` · **Rama única:** `main`

## Resultado funcional real F2.3 — 22/10/2026

**Aprobado: fase F2.3 del motor contable (7/32 actividades; 22%).** El usuario abrió enero de 2026 desde el panel `http://localhost:5180/#periodos` y recibió el mensaje de éxito; la tabla cambió a «Abierto», 1 período abierto y 0 asientos. La lectura SQL independiente a Neon DEV confirmó para DEMO A `2026-01-01–2026-01-31`, `OPEN`, `closed_at NULL`, **1 evento FISCAL_PERIOD_OPENED**, **0 asientos**.

**Aislamiento independiente de la interfaz:** SQL SELECT bajo `app_backend` y RLS con contexto local transaccional de DEMO B mostró **0 períodos**; con DEMO A, **1 período**. Sin cambios de datos derivados de esta comprobación.

**Ensayos previos completados:** F2.2 201/200/409 y extorno; F2.3 CLOSE/REOPEN/LOCK 403/409/422, auditoría de cambios y ROLLBACK; concurrencia entre dos sesiones PostgreSQL 18 Docker, 55P03/NOWAIT; Vue typecheck, 16 pruebas y build aprobados previamente. La apertura E2E completó el flujo desde navegador.

**Persistencia esperada en desarrollo:** ya existe un período válido de enero. Los scripts reversibles antiguos que exigían 0 períodos/auditorías se adaptaron en `main` para permitir exclusivamente la apertura auditada de enero como baseline DEMO y comprobar que sus pruebas con ROLLBACK no cambian el estado previo. **Estos cambios del harness requieren nueva validación sintáctica/manual antes de reutilizarse**; no borrar los registros de la aplicación para hacer pasar pruebas.

---

## Decisión operativa

**GitHub Actions no se utilizará.** Los workflows `.github/workflows/phase0-static-baseline.yml` y `.github/workflows/phase1-security-static.yml` se eliminaron de `main`. GitHub continúa siendo repositorio de código; **las compilaciones, pruebas, migraciones y verificaciones serán ejecutadas expresamente en Windows/terminal local**, y las evidencias se consignarán en este documento y en el checklist del [plan maestro](../README-PLAN-MAESTRO.md#10-checklist-de-seguimiento). Los runs antiguos de Actions pueden seguir visibles como historial, pero **no representan la política de validación vigente**.

## Reglas de seguridad

1. Primero `git pull --ff-only origin main`. No crear ramas ni ejecutar procesos en producción para validar datos DEMO.
2. En Neon `contabilidad-dev`, usar únicamente scripts `--verify`, `--plan` o `--probe=contabilidad-dev` cuyo contrato garantice ROLLBACK. **No ejecutar `migrate:fresh`, `db:wipe`, `db:seed` general ni activar matrices permanentes sin instrucción explícita.**
3. Las pruebas que requieren una matriz `ACTIVE` y `app_backend` simultáneamente usan Docker PostgreSQL 18 efímero `phase2-active-rls-docker.ps1`, con puerto aleatorio en loopback y destrucción final del contenedor.
4. No copiar `.env`, contraseñas, dumps, tokens ni datos privados a GitHub o al chat. Compartir únicamente salidas PASS/FAIL sin secretos.
5. Cada actividad del plan maestro solo pasa a `[x]` cuando su **alcance completo** tiene evidencia; las pruebas parciales o una subfase F2.3h no cierran automáticamente una actividad que también requiere cierre contable.

## Rutina manual — Windows/PowerShell

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main

cd backend
php -v
php -l app/Services/Accounting/AnalyticRoutingService.php
php -l app/Services/Accounting/AnalyticMatrixPromotionService.php
php -l scripts/phase2-active-rls-docker.php

# Solo lectura: reglas de destino y catálogo de empresas reales de desarrollo
php scripts/phase2-neon-analytic-promotion-preflight.php --verify
php scripts/phase2-neon-analytic-allocations.php --verify
php scripts/phase2-neon-tenant-integrity.php --verify

# La siguiente prueba usa exclusivamente PostgreSQL 18 temporal en Docker.
docker info --format '{{.OSType}}'
.\scripts\phase2-active-rls-docker.ps1

cd ..\frontend
pnpm install --frozen-lockfile
pnpm typecheck
pnpm test:unit
pnpm build
```

**Alcance:** la rutina de frontend puede realizarse separadamente en el cambio de UI; instalar dependencias no debe requerir conexión a Neon. El ensayo Docker se ejecuta cuando cambie el motor, RLS, esquema o control de acceso, no en cada cambio de documentación.

## Ensayos de integración adicionales en Neon DEV (solo cuando cambie código relevante)

```powershell
cd C:\Proyectos\contabilidad\backend

# La primera prueba verifica HTTP del motor DRAFT como app_backend.
php scripts/phase2-neon-analytic-http.php --probe=contabilidad-dev

# La segunda verifica ACTIVE solo en transacción owner, siempre ROLLBACK.
php scripts/phase2-neon-analytic-http-active.php --probe=contabilidad-dev

# La tercera verifica DRAFT -> ACTIVE -> RETIRED transitorios y auditoría.
php scripts/phase2-neon-analytic-promotion-rollback.php --probe=contabilidad-dev

# Siempre confirmar que las matrices DEV siguen en DRAFT.
php scripts/phase2-neon-analytic-promotion-preflight.php --verify
```

**No ejecutar las pruebas anteriores automáticamente ni en un entorno de producción.** Cada script impone sus guardas sobre host, rol y base. La activación permanente no forma parte de ninguna rutina manual vigente.

## F2.3 — HTTP cerrado/reabierto/bloqueado PASS; concurrencia Docker pendiente (22/10/2026)

**Estado:** el usuario ejecutó el modo corto F2.3 en Neon DEV y confirmó **PASS** de CLOSE, REOPEN, LOCK, 403 RBAC, 409, 422, cuatro auditorías y ROLLBACK con cero datos persistidos. **Todavía pendiente:** prueba concurrente Docker de dos sesiones y la UX de administración de períodos. `FiscalPeriodStatusService` + `ChangeFiscalPeriodStatusController` + endpoint `POST /api/v1/accounting/fiscal-periods/{periodId}/{action}` con acciones `close`, `reopen`, `lock` y control `ADMIN/CHIEF_ACCOUNTANT`. El mismo script HTTP reversible de Neon **comprobó** 200/403/409/422, POST tras reapertura, auditoría de cuatro eventos y ROLLBACK. Documentado en [F2.3 Cierre de períodos](F2-3-CIERRE-PERIODOS.md). `F2.3` **permanece [ ]**; no se ha activado ninguna matriz ni creado migración nueva.

**Prueba aislada de concurrencia preparada:** Windows ` .\\scripts\\phase2-active-rls-docker.ps1 -PeriodConcurrency` o Bash `bash scripts/phase1-journal-concurrency-docker.sh --f23`. Verifica dos sesiones app_backend, bloqueo real `FOR UPDATE NOWAIT` (`55P03`), cierre primero versus asiento primero, relectura de estado, POST rechazado tras cierre y auditoría. Solo Docker efímero; **sin ejecución confirmada aún**.

**Pruebas manuales siguientes:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l app/Services/Accounting/FiscalPeriodStatusService.php
php -l app/Http/Controllers/Api/V1/Accounting/ChangeFiscalPeriodStatusController.php
php -l scripts/phase2-neon-analytic-http.php
php scripts/phase2-neon-analytic-http.php --probe=contabilidad-dev
php scripts/phase2-neon-journal-idempotency.php --verify
```

**Configuración local independiente:** revisar `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync` en `.env` privado; no cambiar contraseñas y **no regenerar APP_KEY**. Repetir `php scripts/phase2-neon-connection-diagnostic.php --check` para validar ejecución normal de Laravel. El *harness* es intencionadamente autoaislado (array/sync), así que tres `FAIL` del diagnóstico no invalidan el test reversible, pero sí el arranque normal.

---

## Certificación F2.2 HTTP Neon y rollback — 22/10/2026

**PASS por salida de PowerShell del usuario:** `phase2-neon-analytic-http.php --probe=contabilidad-dev` verificó HTTP 201/200/409 sin duplicar asiento, recibo ni auditoría; errores 422/403, GET RLS A/B, extorno correcto, prohibición de doble extorno y ROLLBACK. `phase2-neon-journal-idempotency.php --verify` confirmó **22 migraciones**, FORCE RLS de recibos, **3 DRAFT**, cero asientos/recibos persistidos. Con pruebas Docker y frontend previas, **F2.2 ahora [x] — 6/32 actividades (19%)**. Próximo: F2.3 cierre de períodos.

**Tarea operativa local independiente:** el diagnóstico de `CACHE_STORE`, `SESSION_DRIVER` y `QUEUE_CONNECTION` aún muestra 3 FAIL (ausentes en `.env`). Corregir para iniciar el API habitual; la prueba HTTP se aisló expresamente con cache/session array y queue sync, sin modificar `.env`.

---

## Resultado actualizado de Neon DEV — 22/10/2026

- **Migración F2.2 aprobada:** Laravel Artisan instaló la número 22; preflight posterior verificó 3 constraints, 2 UNIQUE, 2 políticas FORCE RLS, SELECT/INSERT únicamente para app_backend.
- **Estado posterior confirmado por SQL independiente:** 3 matrices DRAFT, 0 ACTIVE, 0 asientos, 0 líneas, 0 recibos y 0 auditorías.
- **Histórico — primer intento fallido (resuelto):** primer intento con Sanctum y rol app_backend falló en `Illuminate\\Support\\Manager::createDriver()` y registró ROLLBACK. Se corrigió el *harness* con `cache.default=array`, `session.driver=array`, `queue.default=sync` **solo en memoria**, como hace el ensayo Docker ya aprobado; fase de fallo ahora identificable.
- **No modificar** una matriz DRAFT ni repetir la migración aplicada como reparación de un fallo de configuración de drivers. Siguiente ejecución: `php scripts/phase2-neon-analytic-http.php --probe=contabilidad-dev`.

## Prueba nueva pendiente — F2.2 idempotencia (22/10/2026)

El backend y frontend ya incluyen deduplicación optativa de POST mediante `Idempotency-Key` y una migración aditiva para recibos protegidos por RLS. **La ejecución manual de Docker PG18 con 20 migraciones y frontend ya fue aprobada.** **La migración 22 ya se aplicó mediante Laravel en Neon DEV y está verificada**. F2.2 sigue `[ ]` hasta demostrar reintentos HTTP 201/200/409 con ROLLBACK en Neon.

El mismo wrapper Windows `phase2-active-rls-docker.ps1` instala ahora **20 migraciones** solo en PostgreSQL 18 Docker descartable y prueba HTTP **201 creación / 200 reintento / 409 conflicto**, separación tenant y recibos inmutables, además del antiguo motor 94/95/79. Tras ejecutarlo, correr manualmente `pnpm typecheck`, `pnpm test:unit` y `pnpm build` en `frontend`. [Diseño y puerta de calidad](F2-2-IDEMPOTENCIA-POST.md).

**No ejecutar migraciones nuevas sobre Neon hasta superar los ensayos del contenedor.** En este punto Neon continúa con **21 migraciones**, 3 matrices DRAFT y ningún asiento.

## Aprobación parcial F2.2 — ejecución manual confirmada

**2026-10-22 · PASS usuario:** `phase2-active-rls-docker.ps1` en Windows, PG18 20 migraciones, idempotencia RLS HTTP 201→200/409, 1 recibo sin duplicar auditoría/asiento, denegación de UPDATE, invisibilidad tenant B, concurrencia por segunda conexión, matriz ACTIVE reversible y eliminación del contenedor. Frontend: 16/16 pruebas unitarias, `pnpm typecheck` y `pnpm build` exitosos. **No se ha ejecutado la migración Neon**; [instalador de una sola migración vía Laravel](../backend/scripts/phase2-neon-journal-idempotency.php) y [prueba HTTP reversible](../backend/scripts/phase2-neon-analytic-http.php) preparados. [Runbook detallado](F2-2-IDEMPOTENCIA-POST.md).

## Registro de evidencias verificadas

| Fecha | Referencia | Evidencia recibida/verificada | Estado |
|---|---|---|---|
| 2026-10-22 | F2.3d | HTTP Kernel/Sanctum `app_backend`: POST bimoneda 201, validaciones 422, aislamiento A/B, 403 auditor, extorno, auditoría y ROLLBACK | **PASS** |
| 2026-10-22 | F2.3e | Matriz ACTIVE sin COMMIT: CC-ADM/CC-VTA 94/95/79, ajuste Haber 6xx, extorno, auditoría y ROLLBACK | **PASS** |
| 2026-10-22 | F2.3f | 3 preflights SHA-256: 227 fuentes × 2 centros = 454 rutas por empresa (1.362 totales) | **PASS** |
| 2026-10-22 | F2.3g | Actuación admin, denegación de auditor/SHA incorrecto, transición y retiro auditados, ROLLBACK | **PASS** |
| 2026-10-22 | F2.3h | Docker PG18: 19 migraciones, `current_user=app_backend`, FORCE RLS, POST ACTIVE 94/95/79, tenant A/B, extorno, cuatro asientos/cuatro links, auditoría, ROLLBACK y contenedor eliminado | **PASS** |

Las evidencias son las salidas de terminal comunicadas por el usuario, también descritas en [F2.3 — Motor Analítico](F2-3B-MOTOR-ANALITICO-POST.md). **No** certifican la aprobación de H1, el cierre contable por período ni el asistente de alta de empresas.

## Cómo actualizar el plan maestro

Actualizar en una **misma revisión**: (1) casilla `[x]`, (2) vínculo a la evidencia y limitaciones, (3) numerador/denominador en encabezado y tabla resumen, (4) estado de fase/hito y (5) fecha. Si la prueba pasó parcialmente, usar `[ ]` y describir el progreso sin contarla como aprobada. El total vigente del checklist es **32 actividades**, incluidas F6.4 y F7.0–F7.6; el antiguo texto `0/27` provenía de una versión desactualizada.

Última conciliación del inventario: **22/10/2026**. Las actividades aprobadas se detallan y justifican en el [README-PLAN-MAESTRO.md](../README-PLAN-MAESTRO.md#10-checklist-de-seguimiento).
