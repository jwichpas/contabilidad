# Fase 0 — Diagnóstico y línea base (registro 001)

> **Proyecto:** `jwichpas/contabilidad` · **Base:** Supabase `contabilidad-database` (`bnjwxphwtkhblvhsnylj`) · **Rama:** `main`  
> **Fecha:** 2026-10-19 (America/Lima) · **Estado:** **EN PROGRESO**, no aprobada  
> **Documento rector:** [README-PLAN-MAESTRO.md](../README-PLAN-MAESTRO.md), apartado «FASE 0».  
> **Tipo de revisión:** solo lectura en Supabase y código; cambios únicamente a archivos Markdown en GitHub. No se ejecutaron migraciones, tests que escriban BD, cambios de permisos, cambios de código ni despliegues.

## 1. Objetivo y alcance de este registro

Iniciar F0 y levantar una línea base verificable: inventario GitHub, situación PostgreSQL, correspondencia inicial de migraciones, capacidades de pruebas, riesgos y bloqueos. **No confundir F0 con corrección de F1.** Ninguna actividad F0.1–F0.3 se marca terminada hasta completar sus respectivas condiciones, especialmente respaldo recuperable, pruebas efectivamente ejecutadas y verificación de migración desde cero.

## 2. Inventario del repositorio

**Fuente:** árbol recursivo de `main` en GitHub consultado el 2026-10-19.  
Árbol consultado: `55824716a13158aacb4e76aa46d7fc941f552766` (snapshot de esta revisión).

| Concepto | Encontrado |
|---|---|
| Archivos versionados totales | 407 (incluye duplicaciones de skills de `.agents`, `.claude` y `.kiro`) |
| Archivos bajo `backend/` | 132 |
| Archivos bajo `frontend/` | 32 |
| Migraciones Laravel | 14 archivos `backend/database/migrations/*.php` |
| Seeders Laravel | 8 archivos `backend/database/seeders/*.php` |
| Archivos de pruebas funcionales Pest | 7 en `backend/tests/Feature/` |
| Workflows GitHub Actions | 0 archivos bajo `.github/workflows/` |
| Stack | Backend Laravel 11/PHP, Sanctum; frontend Vue 3, TypeScript, Tailwind 4; Supabase/PostgreSQL |

**Pruebas existentes por archivo:**
- `tests/Feature/Accounting/AccountingAccountCatalogTest.php`
- `tests/Feature/Accounting/JournalEntryTest.php`
- `tests/Feature/Accounting/MasterChartOfAccountsTest.php`
- `tests/Feature/CompanyCurrencyConfigurationTest.php`
- `tests/Feature/CompanySetupAndCatalogsTest.php`
- `tests/Feature/Security/Iso27001SecurityComplianceTest.php`
- `tests/Feature/Tenant/TenantIsolationTest.php`

**Módulos de frontend confirmados:** `frontend/src/modules/accounting/` (plan contable, asientos y tipos de cambio) y `frontend/src/modules/company/` (configuración de empresa). La existencia de componentes **no** certifica que estén integrados o funcionen de punta a punta.

**API confirmada en** `backend/routes/api.php`: Sanctum login/logout; selección/listado de empresas, catálogos SUNAT, normas y rubros, cuentas, Libro Diario, tipos de cambio, áreas, centros de costos, entidades y configuración. La prueba de permisos/respuestas de estos endpoints está pendiente.

## 3. Base Supabase — situación observada (sin consultar datos sensibles)

**Fuente:** consultas de catálogo `pg_catalog`/`information_schema` y asesor de Supabase sobre el proyecto correcto.

| Métrica | Resultado |
|---|---|
| Tablas físicas en `public` | **31** |
| Con RLS `ENABLE` | **13** |
| Con RLS `FORCE` | **13** |
| Sin RLS | **18** |
| Roles `anon` y `authenticated` | Tienen permisos `SELECT/INSERT/UPDATE/DELETE/TRUNCATE/TRIGGER/REFERENCES` sobre **las 31 tablas** (RLS restringe filas en las 13 habilitadas) |
| Rol `app_backend` | Existe, admite login y no tiene `BYPASSRLS`; sus permisos requieren validación real desde Laravel |
| Funciones del esquema `public` con `search_path` mutable | **5** |
| Claves foráneas sin índice recomendado | **17** según asesor |
| Índices sin uso registrado | **8** según asesor; aún no se justifica eliminarlos |
| Migraciones registradas por Laravel | **14**, lotes 1–5 |
| Migraciones devueltas por herramienta de migraciones nativas Supabase | **0**; distinto gestor del registro `public.migrations` de Laravel |

