# Política de base de datos — Laravel como único gestor de esquema

> **Proyecto:** `jwichpas/contabilidad` · **Base única de desarrollo:** Supabase `contabilidad-database` (`bnjwxphwtkhblvhsnylj`) · **Rama:** `main`  
> **Vigente desde:** 2026-10-19 · **Referente:** [README-PLAN-MAESTRO.md](../README-PLAN-MAESTRO.md), fases 0–8.

## 1. Decisión de arquitectura (obligatoria)

**Toda creación o modificación estructural de PostgreSQL debe quedar implementada y versionada mediante migraciones de Laravel** en `backend/database/migrations/`. Esto incluye:

- Crear, modificar o eliminar tablas, columnas, constraints, índices, secuencias y claves foráneas.
- Activar o ajustar RLS; definir políticas, privilegios, roles de aplicación o funciones SQL.
- Triggers, vistas y funciones de integridad contable.
- Cambios de esquema relacionados con catálogos SUNAT, PCGE, NIIF o Libro Diario.

**Supabase no es un segundo administrador de esquema.** El proyecto Supabase actúa como PostgreSQL administrado, herramienta de observación (SQL de solo lectura, advisories, catálogos, logs) y servicios de plataforma cuando sean requeridos. No ejecutar desde Supabase SQL DDL/DML de cambios funcionales que omita las migraciones Laravel, ni usar `apply_migration` nativo de Supabase como sustituto de Artisan.

**Datos de referencia:** cargarlos o modificarlos a través de seeders/comandos Laravel versionados, revisando idempotencia y no sobrescritura de datos existentes. Operaciones de aplicación por API Laravel y servicios de dominio.

**La única base hasta completar el desarrollo** es `contabilidad-database`: no crear otra base o rama sin petición expresa.

## 2. Conexiones Laravel

El backend posee **dos conexiones apuntando a la misma base PostgreSQL** con privilegios distintos.

| Conexión | Uso | Perfil |
|---|---|---|
| `pgsql` | API, usuarios, consultas y POST contable | Rol `app_backend`, sujeto a RLS |
| `pgsql_migrations` | Artisan migraciones y diagnóstico administrativo | Rol de migración con privilegios DDL, configurado mediante `DB_MIGRATIONS_*` |

`backend/config/database.php` **no debe hacer que `pgsql_migrations` herede el `DATABASE_URL` de la conexión normal**. Se corrigió la configuración para aceptar solamente `DB_MIGRATIONS_URL` si se proporciona una URL administrativa independiente, o `DB_MIGRATIONS_HOST/PORT/DATABASE/USERNAME/PASSWORD` en otro caso.

**Protección:** ninguna contraseña, token, URI con contraseña ni secreto debe registrarse en GitHub, `phpunit.xml`, logs o documentación. Usar `backend/.env` no versionado o variables seguras del entorno. La contraseña expuesta anteriormente requiere **rotación**; retirarla del archivo actual no invalida su uso ni su presencia histórica.

## 3. Proceso de desarrollo de un cambio de esquema

1. Revisar el **README maestro**, fase y actividad afectados, riesgos, datos actuales y dependencia con otras tablas.
2. Crear **un archivo de migración nuevo**, sin reescribir una migración ya aplicada:

   ```bash
   cd backend
   php artisan make:migration nombre_descriptivo_del_cambio
   ```

3. Implementar `up()` y `down()` de Laravel. Usar `Schema::create`/`Schema::table` y `DB::statement` **dentro de la migración Laravel** para SQL PostgreSQL específico (RLS, trigger, constraint, etc.).
4. En la base única de desarrollo, comprobar antes de aplicar:

   ```bash
   php artisan migrate:status --database=pgsql_migrations
   php artisan contabilidad:auditar-fase0 --json
   ```

5. Revisar respaldo/reversión, comportamiento multiempresa, seguridad, índices, compatibilidad con datos existentes y pruebas.
6. Ejecutar la migración pendiente **desde Laravel** con la conexión administrativa apropiada:

   ```bash
   php artisan migrate --database=pgsql_migrations
   ```

7. Comprobar mediante el comando Artisan, pruebas Laravel y consultas `SELECT` de Supabase que el esquema y el comportamiento son correctos. Guardar evidencia y commit en `main`.
8. Actualizar el estado del plan maestro **solo si se aprobaron las pruebas**.

**No ejecutar** sobre la base de desarrollo `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`, `DROP ... CASCADE`, truncados ni seeders que borren o sobrescriban registros existentes. No suponer que `down()` es inocuo: evaluar impacto antes de aplicar un rollback.

## 4. Fase 0 — Diagnóstico de solo lectura

Se agregó `backend/app/Console/Commands/AuditPhaseZero.php`, que Laravel 11 descubre como comando Artisan:

