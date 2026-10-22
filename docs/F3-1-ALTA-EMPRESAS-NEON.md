# F3.1 — Alta de empresas y configuración fiscal (Neon DEV)

**Fecha:** 22/10/2026 · **Rama:** `main` · **Estado vigente:** migración 23 y E2E reversible aprobadas; catálogos SUNAT versión 24 aún no instalados; rendimiento de alta 45,97 s pendiente. Actividad del plan maestro **[ ]**.

## Estado más reciente: 3 referencias SUNAT globales preparadas (22/10/2026)

La migración F3.1 del perfil económico **n.º 23 YA ESTÁ instalada y aprobada**. La prueba E2E CLI anterior funcionó con rollback, pero el clonado PCGE tardó **45,97 segundos**, por encima de la meta de 25 s; se mantiene bloqueado el reintento persistente desde Vue.

Por nueva solicitud se versionaron los tres archivos oficiales suministrados: **51 tipos de contribuyente (Anexo 2), 417 CIIU Rev. 4 y 38 códigos de tributos (Anexo 10)**, separados del catálogo PLE/SIRE ya existente. Los desplegables Vue, la validación Laravel y las referencias por empresa ya están implementados en `main`; la **migración n.º 24 aún NO está instalada en Neon**.

**Usar ahora [la guía específica de instalación y pruebas de la migración 24](F3-1-CATALOGOS-SUNAT-GLOBALES.md)**. El texto más antiguo de abajo documenta el procedimiento histórico de la n.º 23 y no debe interpretarse como instrucción para volver a ejecutarla.

---

## Diagnóstico comprobado

Neon PG18 `contabilidad-dev` tiene **22 migraciones, 3 empresas ficticias, 3 usuarios, 8 membresías, PCGE 3.401 plantillas y enero 2026 de DEMO A OPEN**. Faltan físicamente las cinco columnas jurídicas/fiscales de `company_settings` y la tabla `company_economic_activities`.

El archivo histórico `backend/database/migrations/2026_10_19_010000_add_company_economic_profile.php` exige el identificador/servidor Supabase y **no corresponde aplicarlo sobre Neon**. El frontend muestra el asistente de cinco pasos, pero la puerta `CompanyOnboardingGuard` impide el POST mientras falte este esquema.

Comprobación externa de solo lectura con el rol `app_backend`: están completos los **13 catálogos globales del manifest versionado**, incluidas 1.764 cuentas PCGE 2019, 1.637 PCGE 2026, 3 monedas y los catálogos SUNAT. No crear seeders duplicados ni modificar esas referencias.

## Cambios publicados

- `backend/database/portable_migrations/2026_10_22_150000_add_company_economic_profile_neon.php`: migración aditiva Laravel, conexión migradora Neon DEV, PG18, SSL y baseline estricto. Cinco columnas de perfil, actividades CIIU con integridad, índices, **FORCE RLS** y permisos de `app_backend`. Mantiene intactas empresas y período.
- `backend/app/Http/Controllers/Api/V1/Tenant/CreateCompanyFromWizardController.php`: el alta de empresa, membresía ADMIN, monedas, perfil, PCGE, áreas y `COMPANY_ONBOARDED` pertenecen a **la misma transacción**. La auditoría dejó de ser un efecto secundario que podría fallar silenciosamente.
- `frontend/src/modules/company/views/CompanyOnboardingWizard.vue`: usa estilos Tailwind válidos (`bg-surface` y `border-border`).
- `backend/scripts/phase3-company-onboarding-preflight.php`: diagnóstico **read-only** de conexión Neon y baseline, antes/después de la migración.
- `backend/scripts/phase3-neon-company-onboarding-http.php`: ensayo HTTP real mediante Sanctum con empresa totalmente ficticia, clonación del PCGE, configuración de monedas/áreas/CIIU, 1 auditoría obligatoria y **ROLLBACK externo**. Conserva las 3 empresas originales y enero OPEN.

## Puerta 1 — comprobar y aplicar únicamente migración 23

Ejecutar en PowerShell local con credenciales privadas válidas y APP_ENV local:

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend

php -l database/portable_migrations/2026_10_22_150000_add_company_economic_profile_neon.php
php -l app/Http/Controllers/Api/V1/Tenant/CreateCompanyFromWizardController.php
php -l scripts/phase3-company-onboarding-preflight.php
php -l scripts/phase3-neon-company-onboarding-http.php

