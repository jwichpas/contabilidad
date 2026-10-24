> **F3.3 · Áreas en partidas (24/10/2026):** área organizativa opcional y área de flujo según requisito de cuenta, validación tenant/estado/categoría y conservación en extorno. Frontend **33/33 pruebas y build PASS**; nueva migración y pruebas Laravel pendientes. [Alcance y validación](F3-3-AREAS-EN-PARTIDAS.md). Avance: **8/32 (25%)**.

> **F3.3 · Anexos en partidas (24/10/2026):** implementados selector por partida, validación por tipo de cuenta/empresa/estado, referencia histórica y copia en extorno. Frontend **30/30 pruebas y build PASS**; Feature **4/4 PASS, 29 aserciones (144,19 s)** según ejecución manual del usuario. Unit PHP y aceptación visual completa pendientes. [Instalación, alcance y aceptación](F3-3-ANEXOS-EN-PARTIDAS.md). Avance oficial sin cambios: **8/32 (25%)**.

# F3.3 · Maestros, dimensiones y escenarios multiempresa — diagnóstico inicial

> **Incremento centros de costo · 23/10/2026:** API auditada y pantalla `#centros-costo` implementadas; 27/27 pruebas frontend y build aprobados. Feature PostgreSQL aprobada (10/10, 105 aserciones); aceptación funcional Vue completa pendiente. [Contrato, límites y validación](F3-3-CENTROS-COSTO.md). Sin nuevas migraciones; F3.3 sigue abierta.

**Observación:** 23/10/2026 · **Origen:** consultas `SELECT` independientes a Neon PostgreSQL 18, rama existente `contabilidad-dev` (no rama temporal). **Solo lectura:** sin DDL, transacciones de escritura ni nuevos registros.

## Estado comprobado

| Empresa | Entidades/anexos | Centros de costo | Áreas | Flujos NIC 7 | Actividades CIIU | Asientos |
|---|---:|---:|---:|---:|---:|---:|
| 4 (alta por wizard) | 0 | 2 | 6 | 9 | 1 | 1 (ID 52) |
| DEMO A (3000000000101) | 0 | 2 | 6 | 9 | 0 | 0 |
| DEMO B (3000000000102) | 0 | 2 | 6 | 9 | 0 | 0 |
| DEMO C (3000000000103) | 0 | 2 | 6 | 9 | 0 | 0 |

**F3.1 resuelta:** empresa 4 CIIU 4923 clasificado como `TRANSPORTE_LOGISTICA`, corrección auditada ID 83 y verificación independiente de su transición anterior/nueva. El primer asiento ficticio ID 52 se mantiene.

## Primer incremento implementado en GitHub · 23/10/2026

**Estado de esta sección:** fecha de publicación inicial; la regresión manual del backend fue aprobada posteriormente (ver bloque de validación más abajo). Sin DDL ni datos persistidos.

- `GET /api/v1/company/entities`: listado tenant-local, búsqueda limitada, proyección segura que no entrega cuentas bancarias, CCI ni número de detracción.
- `GET /api/v1/company/entities/{entityId}`: ficha del anexo bajo TenantScope; devuelve 404 ante IDs de otras empresas.
- `POST /api/v1/company/entities`: ADMIN/CHIEF_ACCOUNTANT/ACCOUNTANT, validación de códigos de identidad contra tabla SUNAT 02, DNI de 8 dígitos y RUC de 11 (validación de **formato**, no cotejo en línea con SUNAT), UNIQUE por tenant/tipo/número, clasificación múltiple (p. ej. cliente y proveedor en **un solo registro**).
- `PUT /api/v1/company/entities/{entityId}`: mismos roles, cambios parciales y auditoría.
- `PATCH /api/v1/company/entities/{entityId}/deactivate`: ADMIN/CHIEF_ACCOUNTANT; desactivación lógica idempotente, nunca eliminación física.
- **Auditoría obligatoria y atómica** en `audit_logs`: ENTITY_CREATED / ENTITY_UPDATED / ENTITY_DEACTIVATED. Las trazas no incluyen identificaciones completas ni números bancarios; si falla el INSERT de auditoría, la transacción de negocio se revierte.
- `company_id`, UUID, estado y datos bancarios quedan **prohibidos en entradas externas** de este incremento; la futura gestión bancaria será un flujo especializado y autorizado.
- `backend/tests/Feature/CompanyEntityMasterCrudTest.php`: escenarios de alta, doble rol, duplicados, formato, campos prohibidos, auditoría, edición, desactivación, lectura cruzada 404 y autorización 403. El harness Pest usa `DatabaseTransactions` y no debe confirmar fixtures en Neon DEV.