**Tablas sin RLS:** `migrations`, `companies`, `users`, `personal_access_tokens`, `currencies`, `sunat_table_01_payment_methods`, `sunat_table_02_identity_document_types`, `sunat_table_03_financial_institutions`, `sunat_table_05_inventory_types`, `sunat_table_06_measurement_units`, `sunat_table_08_electronic_books`, `sunat_table_10_electronic_document_types`, `sunat_table_11_customs_dependencies`, `sunat_table_12_operation_types`, `financial_statement_rubrics`, `accounting_standards`, `tax_calendar_deadlines`, `accounting_account_templates`.

**Datos de desarrollo reportados** (conteos, no contenido): 1 empresa, 1 usuario, 1,764 cuentas de empresa, 3,401 plantillas PCGE, 2 asientos/4 líneas, 7 entidades, 0 centros de costos y 34 eventos de auditoría. Se deberán volver a medir al ejecutar pruebas aisladas.

### Correspondencia de migraciones

Se contrastaron **14 nombres** de archivos de `backend/database/migrations/` con **14 entradas** de `public.migrations`:
- **14/14 nombres presentes en ambos lados** en esta revisión.
- Sin faltantes nominales detectados.
- **NO comprobado:** checksum/contenido ejecutado, integridad de rollback, drift de objetos, aplicación automática desde una BD vacía ni reconstrucción en un sandbox.

**Nota:** no interpretar el resultado vacío del historial de migraciones nativas Supabase como ausencia de migraciones Laravel. Son mecanismos separados.

## 4. Hallazgos técnicos prioritarios

| Riesgo | Severidad | Evidencia | Acción futura |
|---|---|---|---|
| R01 — Exposición API de tablas sin RLS | **CRÍTICA** | 18 tablas sin RLS; grants extensos en 31 tablas, incluyendo `users` y `personal_access_tokens` | F1: revocar privilegios indebidos, RLS y políticas específicas, probar acceso API |
| R02 — Aislamiento multiempresa dudoso en pool | **CRÍTICA** | `backend/app/Http/Middleware/SetTenantContext.php` usa `set_config(..., false)` sin transacción envolvente visible | F1: controlar contexto por transacción/conexión, verificar que no migra entre peticiones |
| **R10 — Credencial DB versionada en repositorio** | **CRÍTICA** | `backend/phpunit.xml` contiene `DB_PASSWORD` con un valor literal, en archivo tracked | **Rotar credencial inmediatamente** y eliminarla del archivo, de tests y de dependencias; evaluar historial Git. **No publicar el valor en issues/docs/logs** |
| **R11 — Pruebas contra BD de desarrollo compartida** | **ALTA** | `backend/phpunit.xml` apunta a `contabilidad-database`; `tests/Pest.php` utiliza `DatabaseTransactions` | Antes de ejecutar tests, crear BD aislada/credenciales sin privilegios peligrosos; validar restauración |
| R03 — Pérdida potencial de precisión monetaria | ALTA | `CreateJournalEntryController.php` convierte tasas e importes a `(float)` | F2: strings decimales/BCMath end-to-end |
| R04 — Carrera de correlativos | ALTA | Controlador genera número vía `count() + 1` | F2: contador transaccional o estrategia segura e idempotente |
| R06 — `search_path` mutable | ALTA | 5 funciones reportadas por asesor Supabase | F1: revisión función por función |
| R12 — PCGE 2026 / fundamento legal por corroborar | ALTA | README declara oficial PCGE 2026 y atribuye resoluciones sin fuente documental primaria verificada en esta revisión | F0: localizar norma y texto oficial, verificar fecha, exigibilidad y cuentas; no declararlo vigente por documentación interna |
| R13 — Ausencia de CI en GitHub | MEDIA | Ningún archivo en `.github/workflows/` | F0: ejecutar baseline aislado; F8: CI automatizada |
| R14 — Artefacto de caché de PHPUnit versionado | BAJA | `backend/.phpunit.cache/test-results` en Git | Posteriormente excluirlo del control de versiones |

### Precaución especial por R10/R11

La configuración de PHPUnit contiene un valor literal que parece una credencial real de base de datos. Se omite **intencionalmente** en toda la documentación y en mensajes. Que el repositorio sea privado **no hace segura** la inclusión del secreto. La rotación y el retiro del secreto son prioritarios, pero **no se han realizado** durante F0. El mecanismo `DatabaseTransactions` de Pest reduce cierto riesgo de persistencia de datos de prueba, **no garantiza por sí mismo** aislamiento, ausencia de efectos colaterales ni seguridad para ejecución contra la base compartida.

## 5. Revisión normativa inicial (no sustituye validación legal completa)

