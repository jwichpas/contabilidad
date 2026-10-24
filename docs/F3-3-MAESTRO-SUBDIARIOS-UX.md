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

## Nueva evidencia de pantalla y corrección de composición — 24/10/2026

**Capturas de aceptación parcial:** la búsqueda de anexo ya aparecía en Teleport separado, pero el modal quedaba demasiado arriba y comprimido. En el Libro Diario una tabla de ancho mínimo 1.800 px obligaba a desplazar horizontalmente los montos Debe/Haber en una ventana de aproximadamente 1.600 px. DevTools Network mostró `GET /api/v1/accounting/journal-entries/editor-bootstrap?date=2026-10-24` **HTTP 200, ~5,98 s, ~505 kB transferidos**. No se adjuntó el encabezado `Server-Timing`, por lo que no conocemos todavía qué consulta consume el tiempo.

**Incremento UI publicado, pendiente de aceptación visual y nuevo build:** la tabla del voucher utiliza un diseño de **dos filas por partida** (fila principal: cuenta, glosa, Debe/Haber PEN y USD; segunda fila: únicamente dimensiones exigidas por la configuración de la cuenta). Ancho mínimo reducido a **1.040 px**, con scroll horizontal solo si es necesario en pantallas más estrechas. Se mantuvo precisión decimal, botones de añadir/eliminar y totales/diferencias; sin cambio en el POST. La ventana de anexos se centra vertical/horizontalmente, gana ancho útil y lista los resultados en una región desplazable.

**Incremento de carga:** en `GetJournalEditorBootstrapController.php` la consulta `accounts` devuelve un DTO compacto (10 campos requeridos por el editor) en lugar de reserializar datos completos de `ListOperationalAccountsController` (15 campos, incluido UUID). `Server-Timing` sigue distinguiendo `accounts`, `company_areas`, `cash_flow_areas`, `next_number`, `exchange_rates` y `cost_centers`. El endpoint de catálogo completo sigue disponible para otros módulos. No se puede afirmar aún que bajó de 5,98 s ni cuantificar ahorro de bytes sin una captura nueva.

**Pruebas agregadas/ajustadas:** el frontend ahora tiene **43 pruebas previstas** (41 anteriores confirmadas + 2 nuevos casos de diseño y contrato de datos). Se amplió la Feature existente de áreas sin modificar su conteo de **10 tests**, para asegurar que el bootstrap omite uuid, nivel, tipo, naturaleza y modalidad de moneda. **Ninguna de estas pruebas posteriores a la captura está validada en Windows todavía.** No requiere nueva migración.

**Para validar:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php artisan test tests/Feature/JournalAreaDimensionTest.php --debug
cd ..\frontend
pnpm test:unit
pnpm build
```

Después actualizar Chrome con Ctrl+F5, abrir `Nuevo Asiento`, comprobar montos siempre visibles, segunda fila condicional, modal centrado y en **DevTools → Network → editor-bootstrap → Headers** copiar `Server-Timing`, duración y tamaño del nuevo response. Verificar `compañía DEMO`; **no realizar POST** de prueba sin autorización para escritura contable. F3.3 sigue **[ ] y 8/32 (25%)**.

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

Con la migración ya instalada, **solo verificar** su estado y continuar con pruebas, sin repetir DDL:

```powershell
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

## Validación posterior del editor compacto — 24/10/2026

Resultados del usuario tras cambiar el formulario a dos filas y reducir el DTO: `pnpm build` **PASS**, Vite 6.4.4, 1.635 módulos, **2,59 s**. Laravel `JournalAreaDimensionTest` **10/10 PASS, 80 aserciones, 241,97 s** (incluye 5 comprobaciones nuevas del DTO compacto). Frontend `pnpm test:unit` **42/43 PASS**; el único fallo fue `ENOENT` en una ruta incorrecta dentro de `frontend/scripts/journal-area.test.mjs` (se usaba `../../../backend`, que resolvía fuera del repositorio a `C:\Proyectos\backend`). **Corregida en `main` a `../../backend`** y añadida comprobación `existsSync` para informar claramente si falta el archivo. **Pendiente repetir `pnpm test:unit` para certificar 43/43**. No hay nueva medición HTTP ni aceptación visual posterior; F3.3 permanece [ ], 8/32.

## Medición de latencia y carga diferida — 24/10/2026

**Evidencia de Chrome:** GET `editor-bootstrap?date=2026-10-24`: HTTP 200, **6,24 s y 353 kB**, frente a 5,98 s y 505 kB. El volumen transferido bajó **152 kB (30,1 %)**, pero el tiempo subió **0,26 s (+4,3 %)**. La reducción de peso todavía **no es una optimización de latencia**.

Otras solicitudes visibles en el Plan de Cuentas incluyen árbol (~3.096 kB), monedas, rubros, centros, áreas, tabla 03 y configuración, hasta 22,74 s de duración. Los tiempos del navegador no identifican por sí solos consultas SQL lentas: puede existir cola con el servidor PHP de desarrollo de Windows y latencia de conexión.

**Cambios publicados en main sin migraciones ni escrituras:**

