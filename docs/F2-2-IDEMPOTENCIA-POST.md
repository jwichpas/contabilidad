# F2.2 — Idempotencia de asientos contables: implementación y validación manual

**Fecha:** 22/10/2026 · **Rama:** `main` · **Estado:** implementado en código, **NO validado ni migrado en Neon**.

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