**Fuentes públicas consultadas:**
- MEF — [PCGE](https://www.gob.pe/es/92758-ministerio-de-economia-y-finanzas-pcge) muestra la versión «Plan Contable General Empresarial Modificado 2019» como referencia recuperada.
- MEF — [Resolución CNC 002-2019-EF/30](https://www.gob.pe/institucion/mef/normas-legales/277088-002-2019-ef-30) aprueba PCGE 2019.
- Fundación IFRS — [NIIF 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/) indica periodos iniciados a partir de **1 de enero de 2027**, permitiendo adopción anticipada.
- Ministerio de Economía y Finanzas: comprobar adicionalmente el marco de **adopción local** (no confundir fecha IASB con oficialización nacional).

**Hallazgo:** durante esta revisión exploratoria **no se obtuvo respaldo oficial primario suficiente para confirmar la aprobación y entrada en vigencia de un «PCGE 2026»** que el repositorio identifica como oficial. Esto **no prueba que no exista**; obliga a obtener resolución, publicación y texto autenticado antes de sostener su vigencia o modificar catálogos. También deberá verificarse el alcance de NIIF 19. El análisis PLE/SIRE queda pendiente.

## 6. Pruebas y recuperación: estado real

| Prueba / salvaguarda | Estado | Motivo |
|---|---|---|
| Descubrimiento de suites Pest | **INSPECCIONADA** | 7 archivos funcionales |
| Ejecución `composer test` | **NO EJECUTADA** | Código disponible vía conector, sin checkout/runner; PHPUnit apunta a BD compartida y contiene credencial versionada |
| Ejecución `pnpm typecheck` | **NO EJECUTADA** | Sin checkout de frontend o runner CI en esta sesión |
| Ejecución `pnpm build` | **NO EJECUTADA** | Sin checkout de frontend o runner CI en esta sesión |
| Aplicar todas las migraciones desde cero | **NO EJECUTADA** | Requiere BD aislada para ensayo |
| Backup completo y prueba de restauración | **NO VERIFICADOS** | El conector accesible no ofrece flujo confirmado para exportación/restauración; no inferir disponibilidad de respaldo |
| Test aislamiento empresas A/B | **NO EJECUTADA** | Requiere entorno de pruebas separado |
| Test del POST y concurrencia | **NO EJECUTADA** | Requiere entorno de pruebas separado |
| Deploy / preview | **NO REALIZADO** | Fuera del diagnóstico de F0 |

**No emitir un «passed» sin registro de salida real.** Existen suites de prueba, pero eso no constituye evidencia de ejecución.

### Comandos previstos SOLO para entorno aislado

```bash
# Backend (desde backend/), después de proveer un .env.testing seguro
composer install
php artisan migrate --env=testing --force
php artisan db:seed --env=testing --force
composer test
composer lint

# Frontend (desde frontend/)
pnpm install --frozen-lockfile
pnpm typecheck
pnpm build
```

**Protección previa obligatoria:** comprobar que las variables DB de PHPUnit apuntan al **sandbox**, nunca al proyecto `contabilidad-database` compartido, y retirar contraseñas del repositorio antes de conectar. No copiar las actuales credenciales al informe.

## 7. Estado de las tareas del plan (sin marcar completadas)

| Actividad | Estado | Avance verificable | Qué falta para `[x]` |
|---|---|---|---|
| **F0.1** Inventario y respaldo | **EN PROGRESO** | Inventario de tablas, RLS, permisos, roles, índices y funciones | Backup y restauración probada en entorno aislado |
| **F0.2** Código y migraciones | **EN PROGRESO** | 14/14 migraciones por nombre; inventario de API, frontend, 7 suites | Reconstrucción de esquema y verificación funcional real |
| **F0.3** Baseline y normativa | **EN PROGRESO** | Identificados test runners y discrepancias normativas a confirmar | Ejecutar tests seguros, builds, revisión normativa completa |

**Indicadores del plan permanecen en 0/27 actividades aprobadas y 0/9 fases aprobadas.** La Fase 0 se encuentra *iniciada*, no *terminada*.

## 8. Siguiente trabajo dentro de F0 (antes de F1)

1. Gestionar urgentemente **rotación de credencial filtrada** y retirar secreto de `backend/phpunit.xml`, con verificación de no uso de la contraseña anterior. Como toca seguridad, registrar autorización y trazabilidad; mantener al usuario informado.
2. Preparar BD sandbox/backup; verificar que una restauración funciona y que el test runner no usa la BD compartida.
3. Ejecutar suites de backend y frontend en entorno aislado; registrar comandos, salidas, duración y fallos.
4. Reconstruir esquema desde migraciones y seeders, comparar tablas/restricciones/políticas con línea base.
5. Corroborar normativas PCGE y NIIF en documentos oficiales, con fechas y estado de vigencia.
6. Concluir matriz `implementado / implementado sin verificar / parcial / pendiente`, actualizar F0.1–F0.3 y obtener puerta de aprobación F0.

## 9. Registro de cambios

| Fecha | Registro | Resultado |
|---|---|---|
| 2026-10-19 | Inicio Fase 0 y diagnóstico de solo lectura | Inventario técnico y riesgos documentados. F0 permanece abierta; sin modificaciones a DB ni código aplicativo |