php scripts/phase2-neon-connection-diagnostic.php --check
php scripts/phase3-company-onboarding-preflight.php --check
```

Si el diagnóstico muestra PASS y 22 migraciones con el perfil pendiente, **solo entonces** aplicar la migración revisada (no ejecutar migración general ni `migrate:fresh`):

```powershell
php artisan migrate --database=pgsql_migrations --path=database/portable_migrations/2026_10_22_150000_add_company_economic_profile_neon.php --force --no-interaction
php scripts/phase3-company-onboarding-preflight.php --check
```

Se espera una nueva migración registrada (**23**), cinco columnas y tabla de actividades. El preflight no ejecuta DDL. **La migración confirma cambios en Neon DEV** y no genera empresas nuevas; solo debe aplicarse cuando las guardas previas sean PASS.

## Puerta 2 — ensayar alta E2E reversible antes de crear empresa real

```powershell
php scripts/phase3-neon-company-onboarding-http.php --plan
php scripts/phase3-neon-company-onboarding-http.php --probe=contabilidad-dev
```

Esperado:

- HTTP capability **200** y `can_create=true` para DEMO ADMIN.
- HTTP POST `/api/v1/tenant/companies` **201** con RUC ficticio de prueba válido, configuración SAC / régimen GENERAL y moneda PEN + USD.
- Empresa nueva con ADMIN, CIIU 4752, cuentas PCGE 2019 de seis dígitos y jerarquía, seis áreas y nueve flujos NIC 7.
- Auditoría `COMPANY_ONBOARDED` **obligatoria y atómica**.
- **ROLLBACK** y verificación final: siguen tres empresas, ocho membresías, auditorías originales y enero 2026 OPEN. No se crea una cuarta empresa permanente.

Si alguna comprobación falla, detenerse y compartir únicamente las líneas PASS/FAIL, nunca contraseñas, contenido de .env o consultas con secretos.

## Puerta 3 — validar Vue y aceptar alta desde el navegador

```powershell
cd ..\frontend
pnpm typecheck
pnpm test:unit
pnpm build
```

Entrar con administrador DEMO y abrir `http://localhost:5180/#nueva-empresa`. Comprobar los cinco pasos, errores RUC/CIIU, selección de régimen, PEN/USD, PCGE, resumen y motivo/confirmaciones normativas.

**No confirmar una cuarta empresa DEMO de forma permanente durante esta prueba de interfaz.** Crear una empresa adicional desde el botón final requiere acordar cómo quedarán los scripts antiguos que parten de exactamente tres empresas, o adaptar sus guardas primero. La prueba reversible por HTTP es suficiente para validar el backend sin contaminar la rama DEV.

## Incidencia HTTP 500 en creación real — diagnóstico y corrección del 22/10/2026

**Evidencia:** migración 23 instalada correctamente y ensayo F3.1 HTTP reversible **PASS** (ADMIN, PCGE, CIIU, monedas, 6 áreas y 9 flujos, auditoría, rollback). El usuario intentó crear una empresa EIRL con RMT y actividad de transporte en Vue: el navegador registró `POST /api/v1/tenant/companies` → HTTP **500** tras **30,18 s**, y PHP mostró `Maximum execution time of 30 seconds exceeded`.

**Consulta directa a Neon (solo SELECT) inmediatamente después del error:** no existe la empresa solicitada por nombre/RUC; siguen 3 empresas, 23 migraciones, 3 `company_settings`, 0 auditorías `COMPANY_ONBOARDED` y enero 2026 de DEMO A intacto. No se debe reintentar el mismo POST sin validar la corrección.

**Posible cuello de botella:** `InitializeCompanyChartOfAccountsService` clonaba por lotes de 200 filas, con consultas repetidas desde Laravel al endpoint directo de Neon, además del trigger obligatorio de jerarquía y RLS. El test CLI no tiene el mismo límite web de 30 segundos, por lo que un PASS funcional anterior no demuestra tiempo aceptable en navegador.

**Cambio publicado en `main`, pendiente prueba manual:**

- La ruta de alta llama ahora al servicio PCGE en modo **`fastCopy`**. Construye la jerarquía idéntica y envía **un INSERT JSONB por nivel**, unido a la cuenta padre previamente insertada. No desactiva el trigger de integridad, `FORCE RLS`, el control de cuentas operativas a seis dígitos ni la transacción general. El método legacy queda sin alteración funcional para sus otros consumidores.
- En el controlador Laravel se amplía temporalmente a 120 s el límite de ejecución de **esta operación de alta únicamente** y se restaura al finalizar; es una red de seguridad, no reemplaza medir/optimizar la consulta.
- El ensayo `phase3-neon-company-onboarding-http.php` utiliza ahora un perfil ficticio **EIRL / MYPE_TRIBUTARIO / transporte CIIU 4923**, reporta segundos reales del POST y alerta si excede 25 s; termina en ROLLBACK y comprueba el estado original de Neon.
- Vue explica la copia PCGE durante el envío y trata un 500 como resultado no confirmado, para evitar duplicados por reintentos ciegos.

**Puerta obligatoria antes de reintentar en navegador:**

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend

php -l app/Services/Accounting/InitializeCompanyChartOfAccountsService.php
php -l app/Http/Controllers/Api/V1/Tenant/CreateCompanyFromWizardController.php
php -l scripts/phase3-neon-company-onboarding-http.php

php scripts/phase3-company-onboarding-preflight.php --check
php scripts/phase3-neon-company-onboarding-http.php --probe=contabilidad-dev

cd ..\frontend
pnpm typecheck
pnpm build
```

La prueba debe terminar con HTTP 201, integridad PCGE y auditoría, ROLLBACK y confirmación de que solo siguen tres empresas. **Registrar la métrica `[METRICA] F3.1 POST...` para determinar si la optimización es suficiente**. Si supera 25 s, profundizar en triggers/perfil de base antes de realizar un alta persistente. Una vez aprobado, reiniciar `php artisan serve` para cargar el código actualizado y reabrir el borrador de la pestaña. No regenerar migraciones ni eliminar registros.

---

## Resultado esperado / estado

La actividad F3.1 sigue **pendiente** hasta recibir los PASS reales de migración y E2E, comprobar la interfaz y la pertenencia de la empresa tras el alta. No declarar 23 migraciones instaladas antes de la salida real de Artisan.

[Plan maestro](../README-PLAN-MAESTRO.md) · [F2.3 períodos cerrada](F2-3-CIERRE-PERIODOS.md).
