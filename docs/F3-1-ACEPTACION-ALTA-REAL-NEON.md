# F3.1 · Aceptación de la primera empresa creada desde el asistente

**Observación:** 22/10/2026 (hora de Perú). **Entorno:** Neon PostgreSQL 18, rama de desarrollo `contabilidad-dev`. **Pruebas SQL:** solo lectura. **Alcance:** creación real persistida por `POST /api/v1/tenant/companies` desde Vue local, no prueba de consulta en vivo al padrón SUNAT.

## Evidencia del usuario

- Migración global F3.1 n.º **24** instalada mediante `php artisan migrate --database=pgsql_migrations --path=database/portable_migrations/2026_10_22_160000_install_sunat_ruc_reference_catalogs.php`, 9 s, `DONE`.
- Preflight posterior `[PASS] F3.1: 24 migraciones, 3 catálogos SUNAT (51/417/38), empresa DEV intacta.`.
- Navegador: `POST http://localhost:5180/api/v1/tenant/companies` → **HTTP 201 Created**.
- JSON respuesta: **empresa ID 4**, RUC `20610141588`, **TRANSPORTES URVISUR EIRL**, dirección declarada `AV LEGUIA 969`, plan `PCGE_2019`, **2.966 cuentas generadas**.

## Confirmación independiente en Neon DEV

| Comprobación | Resultado |
|---|---|
| Migraciones Laravel | 24 |
| Empresas persistidas | 4: tres DEMO originales más empresa ID 4 |
| Membresías activas totales | 9 registros de membresía; alta ID 4 con ADMIN |
| Cuentas PCGE de la empresa ID 4 | 2.966, todas con códigos únicos |
| Cuentas operativas | 1.214, todas de seis dígitos |
| Padres contables huérfanos | 0 |
| Monedas de la empresa 4 | 2 |
| Áreas organizacionales | 6 |
| Flujos NIC 7 | 9 |
| Configuración fiscal | EIRL, SUNAT contribuyente 07, MYPE_TRIBUTARIO |
| Actividad económica oficial | CIIU 4923, **TRANSPORTE DE CARGA POR CARRETERA** |
| Tributos seleccionados | 1011 / IGVICP y 3031 / RTA3CP |
| Auditoría de alta | 1 evento COMPANY_ONBOARDED |
| Período existente DEMO A enero 2026 | Sigue OPEN |
| Asientos contables registrados | 0 |
| Referencias globales versionadas | 51 contribuyentes / 417 CIIU / 38 tributos (3 datasets) |
| RLS de actividades y tributos | `app_backend` con contexto ID 4 ve 1 actividad y 2 tributos; con contexto DEMO A, 0 / 0 |

El ensayo de aislamiento se ejecutó con `SET LOCAL app.current_company_id` en transacción contra el rol `app_backend`, sin modificar filas.

## Seguimiento aún abierto

**1. Clasificación de macrosector inconsistente.** La actividad oficial `4923` pertenece a transporte, pero el campo interno `company_economic_activities.sector_code` quedó `COMERCIAL`. La interfaz anterior permitía introducir el macrosector manualmente. Se añadieron validadores en Laravel y Vue y asignación automática por división CIIU inequívoca, de modo que futuras altas con 4923 usen `TRANSPORTE_LOGISTICA`. **El registro existente no fue modificado sin autorización del usuario**. La corrección posterior del sector existente deberá ser auditada.

**2. Rendimiento del clonado PCGE.** Último ensayo reversible del mismo flujo: **45,97 segundos**, por encima de 25 segundos deseados. La respuesta real 201 confirma éxito, pero no demuestra una mejora de duración. Continuar optimización/profiling; no confundir `set_time_limit(120)` con reducción real de latencia.

**3. Datos fiscales declarativos.** `annual_income` está registrado como `100.00`; verificar con el usuario si es un dato real o de prueba. La validez matemática del RUC y los catálogos incorporados NO equivalen a consultar en línea la ficha RUC de SUNAT ni a validar de manera automática el régimen de esa empresa.

**4. Regresión local tras nueva validación sectorial.** **APROBADOS por ejecución manual:** `SunatRucReferenceDataTest.php`: **3 pruebas, 525 aserciones** y `CompanyOnboardingRulesTest.php`: **3 pruebas, 12 aserciones**. Total **6 pruebas, 537 aserciones**. Siguen pendientes los controles de compilación TypeScript/Vite y otras pruebas de integración posteriores al cambio.

### Corrección del arranque de pruebas Pest sin acceso a BD

