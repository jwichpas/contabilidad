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

## Puertas todavía abiertas

1. El flujo HTTP nuevo debe superar la ejecución manual del usuario; no marcar `F2.3 [x]` solo porque el código exista.
2. Validar escenarios concurrentes de cierre versus POST/extorno, con dos conexiones y periodo bloqueado, conservando el comportamiento `NOWAIT` si se usa.
3. Completar exposición UX/UI del estado del período y revisión de procedimientos de cierre (conciliaciones previas y permisos de reapertura extraordinaria), si forman parte de la aprobación operativa.
4. Corregir `CACHE_STORE=file`, `SESSION_DRIVER=file` y `QUEUE_CONNECTION=sync` en el `.env` privado local si el diagnóstico de Laravel sigue marcando `FAIL`. El *harness* HTTP usa drivers `array/sync` temporales solo para pruebas.

**No habilitar matrices analíticas ACTIVE de forma persistente ni ejecutar `migrate:fresh`.**

[Plan maestro](../README-PLAN-MAESTRO.md) · [Evidencia manual](VALIDACION-MANUAL-Y-EVIDENCIAS.md).