**Comandos históricos de regresión backend (ejecutados y aprobados el 23/10/2026):**

```powershell
php -l app/Http/Controllers/Api/V1/Company/EntityMasterController.php
php -l app/Http/Controllers/Api/V1/Company/ListEntitiesController.php
php -l tests/Feature/CompanyEntityMasterCrudTest.php
php artisan test --filter=CompanyEntityMasterCrudTest
php artisan test --filter=CompanySetupAndCatalogsTest
php artisan test --testsuite=Feature --filter=Tenant
```

No declarar F3.3 cerrada antes de las pruebas de aislamiento, esquema extendido por ejercicio, UI de anexos y demás maestros.

---

## Validación del CRUD y vista de anexos — 23/10/2026

**Backend APROBADO por ejecución manual aportada por el usuario:**

- `CompanyEntityMasterCrudTest`: **4/4 tests PASS, 51 aserciones** (crear, duplicidad, editar/desactivar, auditoría, seguridad RLS y permisos).
- `CompanySetupAndCatalogsTest`: **6/6 PASS, 44 aserciones**.
- `php artisan test --testsuite=Feature --filter=Tenant`: **20/20 PASS, 92 aserciones**; una prueba del CRUD se repite dentro de esa suite y no se suma como ejecución independiente.
- `verify-plan-master-checklist.php --check`: PASS 8/32, 24 pendientes; este verificador solo certifica consistencia documental.

**Integración Vue publicada, typecheck/unit/build aprobados; aceptación visual con sesión real pendiente:**

- `frontend/src/modules/company/views/EntityMasterView.vue`: directorio responsivo, búsqueda/paginación, creación/edición en un único formulario y confirmación de desactivación lógica.
- `frontend/src/modules/company/types/entityMaster.types.ts` y métodos del cliente HTTP existente: tipado y encabezado `X-Company-Id` / Sanctum heredados del contexto global.
- `App.vue`, `AppSidebar.vue`, `AppHeader.vue` y `navigation.types.ts`: nueva navegación `#anexos`, sin repetir selector de empresa. Los roles ADMIN/CHIEF_ACCOUNTANT/ACCOUNTANT pueden escribir; ADMIN/CHIEF_ACCOUNTANT pueden desactivar; el servidor también exige esos roles.
- `frontend/scripts/entity-master.test.mjs`: pruebas estáticas de navegación, selección global de empresa, rutas API, exclusión de datos bancarios y manejo de 422; incorporadas a `npm run test:unit`.

**Validación local ejecutada y aprobada** desde `frontend/` (21/21 tests; Vite 6.4.4, build 17,50 s):

```powershell
npm run typecheck
npm run test:unit
npm run build
```

Las pruebas estáticas/unitarias y compilación están aprobadas. La prueba visual funcional con sesión real, permisos y cambios de tenant continúa pendiente. No marcar F3.3 como completa: también falta historial tributario por ejercicio, dimensiones y escenarios sectoriales.

---

## Historial fiscal por ejercicio — implementación y validación

**Estado vigente: migración aplicada y pruebas Feature/frontend aprobadas según evidencia manual del usuario.** La aceptación funcional con sesión Vue sigue pendiente. Ver evidencia al final. La API conserva `company_settings`.

- Nueva migración Laravel `2026_10_23_140000_create_company_fiscal_profiles.php`: tabla `company_fiscal_profiles` con **(empresa, ejercicio, revisión, fecha efectiva)**, régimen, forma jurídica, ingresos de referencia, ingresos anuales NUMERIC(20,2), IGV exacto, SIRE/PLE, indicadores tributarios, motivo y usuario autor.
- `FORCE ROW LEVEL SECURITY`, política tenant fail-closed, INSERT/SELECT a `app_backend`, sin privilegios UPDATE/DELETE y trigger SQL que impide mutaciones históricas. Índices UNIQUE por empresa/ejercicio/revisión y empresa/ejercicio/fecha efectiva.
- `GET /api/v1/company/fiscal-profiles?year=2026`: consulta tenant-local; permite al rol AUDITOR consultar solo las empresas donde mantiene membresía activa.
- `POST /api/v1/company/fiscal-profiles`: requiere ADMIN/CHIEF_ACCOUNTANT, valida vigencia y régimen, crea revisiones **solo en orden cronológico**, audita obligatoriamente en la misma transacción y rechaza cambios retrospectivos que afecten períodos CLOSED o LOCKED.
- El modelo Eloquent `CompanyFiscalProfile` hereda `BelongsToTenant`; **no** se infieren ni crean ejercicios históricos durante la migración. La API no modifica el régimen actual de `company_settings`.
- `CompanyFiscalProfileTest.php`: casos de primera versión y revisión, historial filtrado, permisos entre empresas, duplicidad temporal, inmutabilidad y cierre contable. Las pruebas usan `DatabaseTransactions` y no deben dejar registros.

