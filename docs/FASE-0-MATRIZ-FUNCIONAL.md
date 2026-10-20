# Fase 0 — Matriz de estado funcional y evidencia

> **Repositorio:** `jwichpas/contabilidad` · **Rama:** `main` · **Fecha:** 2026-10-19  
> **Documento rector:** [README-PLAN-MAESTRO.md](../README-PLAN-MAESTRO.md).  
> **Método:** inspección estática de archivos GitHub y `SELECT` de inventario en la única base `contabilidad-database`. **Ninguna función se declara aprobada por pruebas end-to-end.**

## Estados de clasificación

- **Código localizado (sin validar):** hay implementación, pero no se ejecutó en esta Fase 0.
- **Parcial:** existe parte del flujo o falta API, datos, parametrización o validación.
- **No localizado:** no se encontraron rutas o implementaciones específicas en el inventario revisado. No equivale a demostrar ausencia absoluta.
- **Validación SQL limitada:** los registros actuales pasan consultas simples; no equivale a probar operaciones completas.

## Matriz funcional

| Componente | Estado F0 | Evidencia en el repositorio | Pendiente / fase |
|---|---|---|---|
| Login / logout Sanctum | Código localizado (sin validar) | `backend/routes/api.php` y `app/Http/Controllers/Api/V1/Auth/` | Seguridad, control de sesión y pruebas: F1 |
| Listado y selección de empresas | Código localizado (sin validar) | `tenant/companies`, `tenant/switch-company`, `frontend/src/shared/auth/useAuthSession.ts` | Aislamiento, revocaciones y pooling: F1 |
| Selector global de empresa en sidebar | **Ya hay implementación** (sin E2E) | `frontend/src/shared/layout/AppSidebar.vue`, `frontend/src/App.vue`, `useAuthSession.ts` | **No rehacer desde cero**; validar persistencia, permisos y usabilidad: F4 |
| Layout de autenticación + aplicación | Parcial | `LoginView.vue`, `AppSidebar.vue`, `AppHeader.vue`, `App.vue` | Evaluar separación y navegación, evitar reescritura innecesaria: F4 |
| Plantillas PCGE | Código + datos localizados | `accounting_account_templates`, seeders; **PCGE_2019 1,764 y PCGE_2026 1,637** | Reglas de vigencia, calidad de catálogo, estructura seis dígitos: F3 |
| Árbol y edición del plan de cuentas | Código localizado (sin validar) | CRUD parcial de cuentas en rutas API, `AccountingCatalogView.vue` | Validar requerimientos por cuenta y dimensiones: F3 |
| Asistente de **crear empresas** | No localizado en rutas inventariadas | Solo listado/cambio de empresa y actualización de parámetros | Implementar wizard y API de creación: F3 |
| Configuración empresa/monedas | Código localizado (sin validar) | Endpoints `company/settings` y `exchange-rates`, vistas Vue | Pruebas de aislamiento y tipos de cambio: F3 |
| Entidades, áreas y centros de costo | Parcial | Tablas, seeders y endpoints `GET`; no se observó CRUD completo en `routes/api.php` | Crear/editar/desactivar maestros: F3 |
| **Editor único** de asientos | **Ya hay implementación parcial** | `JournalEntryView.vue` contiene colección `lines` y editor de partidas | Validar flujo real y UX, sin duplicarlo: F4 |
| Alta/POST de asientos | Código localizado + validación SQL limitada | `CreateJournalEntryController.php`, `PostJournalEntryService.php`, `journal_entries` | F2: concurrencia, idempotencia, restricciones y pruebas |
| Partida doble (registros existentes) | **Validación SQL limitada** | 2 asientos / 4 partidas, cero descuadres y cero referencias huérfanas | Probar masivamente: F2 |
| Extorno contable | **Parcial** | `PostJournalEntryService::reverse()` existe; no se observó ruta de extorno en `routes/api.php` | Autorización, API, auditoría y flujo UI: F2 |
| Precisión monetaria | **Riesgo identificado** | `CreateJournalEntryController.php` usa `(float)`; `JournalEntryView.vue` usa `parseFloat` y `toFixed` en conversión | Corregir de extremo a extremo: F2 |
| Correlativos | **Riesgo identificado** | Generación `COUNT + 1` en controlador y ruta `next-number` | Concurrencia e idempotencia: F2 |
| Periodos fiscales | Código localizado (sin validar) | `fiscal_periods`, modelo y validaciones de período abierto | Cierre/reapertura autorizada y consistencia: F2/F5 |
| Libro Mayor y Balance de Comprobación | No localizados como endpoints | `routes/api.php` carece de rutas dedicadas | F5 |
| CxC/CxP, caja y conciliación | No localizados como endpoints | Sin módulos dedicados en inventario de `routes/api.php` | F5 |
| Catálogos SUNAT de consulta | Código + datos localizados | `ListSunatCatalogController.php`, tablas `sunat_table_*` | Verificación oficial y versiones: F6 |
| PLE/RCE/RVIE/SIRE | No localizados como generadores de libros | Catálogos presentes, sin endpoints de exportación en inventario | F6 |
| NIIF y rubros EEFF | Código/datos de catálogo presentes | `accounting_standards`, `financial_statement_rubrics` | No equivale a motor de EEFF; F7 |
| Estados financieros completos | No localizados como módulo operativo | Sin endpoints/vistas específicos en inventario | F7 |
| Activos fijos / construcción en curso | No localizados | Sin tablas/rutas específicas en inventario actual | F7 |
| Suite automatizada | Código localizado; **no ejecutada** | 7 archivos Pest en `backend/tests/Feature/` | F0.3: runner, conexión y rollback seguros |
| GitHub Actions / compilación | **Bloqueada** | Workflow manual `phase0-static-baseline.yml`; primera ejecución sin runner ni pasos | Ejecutar compilación, lint y pruebas cuando haya runner |

