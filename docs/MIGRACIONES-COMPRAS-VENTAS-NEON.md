# Migraciones Compras/Ventas en Neon DEV — procedimiento seguro

## Diagnóstico

Si `php artisan migrate` responde `SQLSTATE[42501]: permission denied for table migrations`, Laravel ha usado la conexión normal `pgsql` (rol restringido `app_backend`), no la conexión administrativa `pgsql_migrations`. La tabla de historial de migraciones **no** debe conceder privilegios DDL al usuario de tráfico.

El archivo `backend/config/database.php` distingue:
- `pgsql`: petición normal, `DB_USERNAME=app_backend`, FORCE RLS.
- `pgsql_migrations`: esquema/DDL, `DB_MIGRATIONS_USERNAME=neondb_owner`, uso temporal y explícito.

**Jamás** sustituir el rol de aplicación por `neondb_owner`, ni ejecutar `migrate:fresh`, `migrate:reset` o `migrate:refresh` sobre Neon. No ampliar permisos `app_backend` a la tabla `migrations` para ocultar este error.

## 1. Preparación (sin escrituras)

Desde `C:\Proyectos\contabilidad\backend`:

```powershell
php artisan config:clear
php scripts/phase1-neon-preflight.php
php scripts/phase1-neon-preflight.php --read-only
```

El preflight comprueba configuración del entorno y en modo `--read-only` consulta catálogos, sin alterar datos. Confirme **también en la consola Neon** que el host de `DB_MIGRATIONS_HOST` pertenece a la rama de pruebas `contabilidad-dev` y **no** a `production`. El nombre de rama declarado en `.env` no constituye una prueba de la rama real remota.

En `backend/.env` privado deben existir las variables correspondientes a las dos conexiones. Solo mostrar nombres de variables (jamás contraseñas ni URL completas):

```dotenv
DB_CONNECTION=pgsql
DB_USERNAME=app_backend
DB_URL=
DATABASE_URL=
DB_MIGRATIONS_URL=
DB_MIGRATIONS_USERNAME=neondb_owner
DB_MIGRATIONS_HOST=<host directo de la rama de pruebas>
DB_MIGRATIONS_DATABASE=neondb
DB_MIGRATIONS_SSLMODE=require
DB_MIGRATIONS_PASSWORD=<secreto privado, nunca compartir>
```

Si el preflight falla, **detenerse y reparar la causa**. Verificar que existe un respaldo/snapshot o rama de Neon recuperable antes de cualquier DDL.

## 2. Verificar el historial con el propietario (solo lectura)

```powershell
php artisan migrate:status --database=pgsql_migrations
```

La primera migración crea `company_document_journal_settings` y `accounting_document_entries`. La segunda añade el registro tributario interno y sus clasificaciones. Revisar si ambas figuran pendientes. No reaplicar tablas que existan por otra instalación: primero reconciliar esquema e historial.

### Plan de ejecución sin escrituras (opcional)

```powershell
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_11_12_000000_create_document_journal_modules.php --pretend
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_11_12_000001_create_tax_registers_and_allocations.php --pretend
```

**No ejecutar los comandos reales** hasta confirmar todos los pasos, que las migraciones anteriores estén al día y que el entorno sea efectivamente una rama de pruebas. Las comprobaciones de CI con PHP/TypeScript no equivalen a las pruebas de integración PostgreSQL.

## 3. Aplicación controlada: SOLO en rama de pruebas confirmada

```powershell
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_11_12_000000_create_document_journal_modules.php
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_11_12_000001_create_tax_registers_and_allocations.php
php artisan migrate:status --database=pgsql_migrations
php artisan optimize:clear
```

Si aparecen errores por constraints, políticas, ownership o columnas preexistentes, **detenerse** y comprobar el estado de la transacción antes de reintentar. No conceder permisos globales como solución.

## 4. Comprobación de funcionamiento

- Reiniciar backend para que tome `.env` y configuración actualizados.
- Cargar Registro de Compras y Registro de Ventas con usuario normal `app_backend`.
- Confirmar acceso a sus ajustes y consulta tributaria, sin escritura de prueba irreversible en datos reales.
- Validar con pruebas PostgreSQL con RLS que no haya filtraciones entre empresas.

**Estado del PR:** la migración y las pruebas de integración en Neon DEV no se ejecutan desde GitHub. No se fusionará a `main` antes de la verificación manual.