La primera ejecución manual de `php artisan test tests/Unit/SunatRucReferenceDataTest.php` falló en **3 casos** por usar `database_path()` y `ValidationException::withMessages()` sin que Pest hubiese iniciado la aplicación Laravel (solo `tests/Feature` utilizaba `Tests\\TestCase`). **No es un fallo de Neon, los 3 catálogos ni de la migración 24.**

Se configuró `uses(\\Tests\\TestCase::class);` de manera **local a los dos archivos** `SunatRucReferenceDataTest.php` y `CompanyOnboardingRulesTest.php`. No se utilizó `DatabaseTransactions`, no se ejecuta SQL y no se alteró el `tests/Pest.php` global. **Reejecución confirmada por el usuario:** ambas suites **PASS** (3/525 y 3/12). El problema de inicialización de Laravel queda resuelto; no hubo escrituras en Neon.

### Incidencia de ejecución 23/10/2026 y segunda optimización (no verificada)

La nueva ejecución del usuario confirmó:

- `pnpm typecheck`: **FAIL TS6133** en `CompanyOnboardingWizard.vue` porque `activity` era una variable sin usar en `for (const [index, activity] of draft.activities.entries())`. Se publicó la corrección a `for (const index of draft.activities.keys())`. **Typecheck/build aún pendientes**.
- `pnpm test:unit`: **18/18 PASS**, incluidas validaciones SUNAT y el mapeo CIIU→sector.
- `phase3-company-onboarding-preflight.php --check`: **PASS**: 24 migraciones, 51/417/38, hashes, FK y FORCE RLS; cuatro empresas, nueve membresías y dos códigos tributarios asignados.
- `phase3-neon-company-onboarding-http.php --probe=contabilidad-dev`: **PASS funcional** (capability, catálogos, 3 errores 422 por código falso, alta EIRL/RMT con PCGE, auditoría, rollback y baseline). **Duración POST: 116,11 segundos, fuera de presupuesto**.
- Perfil observado: **146 operaciones con 107,51 s de tiempo de consultas/ida-vuelta**; 31 que incluyen cuentas PCGE = 26,79 s, 5 plantillas PCGE = 7,28 s, otras 110 = 73,43 s; tiempo restante 8,60 s. Esas duraciones de `QueryExecuted` **incluyen latencia de red/driver**, no son evidencia exclusiva de tiempo CPU en PostgreSQL.
- **Corrección publicada**: seis áreas y nueve flujos ahora se insertan en dos sentencias dentro de la transacción para nuevas empresas, evitando `firstOrCreate` por fila; la clase 9 y sus dos centros de costo usan seis INSERT por nivel más un INSERT de centros, sin deshabilitar integridad ni FORCE RLS. El camino anterior continúa para empresas preexistentes. El perfilador separa ahora `operational_defaults`, `cost_centers` y `tenant_context`.
- **Pendiente:** aprobar PHP lint, Vue typecheck/build y **repetir una sola vez el ensayo reversible medido**. No se puede afirmar mejoría hasta que el usuario proporcione la nueva medición. No efectuar un alta real ni borrar los datos existentes.

### Medición tercera: optimización de áreas, flujos y analíticas aprobada funcionalmente — 23/10/2026

La ejecución manual del usuario terminó con HTTP 201, tres catálogos globales y los tres rechazos SUNAT 422 correctos, 6 áreas/9 flujos, PCGE, auditoría y `ROLLBACK`. Neon volvió a **4 empresas y 9 membresías**, sin modificar los períodos previos.

| Métrica | Medición anterior | Medición nueva | Variación |
|---|---:|---:|---:|
| Tiempo total del POST | 116,11 s | **75,27 s** | **−40,84 s (−35,2%)** |
| Consultas SQL | 146 | **102** | **−44** |
| Tiempo de consultas/red | 107,51 s | **71,18 s** | −36,33 s |
| Sentencias con cuentas PCGE | 31 / 26,79 s | **17 / 11,32 s** | −14 sentencias |
| Referencias PCGE | — | 5 / 7,16 s | |
| Áreas/flujos | — | 2 / 1,59 s | |
| Centros de costo | — | 2 / 1,18 s | |
| Contexto multiempresa | — | **15 / 10,39 s** | |
| Otras consultas | — | **61 / 39,54 s** | |
| PHP/red fuera del driver SQL | 8,60 s | **4,09 s** | |

**Interpretación:** la segunda optimización de áreas, NIC 7 y clase 9 conservó funcionalidad y redujo 35,2% la duración. No cumple aún el objetivo de 25 segundos; ni los 71,18 s de `QueryExecuted` significan necesariamente CPU en PostgreSQL: son duración de acceso a consulta e incluyen red/driver.