## Hallazgos para evitar re-trabajo

1. El **selector de empresa en el sidebar** ya existe en Vue. La Fase 4 debe evaluarlo y perfeccionarlo, no crear otro selector.
2. Existe un **editor de partidas en una sola vista**. Falta validar su comportamiento; no deducir que está ausente solo porque el plan lo incluye.
3. El **motor de extorno existe como servicio**, pero su exposición por API y UI no fue localizada; completar integración en F2.
4. La pérdida potencial de precisión afecta **backend y frontend**. El flujo de tipo de cambio de `JournalEntryView.vue` usa `parseFloat`; incorporar este hallazgo al criterio F2.
5. Los **7 archivos Pest no constituyen pruebas aprobadas** y algunas pruebas usan `SET ROLE` y `DELETE`. Revisarlas antes de ejecutarlas sobre la única BD de desarrollo.
6. La base tiene 1 empresa; **no es suficiente para validar aislamiento multiempresa real**.

## Instrumentos reproducibles agregados en F0

- **Preflight estático sin BD:** `php backend/scripts/phase0-preflight.php --json`. Revisa archivos, separación de conexiones y salvaguardas; no ejecuta pruebas contables.
- **Auditoría Artisan read-only:** `cd backend && php artisan contabilidad:auditar-fase0 --json`. Valida identificador de proyecto, inicia transacción de solo lectura y coteja migraciones nominales.
- **Preparación de respaldo no destructivo:** `backend/scripts/phase0-backup.sh`. Exporta solo el esquema `public` y sus datos a un archivo fuera del repositorio mediante `pg_dump`, y valida el índice del archivo con `pg_restore --list`. **No se ha ejecutado**, no se ha restaurado y no demuestra todavía recuperabilidad.
- **CI manual:** `.github/workflows/phase0-static-baseline.yml`, sin conexión a la base; runner aún pendiente.

## Pendientes de aprobación de Fase 0

- **F0.1:** inventario avanzado; respaldo real y recuperación siguen **sin comprobarse**.
- **F0.2:** contraste de archivos de migraciones contra `public.migrations` completado nominalmente (14/14); falta verificar ejecución real y comportamiento de módulos. Matriz funcional documentada aquí.
- **F0.3:** consultas read-only y preflight independiente preparados; sigue pendiente ejecutar la suite real Laravel, compilación Vue, revisión completa de normativa y rotación de credencial comprometida.

**Semáforo de esta matriz:** ninguna funcionalidad está certificada como lista para producción. F0 sigue EN PROGRESO y el hito H1 no se ha aprobado.
