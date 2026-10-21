# Neon PostgreSQL 18: integración de desarrollo sin modificar Supabase

**21/10/2026 — F1.6.** Neon pasa a ser la referencia remota para pruebas del ERP. Supabase (PostgreSQL 17.11) **se conserva intacto**. El núcleo contable se desarrolla para PostgreSQL 18 y Laravel 13 con PHP 8.5.

## Qué sabemos y qué NO está confirmado

En la captura se ve la rama Neon `production`, base `neondb`, propietario `neondb_owner`, región `sa-east-1` y una cadena de conexión con `-pooler`. **La captura no demuestra la versión PostgreSQL del proyecto Neon**, ni prueba que exista la cuenta restringida `app_backend`. No hay ninguna conexión Neon ejecutada aún en este flujo.

**No utilizar la rama `production` para el bootstrap inicial ni para tests que hagan escrituras**, aunque el proyecto solo contenga datos ficticios. En la consola de Neon, seleccionar Branches → New branch, elegir nombre p. ej. `contabilidad-dev`, crearla y obtener desde Connect los hosts de ESA rama. Neon aloja el esquema y datos de ramas separadamente.

## Separación de privilegios y conexiones

| Componente | Neon y rol | Motivo |
|---|---|---|
| `DB_HOST` | Host de la rama de PRUEBAS con `-pooler`, puerto 5432 | Consultas Laravel normales |
| `DB_USERNAME` | `app_backend` | Rol restringido que se creará mediante SQL y no tendrá BYPASSRLS |
| `DB_MIGRATIONS_HOST` | Host DIRECTO de la misma rama (sin `-pooler`), puerto 5432 | DDL y migraciones |
| `DB_MIGRATIONS_USERNAME` | `neondb_owner` | Administrador propietario, **no** tráfico de usuarios |
| `DB_MIGRATIONS_URL`, `DB_URL`, `DATABASE_URL` | Vacías en este proyecto | Evita confusiones entre URL con parámetros SSL y variables individuales |
| `DB_MIGRATIONS_SSLMODE` y `DB_SSLMODE` | `require` | TLS requerido |
| `NEON_TEST_BRANCH_NAME` | `contabilidad-dev` (el nombre REAL de la rama de pruebas) | Bloqueo de configuración |
| `NEON_TEST_BRANCH_HOST` | Host DIRECTO, igual a `DB_MIGRATIONS_HOST` | Vincula rama declarada con endpoint |

El host de `production` capturado tiene prefijo `ep-ancient-brook-b6llhj4d` y está explícitamente **denegado** en las pruebas de preparación del repositorio. No copiar ese endpoint para inicializar Neon. Las variables de nombre de rama son una declaración local: **PostgreSQL no certifica el nombre de la rama Neon mediante SQL**, por lo que debe cotejarse visualmente con Connect en Neon.

Neon otorga `neon_superuser` al rol creado desde Console/API/CLI. Para `app_backend` necesitamos **CREATE ROLE desde SQL y permisos mínimos**, no crearlo mediante el botón de la consola. El esquema inicial ya incluye este rol y FORCE RLS, con una contraseña explícita aleatoria de al menos 20 caracteres para evitar valores predecibles. La contraseña del propietario debe permanecer privada.

