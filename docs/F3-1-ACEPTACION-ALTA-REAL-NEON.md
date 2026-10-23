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

**4. Regresión local tras nueva validación sectorial.** Se adaptaron los scripts a 4 empresas y las pruebas PHP/TS del macrosector; **pendiente salida de test/typecheck/build desde Windows**.

### Corrección del arranque de pruebas Pest sin acceso a BD

La primera ejecución manual de `php artisan test tests/Unit/SunatRucReferenceDataTest.php` falló en **3 casos** por usar `database_path()` y `ValidationException::withMessages()` sin que Pest hubiese iniciado la aplicación Laravel (solo `tests/Feature` utilizaba `Tests\\TestCase`). **No es un fallo de Neon, los 3 catálogos ni de la migración 24.**

Se configuró `uses(\\Tests\\TestCase::class);` de manera **local a los dos archivos** `SunatRucReferenceDataTest.php` y `CompanyOnboardingRulesTest.php`. No se utilizó `DatabaseTransactions`, no se ejecuta SQL y no se alteró el `tests/Pest.php` global. **Resultado pendiente de repetición manual**, no marcar PASS por el cambio de código.

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