**Tercera optimización publicada en `main`, todavía no medida por el usuario:** `GlobalReferenceReadiness` valida los 13 catálogos globales, versiones PCGE 2019/2026 y PEN mediante un solo SELECT en lugar de docenas de viajes por separado; `CompanyOnboardingGuard` comprueba columnas requeridas, dos tablas de empresa y conteos SUNAT 51/417/38 en una consulta consolidada. Ambas conservan **fail-closed** ante errores, tablas faltantes o permisos incompletos. La comprobación ADMIN continúa bajo el contexto de empresa. No se cambió el comportamiento de FORCE RLS.

**Pendiente de validar:** `php -l` de ambos archivos, prueba reversible HTTP con sus PASS/ROLLBACK y nuevos tiempos, `pnpm typecheck` y `pnpm build`. No repetir migraciones ni crear empresas para medir rendimiento. **No marcar <25 s antes de medirlo.**

### Verificación del 23/10/2026: 24 migraciones, 4 empresas y Vue aprobado

Resultados aportados por el usuario: preflight local **PASS** con 24 migraciones, integridad SHA-256 de los catálogos 51/417/38, FK y RLS; Neon DEV registra **4 empresas, 9 membresías y 2 códigos tributarios asignados**. `php artisan migrate --path=... --force` informó `Nothing to migrate`, confirmando que **no se requieren más DDL**. El frontend terminó **18/18 pruebas unitarias PASS, `pnpm typecheck` PASS y `pnpm build` PASS**.

SELECT independiente a Neon confirma que empresa ID 4 conserva **2.966 cuentas, 1 membresía, 1 actividad y 2 tributos**, con una auditoría `COMPANY_ONBOARDED`. Cuenta con seis áreas, nueve flujos NIC 7, dos centros de costo y `PCGE_2019`. **No se han modificado registros**. Sigue pendiente corregir de modo auditable el macrosector `COMERCIAL` de su actividad CIIU `4923` (transporte) y corroborar si ingresos de referencia `100.00` son definitivos; no adivinar datos del contribuyente.

**Optimización nueva publicada en `main` y aún no medida:** `TenantContext::run()` conserva SAVEPOINT y rollback de llamadas anidadas del mismo tenant, pero reutiliza el `SET LOCAL` seguro ya instalado por el contexto padre. No se aplica cuando cambia la empresa, el contexto fue bypassed ni cuando no existe transacción SQL. Nueva prueba de integración comprueba rollback de SAVEPOINT en mismo tenant y restauración de RLS; pendiente ejecutarla en PostgreSQL descartable, no en Neon vivo.

El ensayo reversible `phase3-neon-company-onboarding-http.php` ahora discrimina perfiles de SQL por empresa, configuración, membresías, monedas, actividades, tributos y auditoría; solo emite agregados de tiempos/cantidades, nunca consultas, parámetros ni secretos. **Se requiere una nueva medición del ensayo reversible sin altas reales**; hasta ella el mejor tiempo confirmado sigue siendo **75,27 s** y F3.1 no cumple la meta interna de 25 s.

---

## Pruebas de regresión (sin nuevas migraciones)

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l app/Support/Tenant/TenantContext.php
php -l tests/Feature/Security/TenantContextNestedTest.php
php -l app/Support/Company/CompanyOnboardingGuard.php
php -l app/Support/Company/GlobalReferenceReadiness.php
php -l app/Support/Company/CompanyEconomicProfile.php
php -l app/Http/Controllers/Api/V1/Tenant/CreateCompanyFromWizardController.php
php -l scripts/phase3-company-onboarding-preflight.php
php -l scripts/phase3-neon-company-onboarding-http.php
php artisan test tests/Unit/CompanyOnboardingRulesTest.php tests/Unit/SunatRucReferenceDataTest.php
php scripts/phase3-company-onboarding-preflight.php --check

cd ..\frontend
pnpm typecheck
pnpm test:unit
pnpm build
```

**No ejecutar** `migrate:fresh`, la migración 24 otra vez ni alta duplicada de la empresa real. La prueba E2E con `--probe=contabilidad-dev` puede ejecutarse posteriormente: utiliza una empresa ficticia y `ROLLBACK`, preservando las cuatro empresas persistidas, aunque puede tardar más de 45 s.

[Plan maestro](../README-PLAN-MAESTRO.md) · [Catálogos SUNAT](F3-1-CATALOGOS-SUNAT-GLOBALES.md).
