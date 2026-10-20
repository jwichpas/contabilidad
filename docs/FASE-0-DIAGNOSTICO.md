# Fase 0 — Diagnóstico y línea base (registro 001)

> **Proyecto:** `jwichpas/contabilidad` · **Base:** Supabase `contabilidad-database` (`bnjwxphwtkhblvhsnylj`) · **Rama:** `main`  
> **Fecha:** 2026-10-19 (America/Lima) · **Estado:** **EN PROGRESO**, no aprobada  
> **Documento rector:** [README-PLAN-MAESTRO.md](../README-PLAN-MAESTRO.md), apartado «FASE 0».  
> **Alcance actual:** consultas SQL de solo lectura en Supabase, actualización de documentos, retiro de credencial literal de PHPUnit y creación de workflow de chequeos estáticos en GitHub. No se ejecutaron migraciones, escrituras en BD, cambio de permisos ni despliegues.

## 1. Objetivo y alcance de este registro

Iniciar F0 y levantar una línea base verificable: inventario GitHub, situación PostgreSQL, correspondencia inicial de migraciones, capacidades de pruebas, riesgos y bloqueos. **No confundir F0 con corrección de F1.** Ninguna actividad F0.1–F0.3 se marca terminada hasta completar sus respectivas condiciones, especialmente verificación de respaldo de la misma base, pruebas ejecutadas y análisis no destructivo de migraciones y esquema.

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

**Datos de desarrollo reportados** (conteos, no contenido): 1 empresa, 1 usuario, 1,764 cuentas de empresa, 3,401 plantillas PCGE, 2 asientos/4 líneas, 7 entidades, 0 centros de costos y 34 eventos de auditoría. Volver a medir durante futuras pruebas controladas en la misma base de desarrollo.

### Correspondencia de migraciones

Se contrastaron **14 nombres** de archivos de `backend/database/migrations/` con **14 entradas** de `public.migrations`:
- **14/14 nombres presentes en ambos lados** en esta revisión.
- Sin faltantes nominales detectados.
- **NO comprobado:** checksum/contenido ejecutado, integridad de rollback, drift de objetos, aplicación automática desde una BD vacía ni reconstrucción desde cero (no destructiva en la base única).

**Nota:** no interpretar el resultado vacío del historial de migraciones nativas Supabase como ausencia de migraciones Laravel. Son mecanismos separados.

## 4. Hallazgos técnicos prioritarios

| Riesgo | Severidad | Evidencia | Acción futura |
|---|---|---|---|
| R01 — Exposición API de tablas sin RLS | **CRÍTICA** | 18 tablas sin RLS; grants extensos en 31 tablas, incluyendo `users` y `personal_access_tokens` | F1: revocar privilegios indebidos, RLS y políticas específicas, probar acceso API |
| R02 — Aislamiento multiempresa dudoso en pool | **CRÍTICA** | `backend/app/Http/Middleware/SetTenantContext.php` usa `set_config(..., false)` sin transacción envolvente visible | F1: controlar contexto por transacción/conexión, verificar que no migra entre peticiones |
| **R10 — Credencial DB versionada en repositorio** | **CRÍTICA** | Se identificó un `DB_PASSWORD` literal; retirado del archivo actual en commit `cc363a17` | **Pendiente:** rotar la contraseña y revisar el historial Git; no difundir el valor anterior |
| **R11 — Pruebas contra BD de desarrollo compartida** | **ALTA** | `backend/phpunit.xml` apunta a `contabilidad-database`; `tests/Pest.php` utiliza `DatabaseTransactions` | No crear BD aislada (decisión del usuario); revisar tests, preservar datos, usar transacciones/rollback y verificar conexión a la base de desarrollo |
| R03 — Pérdida potencial de precisión monetaria | ALTA | `CreateJournalEntryController.php` convierte tasas e importes a `(float)` | F2: strings decimales/BCMath end-to-end |
| R04 — Carrera de correlativos | ALTA | Controlador genera número vía `count() + 1` | F2: contador transaccional o estrategia segura e idempotente |
| R06 — `search_path` mutable | ALTA | 5 funciones reportadas por asesor Supabase | F1: revisión función por función |
| R12 — Error de cita legal del PCGE 2026 | MEDIA | Resolución correcta **002-2026-EF/30**, obligatoria desde 2028 | Corregir README y respetar vigencias en F3/F7 |
| R13 — GitHub Actions no asigna runner | MEDIA | Workflow `phase0-static-baseline.yml` creado; primer run finalizó sin pasos ejecutados ni runner asignado | Revisar disponibilidad de GitHub Actions; workflow dejado en disparo manual para evitar ejecuciones fallidas repetidas |
| R14 — Artefacto de caché de PHPUnit versionado | BAJA | `backend/.phpunit.cache/test-results` en Git | Posteriormente excluirlo del control de versiones |

