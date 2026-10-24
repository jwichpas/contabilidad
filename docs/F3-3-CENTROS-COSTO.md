# F3.3 — Gestión de centros de costo

23/10/2026 · Incremento Laravel/Vue sobre las tablas existentes. No agrega migraciones, no activa matrices y no instala datos DEMO. F3.3 permanece pendiente; avance global 8/32 (25%).

## Pantalla

`#centros-costo`, accesible desde la navegación principal, usa la empresa global. Incluye búsqueda, paginación, activos/inactivos, un único formulario de alta/edición y confirmación de desactivación. ADMIN y CHIEF_ACCOUNTANT pueden escribir; los demás roles con membresía activa pueden consultar. Laravel vuelve a validar el rol en cada escritura.

El formulario conserva sus datos ante 422 y muestra el mensaje del servidor. La vista captura la empresa al montarse y descarta resultados al desmontarse/cambiar de empresa. El editor de asientos continúa usando su consulta original de centros activos.

## Contrato HTTP

Todas las rutas exigen Sanctum y contexto de empresa (`X-Company-Id`, membresía activa, TenantScope y RLS).

| Método y ruta bajo `/api/v1/company` | Contrato |
|---|---|
| `GET /cost-centers` | Sin cambios: lista de activos para el editor de asientos |
| `GET /cost-centers/manage` | `status` active/inactive/all, `search` hasta 150 caracteres, `page>=1`, `per_page=1..100` (25 por defecto); devuelve `data` y `meta` |
| `GET /cost-centers/{id}` | Detalle activo/inactivo; 404 para un ID de otra empresa |
| `POST /cost-centers` | 201, datos y auditoría atómicos; ADMIN/CHIEF_ACCOUNTANT |
| `PUT /cost-centers/{id}` | Actualización parcial, 200; ADMIN/CHIEF_ACCOUNTANT; solo cambios efectivos generan auditoría |
| `PATCH /cost-centers/{id}/deactivate` | Desactivación lógica idempotente, 200; ADMIN/CHIEF_ACCOUNTANT; sin DELETE |

Alta: `code`, `name`, `destination_debit_account_id`, `destination_credit_account_id` obligatorios. Edición: esos mismos campos opcionales; si se suministran, no pueden estar vacíos. Código en mayúsculas, máximo 20 caracteres (letras ASCII, dígitos, guion o guion bajo; primer carácter alfanumérico), único por empresa. Nombre hasta 150 caracteres. No se aceptan `id`, `uuid`, `company_id` ni `is_active` en el formulario.

Errores: 401 sin sesión, 403 por rol/membresía, 404 por centro ajeno/inexistente, 422 por validación/duplicado/restricción. El detalle devuelve IDs, código/nombre, estado y proyección de las dos cuentas, no todo el modelo contable.

## Integridad y límites

- Cuentas del mismo tenant, activas y operativas de seis dígitos. Se reutilizan los destinos admitidos por el motor actual: **941100 o 951100 contra 791000**. Es el alcance del motor existente, no una restricción normativa general del PCGE.
- Los destinos solo pueden cambiar si el centro no tiene partidas ni rutas versionadas, incluso retiradas. Nombre/código siguen editables y auditados si el centro está activo.
- No se desactiva un centro referenciado por una matriz DRAFT o ACTIVE. Las rutas RETIRED y los movimientos históricos se conservan; estos últimos, por sí solos, no impiden desactivación lógica.
- Se toma el mismo advisory lock de contabilización/promoción, en modo exclusivo antes de bloquear centros/cuentas. Las cuentas se bloquean en orden estable. **La concurrencia entre conexiones aún debe probarse**; la existencia del bloqueo no certifica ausencia de carreras.
- La auditoría forma parte de la transacción: si falla, revierte el cambio. Eventos `COST_CENTER_CREATED`, `COST_CENTER_UPDATED`, `COST_CENTER_DEACTIVATED` con antes/después.
- Crear un centro **no crea ni activa rutas analíticas**. Con una matriz ACTIVE, las cuentas de destino automático requieren una ruta aprobada para ese centro. No se regeneran matrices ni asientos.

## Evidencia y aceptación pendiente

- `pnpm install --frozen-lockfile`: PASS. Sin cambios de dependencias ni lockfile.
- `pnpm test:unit`: **27/27 PASS**. Las tres nuevas ejecutan el constructor del payload (normalización, allowlist, IDs/límites), no un navegador ni el backend.
- `pnpm build` (`vue-tsc -b && vite build`): **PASS**, Vite 6.4.4, 1.626 módulos, 7,78 s. Hubo una compilación previa con npm/Vite 6.4.5; la comprobación con lockfile del repositorio es la evidencia de referencia.
- PHP: análisis sintáctico con `php-parser` 3.7.0; no equivale a `php -l` ni a ejecución de Laravel.
- **Feature PostgreSQL aprobada:** 10/10, 105 aserciones (364,12 s), ejecución manual del usuario el 24/10/2026. **Pendiente:** concurrencia y aceptación funcional completa con sesión Vue real. No se realizaron nuevas escrituras persistidas en Neon durante este incremento.

`CostCenterMasterCrudTest` incorpora **10 casos** contando el dataset de dos roles: alta/auditoría, duplicados por tenant, cuentas inválidas/ajenas, edición/desactivación, acceso cruzado con membresías válidas, permisos, rollback ante fallo de auditoría, conservación de partidas y protección de matriz DEMO existente. Usa `DatabaseTransactions`; no ejecuta migraciones ni usa la conexión propietaria. El caso de matriz consulta DEMO A y añade una membresía temporal dentro de la transacción; si no existe la matriz, se marca SKIPPED, no PASS.

Validación en Windows:

```powershell
git pull --ff-only origin main
cd backend
php artisan route:list --path=api/v1/company/cost-centers
php artisan test --filter=CostCenterMasterCrudTest
php artisan test --filter=CompanySetupAndCatalogsTest
php scripts/verify-plan-master-checklist.php --check
cd ..\frontend
pnpm test:unit
pnpm build
```

**No ejecutar migraciones para este cambio.** Ante un fallo, conservar la salida; no usar `migrate:fresh`, recargar catálogos ni activar matrices para hacer pasar la prueba.

Aceptación visual: entrar a `#centros-costo`, consultar activos/inactivos, crear un centro ficticio con las cuentas disponibles, editar su nombre y desactivarlo con confirmación. Debe desaparecer del selector de nuevas partidas y seguir visible en inactivos. Cambiar empresa y comprobar aislamiento. Con ACCOUNTANT/AUDITOR debe existir consulta sin escritura. No desactivar ni reasignar los centros DEMO ligados a matrices existentes.


## Seguimiento de validación · 24/10/2026

**Evidencia recibida · 24/10/2026:** `CostCenterMasterCrudTest` **10/10 PASS, 105 aserciones, 364,12 s**, ejecución manual del usuario contra Neon DEV. Incluye protección de matriz, sin casos omitidos. Capturas del usuario confirman apertura de anexos, historial fiscal y centros de costo; consulta de CC-ADM/CC-VTA en empresa 4 y sidebar sin duplicado tras `e067b77`. No certifican altas/ediciones/desactivaciones en navegador, cambio de empresa ni permisos visuales. F3.3 sigue abierta, **8/32 (25%)**.

La consulta del catálogo SUNAT se normaliza en el cliente HTTP para mostrar el nombre cuando la API entrega `name` en lugar de `description`; corrige la etiqueta vacía observada en el formulario de anexos. No modifica códigos ni datos persistidos.
