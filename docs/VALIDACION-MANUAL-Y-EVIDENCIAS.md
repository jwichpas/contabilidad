# Verificación manual obligatoria — Nexus Contable

**Vigente desde:** 2026-10-22 · **Responsable de ejecución:** desarrollador autorizado en su equipo local · **Repositorio:** `jwichpas/contabilidad` · **Rama única:** `main`

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

## Prueba nueva pendiente — F2.2 idempotencia (22/10/2026)

El backend y frontend ya incluyen deduplicación optativa de POST mediante `Idempotency-Key` y una migración aditiva para recibos protegidos por RLS. **El nuevo flujo todavía no tiene evidencia de ejecución, por lo que F2.2 sigue `[ ]`.**

El mismo wrapper Windows `phase2-active-rls-docker.ps1` instala ahora **20 migraciones** solo en PostgreSQL 18 Docker descartable y prueba HTTP **201 creación / 200 reintento / 409 conflicto**, separación tenant y recibos inmutables, además del antiguo motor 94/95/79. Tras ejecutarlo, correr manualmente `pnpm typecheck`, `pnpm test:unit` y `pnpm build` en `frontend`. [Diseño y puerta de calidad](F2-2-IDEMPOTENCIA-POST.md).

**No ejecutar migraciones nuevas sobre Neon hasta superar los ensayos del contenedor.** En este punto Neon continúa con **21 migraciones**, 3 matrices DRAFT y ningún asiento.

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
