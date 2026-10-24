# F3.3 — Maestro configurable de subdiarios y mejoras visuales del Libro Diario

**Fecha:** 24/10/2026 · **Estado:** migración `2026_10_24_140000_create_company_subdiaries` **DONE (~1 s)**, Unit **1/1 (8 aserciones)**, Feature **17/17 (140 aserciones)**, frontend **41/41** y compilación **PASS (1.635 módulos, 2,52 s)** según evidencia manual del usuario. **Pendientes:** salida legible del diagnóstico SQL posterior `Ran` + RLS/FK y aceptación visual actualizada. **Plan Maestro: 8/32 (25%), F3.3 abierta**.

## Decisión funcional

El selector de subdiario del Libro Diario **ya no debe depender de un array hardcodeado**. Se añadió un maestro real, editable **por empresa**, separado del `entry_type` del voucher. El código histórico seleccionado sigue almacenado en `journal_entries.subdiary_code`, sin cambiar la numeración global por período contable.

### Base de datos (migración ejecutada manualmente en DEV)

`backend/database/migrations/2026_10_24_140000_create_company_subdiaries.php`: crea `company_subdiaries` con `(id, company_id, code, name, is_active, timestamps)`, único `(company_id, code)`, RLS `ENABLE + FORCE` y permisos sin DELETE para `app_backend`. Inserta los ocho códigos previamente soportados **para cada empresa existente**, sin insertar ni modificar asientos, períodos ni tasas de cambio. Sustituye el CHECK cerrado de ocho códigos por una **FK compuesta de `(company_id, subdiary_code)` en `journal_entries` hacia `(company_id, code)`**: protege tanto la identidad de la empresa como los códigos históricos. Cualquier rollback/limpieza de esta migración es deliberadamente bloqueado; requiere plan de datos aprobado. Se preserva la migración anterior `2026_10_24_130000_add_journal_entry_subdiary.php` que ya está instalada en Neon DEV.

**Empresas nuevas:** el asistente de alta instala los ocho valores dentro de la misma transacción tenant-safe; bloquea altas si falta la migración del maestro, evitando una empresa sin subdiarios.

### API y autorización

- `GET /api/v1/company/subdiaries?active_only=1`: devuelve catálogo de la empresa; sin filtro, devuelve activos e inactivos para administración.
- `POST /api/v1/company/subdiaries`: alta con código `A-Z0-9_` (2 a 24 caracteres) y nombre de 2–120. Requiere rol `ADMIN` o `CHIEF_ACCOUNTANT`.
- `PUT /api/v1/company/subdiaries/{id}`: solo edita **nombre**; prohíbe cambiar código, empresa, estado e ID.
- `PATCH /api/v1/company/subdiaries/{id}/status`: desactiva/reactiva sin borrar; restringido a `ADMIN` o `CHIEF_ACCOUNTANT`.
- POST de asientos: valida subdiario activo bajo RLS con bloqueo compartido antes de crear períodos o tasas; los extornos copian el código del histórico aunque el maestro esté inactivo. Códigos arbitrarios ya no están restringidos al array PHP. La vista de administración se encuentra en menú lateral **Maestro de subdiarios**.

**Precaución:** desactivar no elimina referencias; cambiar un nombre no modifica el registro histórico, que retiene su código. En el Libro Diario el nombre visible corresponde al catálogo de su empresa; al desplegar inactivos históricos, se debe conservar la legibilidad del código.

## Problemas visuales detectados en captura y solución

En una imagen de 1600 píxeles, el buscador de cuentas se desplegaba dentro de `overflow-x-auto` y se recortaba; el selector de terceros insertaba su lista dentro de la celda y elevaba/solapaba otras filas. Se corrigió:

1. `JournalAccountCombobox.vue`: lista de resultados Teleport hacia `body` con posición fija calculada desde el input (`getBoundingClientRect`), ajuste de ventana/scroll, límite de 40 y búsqueda por código/nombre. Ya no queda recortada por la tabla.
2. `JournalEntityPicker.vue`: búsqueda en cuadro modal Teleport fuera de la tabla, sin expandir filas. Mantiene búsqueda paginada y validación de roles.
3. `JournalEntryView.vue`: tabla de ancho mínimo estable de 1800px, alineación superior y scroll horizontal explícito en pantallas reducidas; Debe/Haber y áreas mantienen ancho y no se enciman. **La aceptación visual en navegador sigue pendiente**; no se declara que el diseño esté aprobado hasta recibir captura posterior.

## Verificación manual de despliegue (exclusivamente DEV)

**Migración ya aplicada según salida del usuario: NO repetirla.** Para una auditoría independiente del estado resultante, solo ejecutar el diagnóstico de lectura `php scripts/check-company-subdiaries-schema.php --check` y comprobar `Ran`, tabla existente, FORCE RLS y FK intraempresa. Nunca ejecutar `migrate`, `migrate:fresh` ni rollback generales para volver a instalarla.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php scripts/phase2-neon-connection-diagnostic.php --check
php scripts/check-company-subdiaries-schema.php --check
php -l database/migrations/2026_10_24_140000_create_company_subdiaries.php
php artisan test tests/Unit/CompanySubdiaryMigrationSqlTest.php
```

Solo tras confirmación de precondiciones y respaldo de DEV:

```powershell
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_140000_create_company_subdiaries.php --force
php scripts/check-company-subdiaries-schema.php --check
php artisan test tests/Feature/CompanySubdiaryMasterTest.php --debug
php artisan test tests/Feature/JournalAreaDimensionTest.php --debug
php artisan test tests/Feature/JournalEntityDimensionTest.php --debug
cd ..\frontend
pnpm test:unit
pnpm build
```

**Evidencia ejecutada el 24/10/2026:** `CompanySubdiaryMigrationSqlTest` **1/1 PASS (8 aserciones, 0,28 s)**; migración específica **DONE (~1 s)**; `CompanySubdiaryMasterTest` **3/3 PASS (35 aserciones, 59,82 s)**; `JournalAreaDimensionTest` **10/10 PASS (75 aserciones, 244,78 s)**; `JournalEntityDimensionTest` **4/4 PASS (30 aserciones, 113,66 s)**; frontend **41/41 PASS (0 fallos)**; `vue-tsc -b && vite build` **PASS (1.635 módulos, 2,52 s)**. El texto proporcionado después del comando `check-company-subdiaries-schema.php --check` muestra directamente la salida de PHPUnit, así que el **diagnóstico posterior no está acreditado** por una salida diferenciada. No hay evidencia de aceptación visual del diseño nuevo, rendimiento real ni prueba de alta y edición desde navegador. F3.3 permanece [ ], 8/32.

**Criterios de aceptación:** maestro permite crear, editar nombre, desactivar, reactivar en empresa DEMO sin copiar a otras empresas; POST rechaza subdiario inactivo y de tenant ajeno; extorno conserva subdiario original; pantallas sin clipping; compilación Vue y 41/41 pruebas frontend previstas; Feature `CompanySubdiaryMasterTest` (3 tests nuevos) y `JournalAreaDimensionTest` (10 tests previstos) sin FAIL. No declarar PASS sin logs manuales.
