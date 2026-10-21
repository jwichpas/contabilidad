# Crear empresa B de pruebas a través de Laravel (Supabase Dev)

La migración excepcional `backend/database/migrations/2026_10_20_235900_create_dev_company_b_via_laravel.php` utiliza **modelos, el guard de onboarding, TenantContext y el servicio Laravel oficial de inicialización del PCGE**. No ejecuta INSERT ad hoc desde un panel SQL, no inventa usuarios y no modifica la empresa existente.

**Requisitos:** `APP_ENV=local`, `pgsql` dirigido a `app_backend` mediante el pooler de Supabase Dev, conexión de migraciones separada `pgsql_migrations`, un usuario administrador activo de la empresa existente, monedas PEN/USD y plantillas PCGE 2019 cargadas. RUC ficticio con dígito verificador: `20999999990`. Empresa: NEXUS EMPRESA PRUEBA B S.A.C.

Después de actualizar el repositorio, desde `backend`:

```powershell
php -l database/migrations/2026_10_20_235900_create_dev_company_b_via_laravel.php
php artisan migrate:status --database=pgsql_migrations
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_20_235900_create_dev_company_b_via_laravel.php --force
```

**Atención:** el último comando **sí crea datos persistentes de desarrollo** en Supabase. Ejecutarlo solo si la conexión administrativa de migraciones está configurada correctamente y el usuario desea crear la empresa B. No ejecutar `migrate:fresh`, `db:wipe`, `migrate:rollback` ni el conjunto completo de migraciones a ciegas.

La operación de datos corre dentro de una única transacción `pgsql` restringida como `app_backend`: compañía, membresía ADMIN del administrador existente, parámetros tributarios, PEN/USD, actividad comercial CIIU 4752 y copia PCGE 2019. Un error revierte los datos de esta operación. La migración administrativa deja el registro en `migrations` al completarse. El método `down()` no elimina empresas para evitar borrar posteriores asientos o pruebas.

Después, ejecutar:

```powershell
php scripts/phase1-rls-cross-company-readonly.php
```

Nota: esta es una **migración de datos extraordinaria para desarrollo**, no una migración de esquema ni una forma general de dar de alta empresas en producción. En producción debe utilizarse siempre el wizard/API de onboarding autenticado.