```bash
cd backend
php artisan contabilidad:auditar-fase0
php artisan contabilidad:auditar-fase0 --json
```

El comando conecta a `pgsql_migrations` solo para ejecutar consultas `SELECT` que cuentan tablas, RLS, índices, funciones, triggers, políticas, historial de migraciones y grants de lectura de `anon`. También inspecciona la cantidad de asientos, líneas, descuadres y relaciones huérfanas. **No migra ni inserta ni modifica tablas.** El comando está diseñado para F0; su existencia no demuestra que Laravel haya pasado las pruebas de ejecución.

**Resultados SQL comprobados previamente** en Supabase: 31 tablas; 18 sin RLS; 94 índices; 5 funciones; 6 triggers propios; 14 políticas; 14 migraciones Laravel; 2 asientos, 4 líneas y ningún descuadre en esos 2 asientos. Ver [informe de Fase 0](FASE-0-DIAGNOSTICO.md).

## 5. Pruebas sobre la única base de desarrollo

- `backend/phpunit.xml` no debe fijar host, usuario privilegiado ni contraseña. Estos datos se obtienen de la configuración Laravel/entorno **para esta misma base**.
- Los tests Pest que usan `DatabaseTransactions` deben revisarse individualmente; rollback no garantiza aislamiento de operaciones SQL de sesión, llamadas externas, DDL o efectos fuera de la transacción.
- Antes de correr una suite, verificar credenciales y conexión, revisar que no haya `RefreshDatabase` destructivo ni migraciones desde tests, y tomar conteos de referencia.
- Comparar los registros al terminar y documentar anomalías. No ejecutar suites sin garantía razonable de preservación de datos.
- **GitHub Actions F0:** se agregó un workflow de análisis estático sin conexión a la base. Primera ejecución bloqueada por ausencia de runner; solo `workflow_dispatch` mientras se resuelve. No usar resultados de un workflow sin pasos ejecutados como evidencia de compilación exitosa.

## 6. Evidencias y gobierno

- Plan maestro: [README-PLAN-MAESTRO.md](../README-PLAN-MAESTRO.md)
- Línea base/pendientes: [FASE-0-DIAGNOSTICO.md](FASE-0-DIAGNOSTICO.md)
- Código de conexiones: [config/database.php](../backend/config/database.php)
- Migraciones: [database/migrations](../backend/database/migrations)
- Validación read-only: [AuditPhaseZero.php](../backend/app/Console/Commands/AuditPhaseZero.php)
- Pruebas: [tests/Feature](../backend/tests/Feature)
- CI: [phase0-static-baseline.yml](../.github/workflows/phase0-static-baseline.yml)

**Situación actual:** F0 EN PROGRESO; ninguna fase aprobada. La política entra en vigor desde su commit, sin alterar el esquema de Supabase.

Fuentes de referencia: [Laravel 11 — migraciones](https://laravel.com/docs/11.x/migrations), [Laravel 11 — Artisan](https://laravel.com/docs/11.x/artisan).

## 7. Procedimiento de preflight y respaldo sin segunda base

**Sin conexión a PostgreSQL (puede ejecutarse antes de configurar secretos):**

```bash
php backend/scripts/phase0-preflight.php
php backend/scripts/phase0-preflight.php --json
```

**Preparación de respaldo de la única base de desarrollo:** en una consola con `pg_dump` y `pg_restore` de una versión compatible con PostgreSQL 17, exportar `DB_MIGRATIONS_HOST`, `DB_MIGRATIONS_USERNAME`, `DB_MIGRATIONS_DATABASE`, `DB_MIGRATIONS_PORT` y `DB_MIGRATIONS_PASSWORD` mediante variables del entorno seguro, **sin escribir la contraseña en este repositorio**.

```bash
# La exportación lee la base, no modifica las tablas y no crea otra base.
bash backend/scripts/phase0-backup.sh
```

El archivo de respaldo `.dump`, su catálogo `.list` y su huella `.sha256` se generan bajo `~/contabilidad-backups` por defecto, **fuera del repositorio**, con permisos restrictivos. El archivo puede contener datos confidenciales de la empresa: no subirlo a GitHub, chats o CI.

`pg_restore --list` y SHA-256 verifican legibilidad básica e integridad del archivo, **no demuestran recuperabilidad completa**. Dado que el usuario decidió no crear segunda BD, cualquier prueba futura de restauración deberá planificarse expresamente sin sobrescribir ni destruir el entorno activo. Hasta entonces F0.1 seguirá abierta.

**Sin modificar tablas en Supabase:** también está disponible `php artisan contabilidad:auditar-fase0 --json` desde Laravel, conectado a `pgsql_migrations`, protegido mediante `SET TRANSACTION READ ONLY`.
