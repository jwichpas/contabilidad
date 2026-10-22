# F3.1 · Catálogos globales SUNAT RUC y CIIU Rev. 4 (24.ª migración)

**22/10/2026 — Código publicado en `main`, migración 24 PENDIENTE DE INSTALACIÓN Y PRUEBA MANUAL.**

## Fuentes incorporadas

Los tres documentos adjuntos se convirtieron a JSON versionado y están retenidos bajo `backend/database/reference_data/`:

| Fuente suministrada | Archivo retenido | Filas | Tabla PostgreSQL |
|---|---|---:|---|
| SUNAT RUC, Tabla Anexa Nº 2 — Tipo de contribuyente | `sunat_contributor_types_2026_10_22.json` | 51 | `sunat_ruc_contributor_types` |
| CIIU Rev. 4, tabla oficial descrita en el adjunto | `sunat_ciiu_rev4_2026_10_22.json` | 417 | `sunat_ciiu_rev4_classes` |
| SUNAT RUC, Tabla Anexa Nº 10 — Codificación de tributos | `sunat_tax_codes_2026_10_22.json` | 38 | `sunat_ruc_tax_codes` |

**No se mezclan** con las tablas numéricas 02/10 de SUNAT usadas para libros electrónicos PLE/SIRE o comprobantes electrónicos: la Tabla Anexa 2 es **tipo de contribuyente**, no tipo de documento de identidad; la Tabla Anexa 10 es **códigos tributarios**, no tipos de comprobantes.

Estas referencias representan la **versión de los archivos suministrados**, no una consulta en línea al padrón RUC ni garantía de vigencia automática. No crean obligaciones, tasas ni actividades inscritas en SUNAT: el contador debe confirmar los datos reales del contribuyente. Algunas listas de tributos publicadas en SUNAT son extractos y pueden existir códigos adicionales: **38 = 38 registros recibidos, no necesariamente todo el universo SUNAT**.

## Implementación

- Migración portable aditiva Laravel: `backend/database/portable_migrations/2026_10_22_160000_install_sunat_ruc_reference_catalogs.php`. Incluye datos globales con permisos `SELECT` para `app_backend` pero sin `INSERT/UPDATE/DELETE` al rol API; versión y SHA-256 en `sunat_ruc_reference_versions`.
- `company_settings.sunat_contributor_type_code` almacena el código del Anexo 2; FK a catálogo global.
- `company_economic_activities.ciiu_code` referencia el CIIU vigente en el snapshot; el servidor sustituye la descripción libre por la descripción canónica del catálogo al dar de alta.
- `company_tax_codes`: códigos del Anexo 10 **seleccionados**, separados por empresa, con FK, UNIQUE(company, código), **FORCE RLS** y permisos de `app_backend`. No materializar una tasa de IGV/IR a partir de un código.
- API autenticada: `GET /api/v1/catalogs/sunat-ruc/contributor-types`, `/ciiu`, `/tax-codes`; puede añadirse `?q=texto`. Estos endpoints globales no requieren cambio de empresa.
- El asistente `#nueva-empresa` ahora incluye tipo de contribuyente filtrado por forma jurídica cuando existe correspondencia directa, búsqueda por texto/código CIIU y multiselección de tributos. La descripción CIIU es de solo lectura, y la validación real también la realiza Laravel (`422` para códigos ausentes).

## Aplicación controlada de migración 24

Neon PG18 DEV ya contiene **23 migraciones y las 3 empresas DEMO**, con enero 2026 de DEMO A `OPEN`. La migración 24 sigue pendiente; ejecutar **solo** tras comprobar PHP/diagnóstico local:

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend

php -l database/portable_migrations/2026_10_22_160000_install_sunat_ruc_reference_catalogs.php
php -l app/Http/Controllers/Api/V1/Catalogs/ListSunatRucReferenceController.php
php -l app/Http/Controllers/Api/V1/Tenant/CreateCompanyFromWizardController.php
php -l scripts/phase3-company-onboarding-preflight.php
php -l scripts/phase3-neon-company-onboarding-http.php

php scripts/phase2-neon-connection-diagnostic.php --check
php scripts/phase3-company-onboarding-preflight.php --check
```

El preflight debe indicar **23 migraciones y catálogos RUC aún pendientes**, no cambios inesperados. Entonces:

```powershell
php artisan migrate --database=pgsql_migrations --path=database/portable_migrations/2026_10_22_160000_install_sunat_ruc_reference_catalogs.php --force --no-interaction
php scripts/phase3-company-onboarding-preflight.php --check
php artisan route:list --path=api/v1/catalogs/sunat-ruc
```

Resultado deseado: **24 migraciones, catálogos 51/417/38, 3 versiones con SHA-256, 3 empresas, 0 cambios a enero de DEMO A**. El preflight de la versión 24 compara el hash SHA-256 del contenido actual del repositorio contra el hash instalado en Neon.

No ejecutar `migrate:fresh`, seeders globales sin versión, `migrate` sin `--path` ni SQL manual sobre las tablas.

## Aceptación y no duplicación

```powershell
php artisan test --filter=SunatRucReferenceDataTest
php scripts/phase3-neon-company-onboarding-http.php --probe=contabilidad-dev

cd ..\frontend
pnpm typecheck
pnpm test:unit
pnpm build
```

El ensayo HTTP reversible valida GET 51/417/38, rechazos HTTP 422 (tipo, CIIU y tributo inexistentes), creación EIRL/RMT temporal con tipo 07, tributos 1011/3031, CIIU 4923, PCGE, auditoría y `ROLLBACK`. Registra el tiempo total de clonado.

**Rendimiento PENDIENTE:** el último ensayo anterior al cambio de catálogos tardó **45,97 s** y aún está por encima del objetivo de 25 s; no repetir la creación real desde el navegador hasta investigar y resolver esta latencia. La actualización de catálogos no se presenta como solución al rendimiento.

[F3.1](F3-1-ALTA-EMPRESAS-NEON.md) · [Plan maestro](../README-PLAN-MAESTRO.md)
