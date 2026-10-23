# F3.3 · Maestros, dimensiones y escenarios multiempresa — diagnóstico inicial

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

**Estado: CÓDIGO PUBLICADO, PENDIENTE REGRESIÓN MANUAL.** Sin DDL ni datos persistidos.

- `GET /api/v1/company/entities`: listado tenant-local, búsqueda limitada, proyección segura que no entrega cuentas bancarias, CCI ni número de detracción.
- `GET /api/v1/company/entities/{entityId}`: ficha del anexo bajo TenantScope; devuelve 404 ante IDs de otras empresas.
- `POST /api/v1/company/entities`: ADMIN/CHIEF_ACCOUNTANT/ACCOUNTANT, validación de códigos de identidad contra tabla SUNAT 02, DNI de 8 dígitos y RUC de 11 (validación de **formato**, no cotejo en línea con SUNAT), UNIQUE por tenant/tipo/número, clasificación múltiple (p. ej. cliente y proveedor en **un solo registro**).
- `PUT /api/v1/company/entities/{entityId}`: mismos roles, cambios parciales y auditoría.
- `PATCH /api/v1/company/entities/{entityId}/deactivate`: ADMIN/CHIEF_ACCOUNTANT; desactivación lógica idempotente, nunca eliminación física.
- **Auditoría obligatoria y atómica** en `audit_logs`: ENTITY_CREATED / ENTITY_UPDATED / ENTITY_DEACTIVATED. Las trazas no incluyen identificaciones completas ni números bancarios; si falla el INSERT de auditoría, la transacción de negocio se revierte.
- `company_id`, UUID, estado y datos bancarios quedan **prohibidos en entradas externas** de este incremento; la futura gestión bancaria será un flujo especializado y autorizado.
- `backend/tests/Feature/CompanyEntityMasterCrudTest.php`: escenarios de alta, doble rol, duplicados, formato, campos prohibidos, auditoría, edición, desactivación, lectura cruzada 404 y autorización 403. El harness Pest usa `DatabaseTransactions` y no debe confirmar fixtures en Neon DEV.

**Validación manual pendiente desde `backend/`:**

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

**Siguiente control:** ejecución manual del CRUD y pruebas negativas contra Neon DEV mediante `DatabaseTransactions` (rollback), corrección de incidencias y posterior implementación/aceptación de la interfaz Vue.
