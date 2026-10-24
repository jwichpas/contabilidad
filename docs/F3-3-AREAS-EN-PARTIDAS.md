# F3.3 · Áreas organizativas y de flujo en partidas

24/10/2026. Implementación para validación manual en Neon DEV. **Avance oficial: 8/32 (25%); F3.3 abierta.**

## Alcance

El Libro Diario permite seleccionar en cada partida `company_area_id` (opcional) y `cash_flow_area_id` (obligatorio solo si la cuenta tiene `requires_cash_flow_area=true`). Reutiliza los catálogos de áreas existentes y el selector global de empresa; no crea ni recarga catálogos.

La API comprueba que cada área seleccionada pertenezca a la empresa y esté activa. Si la cuenta fija OPERATING, INVESTING o FINANCING, el área de flujo debe coincidir. CASH_EQUIVALENT permite seleccionar cualquiera de las actividades del catálogo; NONE deja la selección opcional, pero valida cualquier área recibida. Son reglas de configuración del software, no una certificación normativa del reporte NIC 7.

No se infiere ingreso/egreso a partir de Debe/Haber: eso depende de la cuenta y de la operación. `flow_direction` se conserva como metadato; este incremento no genera el estado de flujos ni elimina duplicidades entre contrapartidas o transferencias internas.

Las validaciones se realizan antes de abrir períodos o guardar tipos de cambio, dentro del contexto tenant, y también en llamadas directas al servicio POST. Se respeta el orden de bloqueo analítico antes de cuenta/maestros. Cuenta y áreas se leen con bloqueo compartido; queda pendiente la prueba real de concurrencia entre conexiones.

Las dos referencias se guardan en las partidas manuales y participan en el digest de idempotencia. El replay las conserva y cambiar cualquiera con la misma clave produce conflicto. Las partidas analíticas generadas conservan su vínculo al origen; no se copian automáticamente estas áreas.

El historial muestra ambas áreas sin devolver el responsable ni otros campos del maestro organizativo. El extorno copia los IDs originales aunque las áreas estén ahora inactivas o hayan cambiado de clasificación. Los nombres mostrados provienen del maestro actual, no de una instantánea de denominaciones históricas.

## Migración necesaria

`backend/database/migrations/2026_10_24_100000_add_journal_line_areas.php` agrega dos columnas nullable, índices y FKs compuestas `(company_id, area_id)` contra los maestros correspondientes. Reutiliza la protección RLS y la inmutabilidad de las partidas; los asientos existentes permanecen NULL, sin backfill. `down()` rechaza eliminar dimensiones sin un plan auditado.

**No ejecutada por el agente.** En PowerShell el usuario ejecutó el 24/10/2026 `php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_100000_add_journal_line_areas.php --force`, que respondió **`INFO Nothing to migrate`**. Esta salida, por sí sola, no certifica el historial de migraciones ni su rama; sin embargo las pruebas Feature ejecutadas a continuación pasaron con las columnas y relaciones requeridas. Si la migración faltara en otro entorno, POST y extorno HTTP devolverían 503. No usar `migrate:fresh`, no repetir migraciones ya instaladas ni trabajar fuera de `contabilidad-dev`.

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php scripts/phase2-neon-connection-diagnostic.php --check
# Continuar solo si el diagnóstico confirma contabilidad-dev.
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_100000_add_journal_line_areas.php --force
php artisan test tests/Unit/JournalAreaRequirementTest.php
php artisan test tests/Feature/JournalAreaDimensionTest.php --debug
php scripts/verify-plan-master-checklist.php --check
```

## Evidencia y aceptación

- Frontend: **33/33 PASS**. Tres pruebas nuevas ejecutan obligatoriedad, estado, coincidencia de actividades y selección en cuentas de efectivo/equivalentes.
- `pnpm build`: PASS, incluye `vue-tsc -b` y Vite 6.4.4.
- PHP: análisis sintáctico con php-parser 3.7.0 PASS. No equivale a ejecutar Laravel.
- **Laravel validado manualmente el 24/10/2026 (salida PowerShell del usuario):** `JournalAreaRequirementTest.php`: **2/2 PASS, 5 aserciones, 0,28 s**. `JournalAreaDimensionTest.php`: **5/5 PASS, 44 aserciones, 120,87 s**, PHPUnit 12.5.33 / PHP 8.5.12, **Exit Code 0**. Comprende catálogo con campos mínimos, guardar/consultar/replay e idempotencia, rechazos antes de período/TC, extorno con áreas inactivas y dimensiones opcionales. Feature usa `DatabaseTransactions`; el resultado no implica COMMIT de nuevos asientos persistentes.
- **Revisión incremental 24/10/2026:** las rutas `GET /api/v1/company/areas` y `GET /api/v1/company/cash-flow-areas` seleccionan solo los campos requeridos por el Libro Diario; no se exponen `manager_name`, descripción ni metadatos internos en el listado de áreas. Se añadió un caso Feature de regresión a `JournalAreaDimensionTest.php` (**ejecutado y PASS** dentro del conjunto 5/5; no modifica el conteo oficial).
- **Corrección UI y validación reportada (24/10/2026):** `JournalEntryView.vue` muestra el aviso de éxito después de limpiar el formulario. Si falla la recarga del historial tras un POST confirmado, indica que el asiento ya está registrado y desaconseja volverlo a asentar. Tras publicar el cambio, el usuario ejecutó en Windows `pnpm test:unit` (**33/33 PASS, 0 fallidas**) y `pnpm build` (**vue-tsc -b + Vite 6.4.4 PASS, 1.630 módulos, 2,97 s**). **Estos comandos acreditan pruebas unitarias existentes y compilación, pero no una prueba automatizada específica del mensaje de éxito ni aceptación visual del flujo real.**
- **Checklist documental (24/10/2026):** `php scripts/verify-plan-master-checklist.php --check` **PASS, 8/32 aprobadas y 24/32 pendientes**; tabla de evidencias, fase F3 y subhitos F3.4/F3.5 reconciliados. Es coherencia documental, no prueba funcional. Sigue sin adjuntarse el resultado del diagnóstico de conexión.
- Pendientes adicionales: aceptación visual, comprobación directa de FKs y concurrencia. Área organizativa obligatoria por cuenta todavía no existe en el modelo de cuenta: este incremento la trata como opcional.

Prueba visual: en una empresa DEMO, seleccionar áreas existentes y registrar un asiento ficticio balanceado. Recargar el detalle y comprobar ambas referencias. Para una cuenta con flujo obligatorio, omitirlo debe impedir guardar; cambiar de cuenta limpia la selección de flujo. Cambiar la empresa desmonta el borrador y carga los catálogos de la nueva empresa. Probar extorno en período abierto sobre el asiento ficticio.

Quedan pendientes cuentas bancarias, medios de pago, CRUD de áreas, escenarios multisectoriales y aceptación funcional completa de F3.3. Los resultados anteriores de anexos (4/4, 29 aserciones) no validan automáticamente este incremento.