**Secuencia histórica de instalación y validación (ya realizada; no repetir la migración)** en `contabilidad-dev`, desde `backend/`:

```bash
php scripts/phase2-neon-connection-diagnostic.php --check
php -l database/migrations/2026_10_23_140000_create_company_fiscal_profiles.php
php -l app/Models/CompanyFiscalProfile.php
php -l app/Http/Controllers/Api/V1/Company/CompanyFiscalProfileController.php
php -l tests/Feature/CompanyFiscalProfileTest.php
php artisan migrate:status --database=pgsql_migrations
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_23_140000_create_company_fiscal_profiles.php --force
php artisan test --filter=CompanyFiscalProfileTest
php artisan test --testsuite=Feature --filter=Tenant
php scripts/verify-plan-master-checklist.php --check
```

**No ejecutar en `production` ni otra rama Neon; no crear ramas adicionales.** La política de ejecución manual está vigente: si aparece un error tras el DDL, documentar el fallo y corregir en GitHub antes de reintentar. No inventar entradas fiscales de la empresa 4; el historial se inicia mediante un acto declarativo autorizado, no un seed automático.

**Alcance pendiente:** aceptación funcional de la vista Vue ya publicada, vigencia SUNAT por régimen/ejercicio y validación de escenarios sectoriales. No marcar F3.3 completa con la migración únicamente.

---

## Infraestructura existente que debe reutilizarse

- `entities`: un directorio maestro multiempresa con documento, identificación, razón social, dirección, país, cliente/proveedor/trabajador/banco/accionista/tributos como banderas, datos bancarios y condiciones tributarias. Ya cuenta con FK al documento de identidad SUNAT y a institución financiera SUNAT, UNIQUE `(company_id, identity_document_type_code, tax_id)` y tenant scope Eloquent.
- `GET /api/v1/company/entities`: controlador Laravel para lectura filtrada con protección de campos bancarios. **Desde este incremento** también existen rutas POST/PUT/PATCH y GET detalle de anexos, pendientes de regresión Neon DEV.
- `company_areas`, `cost_centers`, `cash_flow_areas`: tablas persistidas y configuradas para las cuatro empresas; evitar instalar duplicados.
- `company_settings`: conserva régimen, forma legal, año inicial, año de ingresos de referencia y cifra de ingresos inicial, pero **no** un historial separado por fecha de vigencia y ejercicio.
- No existen actualmente tablas públicas con nombres `bank_accounts`, `workers`, `employees`, `subjournals` o equivalente claramente específico: antes de crear nuevas tablas, revisar si los requerimientos se cubren mediante `entities`, `company_settings` y metadatos del Libro Diario. No asumir que la ausencia de una tabla implica por sí sola carencia funcional de todo el dominio.

## Brechas priorizadas y criterios de prueba

1. **Directorio de terceros/anexos (primer incremento):** crear/consultar/actualizar/desactivar una entidad desde API y Vue con aislamiento `app_backend`/RLS y roles, validación de documentos SUNAT, deduplicación por empresa, auditoría de altas y cambios. La misma entidad puede ser cliente y proveedor: no duplicar filas por rol.
2. **Cost centers y dimensiones:** validar CRUD/operaciones por empresa, vínculos de cuentas operativas, requerimientos por cuenta y prohibición de FK entre empresas. Conservar las dos configuraciones iniciales en vez de recargarlas.
3. **Bancos y trabajadores:** decidir qué campos son parte del anexo compartido y cuáles requieren detalle especializado; proteger credenciales, números de cuenta y documentos. Separar institución financiera SUNAT de la cuenta bancaria del contribuyente.
4. **Subdiarios y documentos:** inventariar códigos, numeración por empresa/ejercicio y relaciones con asientos; no confundir tipo de asiento con subdiario operativo.
5. **Historial fiscal:** diseñar un modelo versionado régimen/ingresos/UIT/ejercicio/fecha efectiva mediante **migraciones Laravel** y reglas SUNAT por vigencia. No reescribir registros cerrados.
6. **Escenarios sectoriales:** cobertura comercial, servicios, industrial, agrícola, ganadera, construcción, extractiva y transporte/logística; permitir actividades mixtas, conservar una actividad principal y validar que nunca se mezclen tenants. Usar ensayos reversibles cuando no sea necesaria persistencia.

