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

## Pruebas de regresión (sin nuevas migraciones)

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
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