## Paso 1 — Configurar .env sin sobrescribir credenciales

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
notepad .env
```

Mantener `APP_ENV=local`. Cambiar los valores del ejemplo que provienen de la rama `production` por los dos hosts de `contabilidad-dev` y poner las contraseñas en el `.env` privado. Definir `DB_APP_BACKEND_ROLE_PASSWORD` de manera aleatoria y fuerte. **No imprimir, compartir ni subir .env**.

## Paso 2 — Diagnóstico sin SQL ni migraciones

```powershell
php -l scripts/phase1-neon-preflight.php
php scripts/phase1-neon-preflight.php
```

El preflight exige conexión directa y pooled de una misma rama, rol `neondb_owner` solo en la conexión de migraciones, `app_backend` solo para el tráfico, SSL, credenciales, rama de pruebas y ausencia de URLs conflictivas. No abre conexiones en su modo predeterminado.

## Paso 3 — Comprobación de solo lectura del servidor

```powershell
php scripts/phase1-neon-preflight.php --read-only
php scripts/phase1-neon-preflight.php --read-only --require-empty
```

La primera consulta únicamente metadatos del servidor (versión, usuario, `neondb`, roles, cantidad de tablas en `public`). La segunda exige **cero tablas de usuario preexistentes en `public`**, como puerta previa para un bootstrap inicial nuevo. **No ejecuta INSERT/UPDATE/DELETE/DDL, seeds ni migraciones.**

Si Neon entrega PostgreSQL 17 en vez de 18, el preflight fallará deliberadamente: PostgreSQL 18 es la referencia acordada. Corregir la configuración o elegir un proyecto/servicio compatible, sin adaptar el chequeo a ciegas.

## Etapa posterior — Migraciones controladas

Solo después de verificar una rama nueva vacía y el preflight PASS se preparará/ejecutará un bootstrap explícito de migraciones portables. **No ejecutar `php artisan migrate` general**, `migrate:fresh`, ni las cinco migraciones ligadas al proyecto Supabase. Las dos migraciones F1.4 ahora aceptan una rama Neon de pruebas SOLO si el host no es el capturado en `production` y coinciden rama declarada, propietario, TLS y entorno `local`. No están ejecutadas en Neon.

Las pruebas de escritura A/B siguen priorizando Docker PostgreSQL 18 efímero hasta que el esquema Neon esté correctamente inicializado. No copiar datos existentes de Supabase ni eliminar sus recursos.

## Referencias de proveedor

- https://neon.com/docs/manage/branches
- https://neon.com/docs/manage/roles
- https://neon.com/docs/connect/connection-pooling
- https://neon.com/docs/manage/database-access


## F1.6 — Rama remota creada y verificada (21/10/2026)

Se conectó el plugin Neon y se inspeccionó el proyecto real **contabilidad-database**, id `soft-bonus-52215969`, PostgreSQL **18**. Se identificó como única rama anterior `production` (`br-silent-leaf-b6zzefdl`), con **0 tablas de aplicación** y rol `neondb_owner`. **No se escribió nada en production.**

Se creó **contabilidad-dev**, id `br-odd-lake-b6wshqv3`, desde la rama principal. Su endpoint independiente quedó activo. Una consulta `SELECT` **dirigida expresamente a esa rama** confirmó: PostgreSQL **18.6**, base `neondb`, usuario `neondb_owner`, **0 tablas de aplicación**, `app_backend` ausente y rol interno `neon_superuser` presente. No se ejecutó DML/DDL en la rama nueva.

Hosts públicos (sin credenciales) devueltos por Neon:

- Directo, administración y aplicación mientras no haya pooling: `ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech`.
- Host pooled anunciado, **pero pooling de la rama actualmente está desactivado**: `ep-sweet-king-b6hxra2r-pooler.c-2.sa-east-1.aws.neon.tech`. **No usarlo aún.**

`backend/.env.example` ahora activa `DB_HOST` y `DB_MIGRATIONS_HOST` **directos** de contabilidad-dev, `NEON_TEST_BRANCH_NAME=contabilidad-dev`, `NEON_TEST_BRANCH_HOST` igual al directo, `NEON_USE_POOLING=false`, `DB_EMULATE_PREPARES=false`, `DB_USERNAME=app_backend` y `DB_MIGRATIONS_USERNAME=neondb_owner`. Conexiones Supabase conservadas como comentarios. Neon `production` permanece intacto.

El preflight `phase1-neon-preflight.php` ahora admite **conexión directa sin pooling** con prepares nativos, o modo pooled solamente si se cambia `NEON_USE_POOLING=true`, `DB_HOST` al host con sufijo `-pooler` y `DB_EMULATE_PREPARES=true`, siempre después de activar realmente pooling en Neon. **Aún no hay credenciales locales verificadas ni ejecución de PHP preflight desde Windows**; la lectura Neon conectada es un control adicional, no reemplaza el preflight Laravel.

**Próximo paso antes del bootstrap:** sincronizar main y completar en `backend/.env` privado `DB_MIGRATIONS_PASSWORD`, `DB_PASSWORD` y una contraseña aleatoria fuerte `DB_APP_BACKEND_ROLE_PASSWORD`; crear y ejecutar solo cuando pase `php scripts/phase1-neon-preflight.php` y `--read-only --require-empty`. La creación de `app_backend`, migraciones Laravel y DDL esperan validación explícita; no están aplicadas.