- `AccountingCatalogView.vue` difiere la carga de seis catálogos auxiliares hasta que se abra el formulario de nueva cuenta o edición, en lugar de solicitarlos al entrar en el módulo o cambiar de empresa. El árbol contable permanece como única carga inicial. Al cambiar de empresa se invalida el estado de catálogos cargados.
- `GetJournalEditorBootstrapController.php` agrega métricas `schema_accounts`, `schema_subdiaries`, `subdiaries`, `controller_total` a `Server-Timing`, conservando cuentas, áreas, número, TC y centros. Solo expone duraciones agregadas, sin SQL ni credenciales.
- Dos casos nuevos en `journal-area.test.mjs`: **45 pruebas frontend esperadas**, sin ejecución posterior confirmada. Una Feature añade verificación del encabezado sin cambiar sus **10 casos**.

**Validación manual:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd frontend
pnpm test:unit
pnpm build
cd ..\backend
php artisan test tests/Feature/JournalAreaDimensionTest.php --filter="loads journal editor catalogs"
```

Después de recargar, comparar los módulos `#plan-cuentas` y `#diario`. En Chrome F12 → Network → seleccionar `editor-bootstrap` → Headers → **Response Headers**: copiar la cadena completa `server-timing`. También consultar en Timing **Waiting (TTFB)** y **Content Download**. Comprobar que monedas/rubros/áreas/cost centers/configuración se descarguen al abrir el modal de cuentas y no al entrar al plan.

**Precaución:** no hay medición posterior a este cambio ni evidencia de 45/45 PASS. No ejecutar migraciones ni POST contables. F3.3 continúa **[ ] y 8/32 (25 %)**.

## Diagnóstico preciso de Server-Timing y reducción de viajes SQL — 24/10/2026

**Medición aportada por el usuario:** `editor-bootstrap` HTTP 200, **6,41 s** en Chrome, 353 kB. La cabecera `Server-Timing` anterior reportó (ms): `schema_accounts=289,9`, `accounts=662,2`, `company_areas=297,1`, `cash_flow_areas=291,8`, `next_number=298,6`, `exchange_rates=875,4`, `cost_centers=290,1`, `schema_subdiaries=288,5`, `subdiaries=292,3`, `controller_total=3586,1`. Las etapas suman ~3586 ms. La diferencia visible hasta 6,41 s (~2824 ms) queda **fuera de la medición del controlador** y puede incluir transacción de empresa, autenticación, preparación de Laravel, servidor Vite/PHP, descarga y red; aún no se puede distribuir sin un segundo cronometraje.

**Causa probable:** numerosas operaciones PostgreSQL separadas cuestan ~290 ms cada una, incluso los dos `Schema::has*`; indica un componente elevado de ida/vuelta hasta Neon, aunque el detalle concreto de red, arranque y SQL aún no está medido aisladamente. `exchange_rates=875,4 ms` ejecuta varias consultas a través del controlador existente; se decidió no alterar por ahora sus reglas de compra/venta ni la selección de tasas históricas.

**Optimización publicada en main, NO ejecutada ni validada todavía:** `GetJournalEditorBootstrapController.php` obtiene los **cinco catálogos** (`accounts`, `company_areas`, `cash_flow_areas`, `cost_centers`, `subdiaries`) en una única sentencia `SELECT` con cinco agregaciones `jsonb_agg` (un solo viaje SQL). Cada subconsulta restringe `company_id = ?::bigint` y se ejecuta en el mismo `SET LOCAL` con `FORCE RLS`; no existen cachés cross-tenant ni escrituras. Conserva exactamente los campos y orden por `code` del editor. Desaparecen dos llamadas independientes de esquema, cuya instalación ya fue validada en DEV. `next_number` y `exchange_rates` mantienen sus controladores existentes y comportamiento original.

**Observabilidad:** sustituye tiempos separados de catálogos por `catalogs_sql;dur`, conserva `next_number`, `exchange_rates` y `controller_total`; `SetTenantContext` añade `tenant_total;dur`, que engloba autorización, SET LOCAL, transacción y el controlador. **`tenant_total` y `controller_total` se superponen: nunca deben sumarse.** No se divulga SQL, RUC, secretos o datos de usuario.

**Regresión nueva:** `JournalAreaDimensionTest.php` incluye una nueva prueba de aislamiento de catálogos cross-tenant, junto a comprobación del encabezado `tenant_total`: **11 pruebas Feature previstas**, sin PASS todavía para este cambio. `journal-area.test.mjs` añade una prueba de instrumentación: **46 pruebas frontend esperadas**, sin PASS todavía. Compilación pendiente.

**Pasos de verificación:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd frontend
pnpm test:unit
pnpm build
cd ..\backend
php artisan test tests/Feature/JournalAreaDimensionTest.php --filter="journal editor catalogs|five batched"
```

Después de recargar `#diario`, capturar Chrome Network `editor-bootstrap` → **Server-Timing**, `Timing → Waiting (TTFB)` y tiempo/tamaño completos. También comparar navegación desde `#plan-cuentas`, donde los catálogos auxiliares ahora se difieren al abrir el formulario. **Sin migraciones ni POST reales.** Hasta verificarlo, no hay mejora de tiempo acreditada y F3.3 sigue abierta **8/32 (25 %)**.