### Precaución especial por R10/R11

La credencial de PostgreSQL se retiró del archivo HEAD `backend/phpunit.xml` en commit `cc363a17`. **La rotación real permanece pendiente**: el valor antiguo puede seguir funcionando y permanecer en el historial de Git. Nunca copiar la contraseña en mensajes, issues o documentación. `DatabaseTransactions` reduce efectos persistentes pero no garantiza por sí mismo ausencia de efectos colaterales.

## 5. Revisión normativa inicial (no sustituye validación legal completa)

**Fuentes públicas consultadas:**
- MEF — [PCGE](https://www.gob.pe/es/92758-ministerio-de-economia-y-finanzas-pcge) muestra la versión «Plan Contable General Empresarial Modificado 2019» como referencia recuperada.
- MEF — [Resolución CNC 002-2019-EF/30](https://www.gob.pe/institucion/mef/normas-legales/277088-002-2019-ef-30) aprueba PCGE 2019.
- Fundación IFRS — [NIIF 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/) indica periodos iniciados a partir de **1 de enero de 2027**, permitiendo adopción anticipada.
- Ministerio de Economía y Finanzas: comprobar adicionalmente el marco de **adopción local** (no confundir fecha IASB con oficialización nacional).

**Hallazgo normativo confirmado:** la [Resolución CNC 002-2026-EF/30](https://busquedas.elperuano.pe/dispositivo/NL/2550786-1), publicada el 4 de septiembre de 2026, aprueba el PCGE 2026, obligatorio desde el **1 de enero de 2028**, con adopción anticipada posible. El README citaba erróneamente la 003-2026. NIIF 18 y NIIF 19 tienen vigencia internacional general desde 2027 (adopción anticipada posible); falta verificar su adopción local y PLE/SIRE.

## 6. Pruebas y recuperación: estado real

| Prueba / salvaguarda | Estado | Motivo |
|---|---|---|
| Descubrimiento de suites Pest | **INSPECCIONADA** | 7 archivos funcionales |
| Ejecución `composer test` | **NO EJECUTADA** | Aún no se ha validado un runner con credenciales renovadas; contraseña inline retirada del XML |
| Ejecución `pnpm typecheck` | **NO EJECUTADA** | Primer workflow GitHub Actions falló sin asignar runner; no hubo pasos de compilación |
| Ejecución `pnpm build` | **NO EJECUTADA** | Primer workflow GitHub Actions falló sin asignar runner; no hubo pasos de compilación |
| Ejecutar `migrate:fresh` | **NO SE EJECUTARÁ** | Destructivo para la única base; sustituido por catálogo SQL y `migrate:status` |
| Backup completo y prueba de restauración | **NO VERIFICADOS** | El conector accesible no ofrece flujo confirmado para exportación/restauración; no inferir disponibilidad de respaldo |
| Test aislamiento empresas A/B | **NO EJECUTADA** | Pendiente de ejecución transaccional con rollback verificado sobre la base de desarrollo |
| Test del POST y concurrencia | **NO EJECUTADA** | Pendiente de fixture de desarrollo reversible y pruebas de concurrencia seguras |
| Deploy / preview | **NO REALIZADO** | Fuera del diagnóstico de F0 |

**No emitir un «passed» sin registro de salida real.** Existen suites de prueba, pero eso no constituye evidencia de ejecución.

### Comandos previstos para la única base de desarrollo (sin comandos destructivos)

```bash
# Backend (desde backend/), después de proveer un .env.testing seguro
composer install
php artisan migrate:status
# composer test: ejecutar solo después de revisar los efectos de los tests y comprobar rollback
composer lint

# Frontend (desde frontend/)
pnpm install --frozen-lockfile
pnpm typecheck
pnpm build
```

**Protección previa obligatoria:** verificar que PHPUnit usa `contabilidad-database` con contraseña renovada en variables no versionadas; revisar rollback y no ejecutar `migrate:fresh`, `db:wipe`, `db:seed` destructivo ni DDL de borrado sobre la base única.

## 7. Estado de las tareas del plan (sin marcar completadas)

| Actividad | Estado | Avance verificable | Qué falta para `[x]` |
|---|---|---|---|
| **F0.1** Inventario y respaldo | **EN PROGRESO** | Inventario de tablas, RLS, permisos, roles, índices y funciones | Verificar respaldo recuperable para la misma base de desarrollo; no crear otra BD de trabajo |
| **F0.2** Código y migraciones | **EN PROGRESO** | 14/14 migraciones por nombre; inventario de API, frontend, 7 suites | Comparación no destructiva del esquema y verificación funcional real |
| **F0.3** Baseline y normativa | **EN PROGRESO** | Identificados test runners y discrepancias normativas a confirmar | Ejecutar tests seguros, builds, revisión normativa completa |

**Indicadores del plan permanecen en 0/27 actividades aprobadas y 0/9 fases aprobadas.** La Fase 0 se encuentra *iniciada*, no *terminada*.

## 8. Siguiente trabajo dentro de F0 (antes de F1)

1. Gestionar urgentemente **rotación de credencial filtrada** (ya retirada del archivo actual `backend/phpunit.xml`), con verificación de no uso de la contraseña anterior. Como toca seguridad, registrar autorización y trazabilidad; mantener al usuario informado.
2. Utilizar solamente la BD actual `contabilidad-database`, verificar respaldo y preservar todos los registros existentes.
3. Ejecutar suites de backend (revisadas para rollback) y frontend mediante un runner disponible, usando la misma BD de desarrollo y registrando resultados.
4. Comparar esquema/migraciones mediante consultas de solo lectura; prohibida la reconstrucción destructiva en la base actual.
5. Corroborar normativas PCGE y NIIF en documentos oficiales, con fechas y estado de vigencia.
6. Concluir matriz `implementado / implementado sin verificar / parcial / pendiente`, actualizar F0.1–F0.3 y obtener puerta de aprobación F0.

## 9. Registro de cambios

| Fecha | Registro | Resultado |
|---|---|---|
| 2026-10-19 | Inicio Fase 0 y diagnóstico de solo lectura | Inventario técnico y riesgos documentados. F0 permanece abierta; sin modificaciones a DB ni código aplicativo |


## 10. Continuación — Única base de desarrollo (2026-10-19)

**Decisión del usuario:** toda la implementación y las pruebas se realizarán en Supabase **contabilidad-database**, referencia **bnjwxphwtkhblvhsnylj**, hasta finalizar el desarrollo. No se creará una base aislada. Esto no autoriza borrar datos existentes ni ejecutar comandos como `migrate:fresh`, `db:wipe` o seeders destructivos.

**Seguridad:** se eliminó el valor literal de `DB_PASSWORD` de `backend/phpunit.xml` en el commit [cc363a17](https://github.com/jwichpas/contabilidad/commit/cc363a17a351114bc61c3a29f1901f127b095b16). El secreto permanece potencialmente comprometido por versiones previas de Git: **rotación pendiente**, con actualización coordinada de todas las conexiones.

**Verificaciones SQL ejecutadas (todas SELECT, sin escrituras):**

| Métrica | Resultado |
|---|---|
| Asientos / líneas examinadas | 2 / 4 |
| Asientos descuadrados en MN/ME | 0 |
| Asientos con menos de dos líneas o total cero | 0 |
| Suma Debe y Haber local | 2,600.0000 / 2,600.0000 |
| Suma Debe y Haber extranjera | 693.3367 / 693.3367 |
| Partidas sin asiento o asociadas con cuenta de otra empresa | 0 |
| Cuentas sin empresa / con padre faltante | 0 |
| Códigos de cuenta duplicados dentro de empresa | 0 |
| Partidas contabilizadas en cuentas no operativas o inactivas | 0 |
| Plantillas PCGE 2019 / 2026 | 1,764 / 1,637 |
| Cuentas operativas de empresa | 1,212 |
| Cuentas operativas con seis dígitos | 21 (109 de 3; 396 de 4; 686 de 5 dígitos) |
| Triggers en Diario/partidas revisados en catálogo | 5; incluyendo saldo diferido, habilitados |

**Interpretación:** los dos asientos de prueba existentes concilian, pero una muestra de dos no valida concurrencia ni exactitud del motor completo.

**Verificación normativa:** fuente oficial [El Peruano, Resolución CNC 002-2026-EF/30](https://busquedas.elperuano.pe/dispositivo/NL/2550786-1) (04-09-2026): PCGE 2026 obligatorio a partir del 01-01-2028, anticipado permitido. IFRS Foundation confirma vigencia general NIIF 18 y 19 desde 2027; se verificará la oficialización local.

**Estado:** F0.1–F0.3 continúan EN PROGRESO, sin pruebas automáticas ejecutadas, sin respaldo/recuperación comprobados y sin cierre de fase. Este registro no inicia F1.

## 11. Primer intento de CI estática — GitHub Actions

- Se creó [`.github/workflows/phase0-static-baseline.yml`](../.github/workflows/phase0-static-baseline.yml) en `main`: `pnpm typecheck`, `pnpm build`, `composer validate`, `composer lint`, sintaxis PHP, control de contraseña hardcodeada.
- Workflow **sin acceso a Supabase**: no usa secretos, migraciones, seeders ni despliegue.
- Primera ejecución [39354337434](https://github.com/jwichpas/contabilidad/actions/runs/39354337434): **failure** en ambos jobs; en el resultado de API, `steps: []` y `runner_id: 0`. **No se ejecutó ningún paso**; no atribuirlo a fallo de PHP/Vue. Motivo de no asignación de runner por esclarecer en GitHub.
- Se dejó temporalmente el workflow en modo **`workflow_dispatch`** para evitar ejecuciones fallidas en cada push hasta contar con runners disponibles.
- F0.3 sigue EN PROGRESO. No se ha certificado compilación ni ejecución del suite Laravel.

## 12. Regla de esquema Laravel y continuación técnica (2026-10-19)

**Instrucción obligatoria del usuario:** creación/modificación de tablas y cualquier DDL mediante **migraciones Laravel**; Supabase se usa como PostgreSQL administrado y para consultas/advisories de solo lectura. Ver [política formal](ARQUITECTURA-BD-LARAVEL.md).

**Cambios versionados en `main` sin mutar la base:**
- Nuevo `backend/app/Console/Commands/AuditPhaseZero.php`: comando Artisan `contabilidad:auditar-fase0` con `SELECT` de inventario e integridad. Sintaxis PHP revisada mediante `php -l` local (correcta); **ejecución real en Laravel pendiente**.
- `backend/config/database.php`: la conexión administrativa `pgsql_migrations` ya no hereda `DATABASE_URL` del backend normal, que podría usar el rol equivocado.
- `backend/phpunit.xml`: se eliminaron las configuraciones de host, puerto, nombre y usuario de DB fijadas en el XML; se obtienen del entorno no versionado. La contraseña real anterior **no se ha rotado**.
- `backend/.env.example`: ejemplo actualizado para separar rol de aplicación restringido y rol administrativo que migrará desde Artisan.
- La migración inicial del rol `app_backend` contiene un **fallback de contraseña por defecto**; riesgo **R15**, por corregir sin editar destructivamente migraciones ya ejecutadas.

**Inventario adicional directamente verificado en PostgreSQL** (todas las consultas fueron `SELECT`):

| Objeto | Cantidad |
|---|---:|
| Tablas `public` | 31 |
| Índices | 94 |
| Funciones | 5 |
| Triggers no internos | 6 |
| Políticas RLS | 14 |
| Migraciones Laravel aplicadas, sin nombres duplicados | 14 (5 lotes) |
| Tablas sin RLS | 18 |

**Fuentes de validación del procedimiento:** [Laravel 11 — Migraciones](https://laravel.com/docs/11.x/migrations) y [Artisan](https://laravel.com/docs/11.x/artisan). Los 14 nombres de migraciones GitHub coinciden con los registros de la tabla `public.migrations`; la ausencia de diferencias nominales no prueba equivalencia completa de esquema.

**Próxima comprobación real:** ejecutar el comando Artisan de auditoría y `php artisan migrate:status --database=pgsql_migrations` desde un entorno Laravel con credenciales renovadas, sin modificar tablas. Pruebas Pest y compilación Vue continúan pendientes por acceso a ejecutor/runner y resguardo de credenciales. Hasta entonces **no se declara F0 cerrada**.