## Puerta de aprobación de F3.3

La actividad del Plan Maestro **permanece sin marcar** hasta comprobar desde API/UI: entidades + dimensiones relevantes, permisos y aislamiento, historial fiscal por ejercicio, casos de los sectores requeridos y al menos un asiento conectado al maestro bajo validaciones de cuenta. El asiento 52 ya prueba el motor POST, no el ciclo de terceros y maestros.

**Siguiente control actualizado:** ejecutar typecheck/test:unit/build del nuevo módulo Vue, verificar lectura/escritura con distintos roles y cambio de empresa; después avanzar con dimensiones e historial fiscal.


## Historial fiscal — evidencia manual recibida el 23/10/2026

La migración fiscal **ya está aplicada en Neon DEV** según la validación comunicada por el usuario. **No repetir la migración.** El módulo Vue fue publicado en el commit `6c74f5ff9ef15f4ea894b689fd3d1892ab61723c`, ruta `#historial-fiscal`.

- Backend: `CompanyFiscalProfileTest` **4/4 PASS, 36 aserciones**, según salida manual aportada; historial, fechas/revisiones, aislamiento, roles, períodos cerrados e inmutabilidad.
- Frontend: `pnpm test:unit` **24/24 PASS**; las tres pruebas nuevas son estáticas de integración y no ejecutan interacción del navegador.
- `pnpm build`: **PASS**, incluye `vue-tsc -b`; Vite 6.4.4, 1.621 módulos, 6,80 s. No se reportó una ejecución separada de `pnpm typecheck` en esta última salida.
- `php scripts/verify-plan-master-checklist.php --check`: **PASS**, 8/32 aprobadas y 24 pendientes. Solo verifica coherencia documental.

### Aceptación funcional pendiente en Vue

Usar datos ficticios y una sesión autorizada en la rama de desarrollo existente. Una revisión fiscal guardada es inmutable: elegir antes una empresa DEMO y valores de prueba revisados; no inventar el historial de empresa 4.

| Paso | Acción | Resultado esperado |
|---|---|---|
| 1 | Abrir `#historial-fiscal` con ADMIN y empresa DEMO seleccionada globalmente | Consulta del ejercicio; sin selector local duplicado |
| 2 | Cambiar ejercicio y después empresa desde el selector global | Solo historial de la empresa/ejercicio activos; sin filas de la selección anterior |
| 3 | Preparar una revisión válida y cancelar la confirmación | Ninguna revisión creada |
| 4 | Revisar empresa, ejercicio, fecha y motivo; confirmar una única revisión ficticia | Alta correcta y listado actualizado; recargar conserva la revisión |
| 5 | Consultar con un rol de lectura autorizado que tenga membresía en esa empresa | Lectura permitida; sin creación, edición ni eliminación |
| 6 | Consultar con CHIEF_ACCOUNTANT | Creación disponible con confirmación obligatoria |
| 7 | Volver al directorio `#anexos` y cambiar empresa | El selector global sigue gobernando ambos módulos |

Registrar rol, empresa, ejercicio, resultado y errores sin publicar tokens ni credenciales. No declarar estos pasos PASS hasta ejecutarlos. Las negativas HTTP de fechas, tenant y períodos cerrados ya están cubiertas por la prueba Feature reportada; la compilación no reemplaza esta aceptación visual.

**Próximo trabajo:** aceptación funcional de anexos/historial y revisión de dimensiones por cuenta, centros de costo y escenarios multisectoriales. **F3.3 permanece pendiente y el total oficial sigue en 8/32 (25%).**



## Seguimiento de validación · 24/10/2026

**Evidencia recibida · 24/10/2026:** `CostCenterMasterCrudTest` **10/10 PASS, 105 aserciones, 364,12 s**, ejecución manual del usuario contra Neon DEV. Incluye protección de matriz, sin casos omitidos. Capturas del usuario confirman apertura de anexos, historial fiscal y centros de costo; consulta de CC-ADM/CC-VTA en empresa 4 y sidebar sin duplicado tras `e067b77`. No certifican altas/ediciones/desactivaciones en navegador, cambio de empresa ni permisos visuales. F3.3 sigue abierta, **8/32 (25%)**.

La consulta del catálogo SUNAT se normaliza en el cliente HTTP para mostrar el nombre cuando la API entrega `name` en lugar de `description`; corrige la etiqueta vacía observada en el formulario de anexos. No modifica códigos ni datos persistidos.
