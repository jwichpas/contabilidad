# F3.3 · Anexos en partidas contables

24/10/2026. Implementación publicada para validación manual en Neon DEV; **F3.3 sigue abierta y el avance oficial es 8/32 (25%)**.

## Comportamiento

- Libro Diario: cada partida puede buscar un anexo por nombre/documento, con paginación. Se filtra según CUSTOMER, SUPPLIER, EMPLOYEE, SHAREHOLDER, FINANCIAL_INSTITUTION o TAX_AUTHORITY. ANY_THIRD_PARTY admite cualquier anexo activo. NONE permite omitirlo, salvo `requires_auxiliary=true`.
- Al cambiar la cuenta se limpia la selección anterior. La API valida de nuevo empresa, estado y clasificación; un anexo puede tener varios roles. Los resultados de búsquedas anteriores se descartan al cambiar cuenta o desmontar el selector.
- `entity_id` pasa a formar parte del payload validado y del digest de idempotencia. Cambiarlo con la misma clave devuelve conflicto, sin reinterpretar un asiento ya creado.
- Se valida el anexo antes de abrir períodos o guardar tipos de cambio. El servicio POST vuelve a validar para proteger sus llamadas directas. Bloqueos compartidos mantienen cuenta/anexo estables dentro de la transacción.
- Las partidas originales guardan la referencia. Las partidas analíticas generadas mantienen su trazabilidad al origen mediante `journal_analytic_allocations`; no se les atribuye automáticamente un anexo.
- El historial y replay devuelven solo ID, empresa, tipo/número de documento y razón social del anexo. No se exponen campos bancarios, contactos ni información de detracciones.
- El extorno copia `entity_id` del original, incluso si el anexo fue desactivado o cambió su clasificación. No aplica las reglas actuales a la referencia histórica ni modifica el original.
- Se retiran las cachés del catálogo operativo del cliente y servidor para leer requisitos vigentes al volver al Libro Diario. Puede aumentar el tiempo de carga; el servidor sigue siendo la autoridad si una cuenta cambia con el formulario abierto.

## Migración aditiva necesaria

`backend/database/migrations/2026_10_24_080000_add_journal_line_entities.php` añade `entity_id` nullable, índice por empresa y FK compuesta `(company_id, entity_id) -> entities(company_id, id)`. Reutiliza RLS, permisos e inmutabilidad de las partidas. Los asientos anteriores permanecen NULL; no hay backfill, seeds ni nuevas empresas persistidas.

Antes de aplicar la migración, el POST y el extorno HTTP responden 503 explícito; las lecturas siguen disponibles. No ejecutar escrituras del Libro Diario entre la actualización del código y la migración. La migración no ha sido ejecutada por el agente. No repetir la migración fiscal ni usar `migrate:fresh`.

Desde el repositorio actualizado, en el entorno local configurado para **contabilidad-dev**:

```bash
git pull --ff-only origin main
cd backend
php scripts/phase2-neon-connection-diagnostic.php --check
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_080000_add_journal_line_entities.php --force
php artisan test tests/Unit/JournalEntityRequirementTest.php
php artisan test tests/Feature/JournalEntityDimensionTest.php --debug
php scripts/verify-plan-master-checklist.php --check
```

Solo continuar con la migración si el diagnóstico confirma la rama DEV. No aplicar en production. `down()` rechaza borrar la dimensión sin un plan auditado.

## Evidencia y límites

- Frontend: **30/30 PASS**, incluye tres pruebas ejecutables de obligatoriedad, seis clasificaciones, entidad inactiva y múltiples roles. No equivalen a una prueba de navegador.
- `pnpm build`: PASS (`vue-tsc -b` + Vite 6.4.4).
- PHP: análisis sintáctico con php-parser 3.7.0 PASS; no hay runtime PHP en el entorno del agente. **No se ejecutaron Laravel, la migración ni el nuevo Feature test.**
- Dos pruebas Unit PHP preparadas para reglas puras. Cuatro Feature preparadas: guardar/consultar/replay sin campos bancarios; rechazos por ausencia/tipo/estado/empresa sin asiento/período/TC; extorno con anexo inactivo; referencia opcional y catálogo actualizado. Usan DatabaseTransactions y una sola cadena de seis cuentas por caso para limitar preparación remota. No marcar PASS hasta recibir salida real.
- Concurrencia entre conexiones, FK directa desde SQL y aceptación visual de guardado siguen pendientes. El bloqueo compartido por sí solo no certifica concurrencia.
- Este incremento cubre **anexo**, no cuenta bancaria, área organizativa, medio de pago ni área de flujo NIC 7. Esas dimensiones y los escenarios multisectoriales continúan pendientes.
- La relación muestra el nombre/documento actual del maestro; no constituye una instantánea histórica de su denominación.

## Aceptación visual pendiente

En una empresa DEMO con usuario autorizado, crear un cliente ficticio en Anexos y elegir una cuenta operativa que exija CUSTOMER. Seleccionarlo desde la partida; comprobar rechazo local si se omite y rechazo HTTP si se envía uno ajeno/inactivo. Completar la contrapartida y registrar un asiento balanceado. Verificar anexo en el detalle tras recargar. Cambiar empresa: el borrador y las búsquedas anteriores no deben reaparecer. La prueba de extorno debe hacerse sobre ese asiento ficticio y en período abierto.
