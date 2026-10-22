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


## F1.6 — Reparación del .env parcial tras el primer preflight (21/10/2026)

El usuario ejecutó `php scripts/phase1-neon-preflight.php`: el comando reconoció que `backend/.env` existe y `NEON_TEST_BRANCH_NAME=contabilidad-dev`, pero encontró `APP_ENV` vacío, `DB_HOST` y `DB_MIGRATIONS_HOST` vacíos, puerto/base/usuarios/SSL no configurados y contraseñas ausentes. **No se abrió conexión ni se ejecutaron migraciones o DDL**. No adjudicar el bloqueo al motor PG18 ni a Neon; es configuración local incompleta o sobrescrita por variables de proceso.

Se añadió `backend/scripts/phase1-neon-env-repair.ps1`. Funciona en PowerShell 5.1; su ejecución predeterminada es un **dry run**, sin escribir archivos. La opción `-Apply` guarda respaldo ignorado por Git `backend/.env.backup.*` y agrega/completa **solo** las claves públicas ausentes o vacías de `backend/.env.example`: host directo de contabilidad-dev, DB_CONNECTION=pgsql, APP_ENV=local, puerto/base/usuarios/SSL, banderas de pooling y rama. **Nunca sobreescribe valores no vacíos** ni modifica APP_KEY, DB_PASSWORD, DB_MIGRATIONS_PASSWORD o DB_APP_BACKEND_ROLE_PASSWORD. Si `.env` no existe, copia completa la plantilla. No muestra secretos ni conecta a Neon/Supabase.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
$tokens=$null; $errors=$null
[System.Management.Automation.Language.Parser]::ParseFile(
  (Resolve-Path .\scripts\phase1-neon-env-repair.ps1).Path,
  [ref]$tokens,[ref]$errors
) | Out-Null
if ($errors.Count) { $errors | Format-List Message,Extent; throw 'ParserError' }
.\scripts\phase1-neon-env-repair.ps1
.\scripts\phase1-neon-env-repair.ps1 -Apply
```

Después completar PRIVADAMENTE con el panel Neon: `DB_MIGRATIONS_PASSWORD` del propietario `neondb_owner`; `DB_APP_BACKEND_ROLE_PASSWORD` aleatoria de al menos 20 caracteres y la misma contraseña temporal en `DB_PASSWORD` para el rol `app_backend` que aún no existe. No pegar passwords en el chat ni commitear `.env`.

Reejecutar `php scripts/phase1-neon-preflight.php`. Si pasa, `--read-only --require-empty` es la siguiente puerta; hasta entonces, **no ejecutar migrate**. Si el script avisa de variables de proceso configuradas, inspeccionar también esa precedencia antes de tocar los archivos.


## F1.6 — Segundo preflight: .env todavia apunta a Supabase/production (21/10/2026)

El usuario adjunto un extracto de su `backend/.env` (sin contrasenas). Se identificaron **dos problemas diferentes**:

1. Valores NO SECRETOS antiguos todavia activos: `DB_HOST=aws-1-ca-central-1.pooler.supabase.com`, `DB_PORT=6543`, `DB_DATABASE=postgres`, `DB_USERNAME=app_backend.bnjwxphwtkhblvhsnylj`, `DB_EMULATE_PREPARES=true`; `DB_MIGRATIONS_HOST=ep-ancient-brook-b6llhj4d.c-2.sa-east-1.aws.neon.tech` corresponde a Neon **production**, y `NEON_TEST_BRANCH_HOST=HOST_DIRECTO_DE_LA_RAMA` es un marcador provisional. **No hacer consultas ni migraciones contra esas conexiones**.
2. El preflight devolvio `APP_ENV` vacio, `DB_HOST` vacio y `DB_MIGRATIONS_HOST` vacio incluso existiendo parametros en el archivo. Esto requiere descartar configuracion Laravel sin cargar o variables de entorno de PowerShell/proceso que sobrescriban `.env`. **No diagnosticar solo cambiando hosts**.

Se mejoro `backend/scripts/phase1-neon-env-repair.ps1` con allowlist exacta de valores conocidos de Supabase y Neon production. Su modo sin `-Apply` no modifica archivos. Con `-Apply` crea backup privado y reemplaza solo parametros no secretos vacios, ausentes o iguales a un valor antiguo reconocido. El host de migraciones y aplicacion sera `ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech`, PostgreSQL 18.6 rama `contabilidad-dev`, pooling desactivado. Se conserva cualquier otro valor personalizado y se mantienen sin cambios las contrasenas y `APP_KEY`. No modificar Supabase ni Neon production.

`phase1-neon-preflight.php` ahora muestra exclusivamente indicadores sin secretos: si el repositorio de configuracion Laravel contiene los nodos DB, si `env()` ve los dos hosts, y **nombres** de variables heredadas del proceso que puedan prevalecer sobre `.env`. Ademas exige `DB_PASSWORD` no vacio e igualdad criptografica con `DB_APP_BACKEND_ROLE_PASSWORD` cuando esta configurada para bootstrap; no imprime ninguna.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
$tokens=$null; $parseErrors=$null
[System.Management.Automation.Language.Parser]::ParseFile(
  (Resolve-Path .\scripts\phase1-neon-env-repair.ps1).Path,
  [ref]$tokens,[ref]$parseErrors
) | Out-Null
if ($parseErrors.Count -gt 0) { $parseErrors | Format-List Message,Extent; throw "ParserError" }
.\scripts\phase1-neon-env-repair.ps1
.\scripts\phase1-neon-env-repair.ps1 -Apply
php -l scripts/phase1-neon-preflight.php
php scripts/phase1-neon-preflight.php
```

Despues completar `DB_MIGRATIONS_PASSWORD` con credencial Neon de la rama dev, `DB_APP_BACKEND_ROLE_PASSWORD` con secreto aleatorio fuerte y `DB_PASSWORD` con el mismo valor (rol aun sin crear). Si el preflight indica configuracion Laravel no cargada a pesar de env visibles, **detenerse y revisar bootstrapping** antes de permitir SQL. Si reporta variables heredadas en el proceso, revisar su procedencia sin publicar valores, utilizar nueva terminal para evitar contaminacion de entorno y repetir. Duplicados de `DB_APP_BACKEND_NEW_PASSWORD` quedan fuera de la reparacion: no afecta a esta puerta pero conviene eliminarlos manualmente antes de fases posteriores.

El endpoint production y el proyecto Supabase NO fueron modificados. No se ejecutaron DDL, DML ni migraciones remotas; por tanto F1.6 aun no esta cerrado.


## F1.6 — Causa confirmada: variables vacías heredadas en PowerShell

La nueva evidencia del usuario confirmó que el `.env` YA apunta correctamente a Neon `contabilidad-dev` en ambos hosts, `neondb` y `APP_ENV=local`, pero la configuración cargada por Laravel muestra `APP_ENV`, hosts, puertos y usuario vacíos. El propio preflight indica que hay variables heredadas del proceso para esos nombres. **No cambiar los endpoints nuevamente.** Las variables de proceso declaradas vacías prevalecen sobre los valores válidos del `.env`.

Se incorporó `backend/scripts/phase1-neon-clear-empty-process-env.ps1`: el modo predeterminado solo cuenta y enumera **nombres de variables heredadas**, sin mostrar valores. Con `-Apply` elimina exclusivamente las variables de proceso vacías o espacios en blanco **de la terminal PowerShell actual**; preserva cualquier variable no vacía, nunca modifica `.env`, no abre una conexión ni ejecuta SQL. Si muestra variables no vacías que podrían sobrescribir `.env`, revisarlas sin publicar sus valores. También se mejoró el preflight para que señale exactamente las variables heredadas vacías y no continúe con SQL cuando aparezcan.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
.\scripts\phase1-neon-clear-empty-process-env.ps1
.\scripts\phase1-neon-clear-empty-process-env.ps1 -Apply
php -l scripts/phase1-neon-preflight.php
php scripts/phase1-neon-preflight.php
```

El `.env` aportado aún tiene vacías las contraseñas `DB_MIGRATIONS_PASSWORD`, `DB_PASSWORD` y `DB_APP_BACKEND_ROLE_PASSWORD`; son un bloqueo independiente que el usuario debe resolver **privadamente**. No guardar ni mostrar secretos en el repositorio. Si `APP_KEY=base64:x` es realmente literal (y no una redacción), debe generarse una clave válida con `php artisan key:generate` **solo en este entorno nuevo**, nunca en un entorno con datos cifrados ya almacenados.

**No se aplicaron migraciones a Neon ni se modificó Neon production o Supabase.** La rama `contabilidad-dev` continúa creada y sin tablas del ERP. Antes de cualquier escritura, revalidar el host y ejecutar `--read-only --require-empty`.


## F1.6 — Corrección del falso diagnóstico de variables «heredadas» (21/10/2026)

**Nueva evidencia de Windows:** el usuario ejecutó `phase1-neon-clear-empty-process-env.ps1 -Apply` y eliminó **20 variables vacías** de la sesión PowerShell. En la siguiente ejecución de `php scripts/phase1-neon-preflight.php`, el antiguo preflight volvió a enumerar estas variables como «heredadas», a pesar de que consultaba `getenv()` **después de iniciar Laravel**. Este procedimiento de detección fue defectuoso: los valores vistos después del bootstrap pueden proceder del `.env` que acaba de cargar Laravel; **no demuestra que PowerShell los heredara**. Además, claves `DB_URL` y `DATABASE_URL` se dejan intencionadamente vacías, por lo que no deben bloquear el preflight por esa sola condición.

Correcciones publicadas en `main`:

- `backend/scripts/phase1-neon-env-origins.php`: nuevo diagnóstico **sin conexión SQL ni escrituras** que captura `getenv()`, `$_ENV` y `$_SERVER` **ANTES** de cargar Composer/Laravel, compara únicamente la presencia/no presencia de variables en `backend/.env`, y al terminar compara `env()`, configuración Laravel y el archivo de entorno seleccionado. **Nunca imprime valores de contraseñas, tokens, usuarios, hosts ni URIs**. Detecta también claves duplicadas en `.env` y verifica la longitud de `APP_KEY` sin mostrarla. Ejecutar primero `php -l scripts/phase1-neon-env-origins.php` y después `php scripts/phase1-neon-env-origins.php`.
- `backend/scripts/phase1-neon-preflight.php`: ahora captura las tres fuentes antes del bootstrap de Laravel; solo describe las variables realmente existentes antes de Laravel y bloquea claves críticas preexistentes vacías, no variables creadas/cargadas posteriormente por Dotenv. Las URLs vacías, permitidas por diseño, no provocan por sí mismas el bloqueo.

**No volver a borrar variables a ciegas, ni recrear `.env`, ni migrar** hasta observar el nuevo diagnóstico. El `.env` proporcionado sí declara Neon `contabilidad-dev` y PostgreSQL 18, pero el arranque PHP/Laravel no utilizó los valores esperados. También faltan `DB_MIGRATIONS_PASSWORD`, `DB_PASSWORD` y `DB_APP_BACKEND_ROLE_PASSWORD`, que son un problema independiente; no compartirlas en el chat. `APP_KEY=base64:x` tampoco es válida si es literal. Ningún SQL/DDL/DML remoto se ha realizado con esta corrección. Supabase y Neon production siguen intactos.

Referencia técnica: Laravel 13 `LoadEnvironmentVariables` ejecuta `Dotenv::create(Env::getRepository(), ...)->safeLoad()`, y Laravel advierte sobre precedencia de variables externas en su [guía de configuración](https://github.com/laravel/docs/blob/13.x/configuration.md).


## F1.6 — Prueba concluyente de variables heredadas vacías y limpieza verificable (21/10/2026)

El usuario ejecutó el diagnóstico seguro `phase1-neon-env-origins.php`. **Antes de Composer y Laravel** el proceso PHP tenía `getenv()=VACIO` y `$_SERVER=VACIO` para `APP_ENV`, `APP_KEY`, host/puerto/base/usuario SSL de ambas conexiones y contraseñas. `$_ENV` se encontraba ausente. En cambio, el `backend/.env` ya contenía valores reales para `APP_ENV`, hosts de Neon dev, base, usuario, contraseña de app y contraseña de migraciones. Dotenv no sobrescribe por defecto variables que ya existen, incluso si están vacías. Conclusión: **el bloqueo real está en las variables vacías heredadas por el proceso PHP, no en los hosts de Neon ni en la base PostgreSQL 18**.

La implementación anterior del limpiador PowerShell invocaba `[Environment]::SetEnvironmentVariable($name, $null, 'Process')`, informaba PASS, pero el proceso PHP hijo seguía recibiendo variables vacías. Se cambió a **`Remove-Item -LiteralPath ('Env:' + $name) -Force`**, se comprueba su ausencia inmediata en PowerShell y, como segunda puerta, se lanza un proceso `php -r` independiente que comprueba `getenv()` y `$_SERVER` para variables críticas. Solo informa nombres de variables, no valores. Si PHP aún las recibe vacías, la limpieza se declara **FAIL**, no se autoriza ejecutar migraciones y se debe investigar variables persistentes de Windows o configuración de PHP. Se eliminan variables **solo de la sesión PowerShell actual**; no hay escrituras al `.env`, al registro de Windows, a Neon ni a Supabase.

Comandos de validación:

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
$tokens=$null; $errors=$null
[System.Management.Automation.Language.Parser]::ParseFile(
  (Resolve-Path .\scripts\phase1-neon-clear-empty-process-env.ps1).Path,
  [ref]$tokens,[ref]$errors
) | Out-Null
if ($errors.Count -gt 0) { $errors | Format-List Message,Extent; throw "Error de sintaxis PowerShell" }
.\scripts\phase1-neon-clear-empty-process-env.ps1 -Apply
if ($LASTEXITCODE -ne 0) { throw "El limpiador del entorno PHP no aprobo" }
php scripts/phase1-neon-env-origins.php
php scripts/phase1-neon-preflight.php
```

La misma evidencia confirmó además `APP_KEY` de longitud inválida y `DB_APP_BACKEND_ROLE_PASSWORD` vacío. El preflight ahora verifica explícitamente longitud/formato de `APP_KEY` sin revelar su contenido. Completar contraseña del rol de app e instalar una APP_KEY válida **solamente para este entorno de desarrollo nuevo**, preservando secretos reales ya configurados. **No hacer cambios DDL/DML ni ejecutar migrate hasta PASS del preflight seguro y verificación read-only del destino.**


## F1.6 — Claves locales tras limpieza de variables (21/10/2026)

El usuario obtuvo dos PASS del limpiador (20 variables heredadas vacías eliminadas, y PHP hijo limpio). El diagnóstico confirmó después que `APP_ENV=local` y ambos hosts de Neon `contabilidad-dev` se leen bien. **No hay problema de conexión por variables heredadas.** La comprobación actual de Laravel se detiene únicamente por `APP_KEY` inválida y por divergencia entre `DB_PASSWORD` y `DB_APP_BACKEND_ROLE_PASSWORD`. El usuario indicó que `DB_APP_BACKEND_ROLE_PASSWORD` y `DB_APP_BACKEND_NEW_PASSWORD` son iguales (35 caracteres); esto **no** equivale a que `DB_PASSWORD` coincida, y la variable `NEW_PASSWORD` solo corresponde a rotación del rol existente, no a bootstrap inicial.

`phase1-neon-env-origins.php` ahora informa **solamente SI/NO**, sin imprimir hashes, passwords ni claves: validez de APP_KEY tanto en archivo como tras bootstrap, si `DB_PASSWORD` coincide con `DB_APP_BACKEND_ROLE_PASSWORD`, y si el secreto de rotación coincide con el rol. No revela valores ni conecta a PostgreSQL. En una rama Neon nueva sin datos cifrados que conservar, con entorno ya saneado, se permite regenerar APP_KEY mediante `php artisan key:generate --force` y validar inmediatamente; **no regenerar sobre datos cifrados existentes**. El usuario debe alinear privadamente DB_PASSWORD con el secreto inicial del rol, y reservar `DB_APP_BACKEND_NEW_PASSWORD` para rotación, pudiendo dejarla vacía ahora.

**No ejecutar migraciones Neon** hasta comprobar APP_KEY válida, coincidencia DB_PASSWORD/ROLE, preflight de configuración PASS y `--read-only --require-empty`. Neon production y Supabase permanecen intactos.


## F1.7 — Neon PostgreSQL 18 aprobado en modo lectura, migraciones NO aplicadas (21/10/2026)

**Evidencia del usuario:** el preflight Laravel sin SQL y el preflight SELECT --read-only --require-empty finalizaron con PASS. La conexión identifica PostgreSQL 18, base neondb, usuario neondb_owner, cero tablas public y rol app_backend aún inexistente. Una comprobación independiente mediante el conector Neon confirmó: proyecto soft-bonus-52215969, rama contabilidad-dev (br-odd-lake-b6wshqv3), PostgreSQL 18.6, cero tablas, sin rol app_backend y branch ready. No se ha ejecutado DDL/DML, ni se ha modificado Supabase o Neon production.

**Bloqueo local restante:** phase1-neon-env-origins.php detectó dos líneas activas DB_PASSWORD en backend/.env. Aunque la última coincida con DB_APP_BACKEND_ROLE_PASSWORD, Dotenv podría resolver la primera. El preflight ahora rechaza cualquier entrada repetida DB_*, NEON_*, APP_KEY, APP_ENV o DATABASE_URL. Para corregirlo, abrir notepad .env, buscar DB_PASSWORD= y dejar exactamente una sola línea, con el valor privado correcto. No imprimir ni pegar secretos en chats. DB_APP_BACKEND_NEW_PASSWORD es solo para rotación; no tiene que coincidir durante el primer bootstrap.

**Inventario de migraciones del repositorio:** 21 PHP en total, 16 del esquema portátil PostgreSQL 18 y 5 específicas de Supabase excluidas. Las cinco excluidas son:

- 2026_10_19_000000_restrict_supabase_data_api_grants
- 2026_10_19_010000_add_company_economic_profile
- 2026_10_20_000000_rotate_app_backend_credential
- 2026_10_20_010000_scope_company_listing_to_authenticated_user
- 2026_10_20_235900_create_dev_company_b_via_laravel

Se publicó scripts/phase1-neon-schema-bootstrap.php. En modo --plan (predeterminado), enumera y valida los archivos SIN hacer SQL. En modo opt-in --apply=contabilidad-dev exige destino exacto conocido, PostgreSQL 18, base vacía, role app_backend ausente, SSL, credenciales válidas y ausencia de duplicados. Ejecuta una por una solo las 16 migraciones autorizadas usando pgsql_migrations y verifica registros de migrations, cinco search_path endurecidos, política de auditoría y rol app_backend sin bypass. Para evitar filtrar contraseñas, no imprime el texto bruto de errores SQL de Laravel.

**Este inicializador NO se ha ejecutado en Neon.** Su lint PHP real y su --plan todavía requieren validación en Windows. El PASS anterior de Docker PostgreSQL 18 no sustituye estas dos verificaciones del nuevo script.

Siguientes comandos seguros, dentro de backend (luego de corregir el duplicado de DB_PASSWORD):

    git pull --ff-only origin main
    (Select-String -Path .env -Pattern '^\s*DB_PASSWORD\s*=').Count
    php -l scripts/phase1-neon-preflight.php
    php scripts/phase1-neon-preflight.php
    php scripts/phase1-neon-preflight.php --read-only --require-empty
    php -l scripts/phase1-neon-schema-bootstrap.php
    php scripts/phase1-neon-schema-bootstrap.php --plan

No usar php artisan migrate general, migrate:fresh ni la opción --apply antes de comprobar el plan y autorizar expresamente la inicialización del entorno de desarrollo.


## F1.7 — Puerta final previa a DDL (continuación 21/10/2026)

El usuario confirmó en PowerShell las cuatro comprobaciones requeridas: **DB_PASSWORD único (conteo 1)**; preflight --read-only --require-empty **PASS** en `contabilidad-dev`; `php -l` del nuevo inicializador **PASS**; e inventario --plan **PASS**, exactamente **16 migraciones portables + 5 Supabase-only excluidas**.

Una comprobación adicional read-only contra la rama de desarrollo `br-odd-lake-b6wshqv3` verificó que:
- `neondb_owner` tiene LOGIN, CREATEROLE, CREATEDB, no es SUPERUSER y **sí tiene BYPASSRLS**: su credencial se reserva para migraciones administrativas, nunca para el tráfico.
- `pgcrypto` es una extensión disponible en PostgreSQL 18.6, aún no instalada.
- `app_backend` sigue ausente y `public` sigue sin tablas.
- La rama sigue en estado `ready`. Neon `production` y Supabase siguen intactos.

Se reforzó el bootstrap y las dos migraciones F1.4:
- Antes de cualquier DDL, el bootstrap exige también que `neondb_owner` posea CREATEROLE y `pgcrypto` esté disponible.
- Las F1.4 aceptan **solo el host directo exacto** de `contabilidad-dev`, no un host arbitrario Neon con un nombre de rama inventado.
- Tras las 16 migraciones, el bootstrap requiere exactamente **13 tablas de tenant con ENABLE y FORCE RLS**, **16 filas en migrations**, **5 funciones con search_path fijo**, política de auditoría sin exposición global y `app_backend` sin SUPERUSER, BYPASSRLS, CREATEROLE, CREATEDB ni membresía `neon_superuser`.

**No se ejecutó bootstrap ni SQL de escritura desde ChatGPT.** El conector Neon no puede recuperar las credenciales privadas de `backend/.env` local; no conviene crear un `app_backend` desde Neon Console/API porque puede recibir privilegios administrativos por defecto. La inicialización tiene que realizarse desde Laravel, usando la contraseña privada local, y con los guardas de la rama. Ejecutar únicamente la herramienta preparada, **no** `php artisan migrate` general:

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase1-neon-schema-bootstrap.php
php scripts/phase1-neon-schema-bootstrap.php --plan
php scripts/phase1-neon-preflight.php --read-only --require-empty
# Solo si los controles anteriores conservan PASS:
php scripts/phase1-neon-schema-bootstrap.php --apply=contabilidad-dev
```

La ejecución --apply crea roles, funciones, tablas y políticas **solo** en `contabilidad-dev`. No ejecuta seeds ni copia datos Supabase. Si una migración se detiene, **no reejecutar desde cero**: el inicializador requiere cero tablas iniciales. Registrar el nombre de migración fallida y recuperar el estado read-only antes de decidir un ajuste; no borrar tablas ni hacer `migrate:fresh`. Después comprobar el resumen 16/16, rol `app_backend` y RLS antes de pruebas HTTP.


## F1.8 — Esquema realmente instalado y siguiente ronda de pruebas

El usuario ejecutó el bootstrap local explícito y reportó **16/16 migraciones PASS**, 13 tablas FORCE RLS, 5 funciones search_path, auditoría tenant-only y rol app_backend limitado. Se contrastó con SELECT independientes mediante Neon apuntando explícitamente a contabilidad-dev: PostgreSQL **18.6**, **31 tablas public**, **16 registros en migrations**, **13 FORCE RLS**, **5 funciones endurecidas**, app_backend sin privilegios administrativos y audit_logs_select_policy sin eventos globales. No se tocaron Neon production ni Supabase.

Para la siguiente etapa están publicadas dos herramientas nuevas, **no ejecutadas** todavía en la máquina Windows del usuario:

1. `scripts/phase1-neon-app-role-readonly.php` — verifica conexión real con DB_USERNAME=app_backend, PostgreSQL 18, permisos SQL, 13 tablas FORCE RLS y contexto local 101/202 sin crear datos. Usa BEGIN READ ONLY y ROLLBACK; restringida a la rama contabilidad-dev. **No prueba HTTP ni denegación de escrituras cruzadas**.
2. `scripts/phase1-neon-rls-rollback.php` — `--plan` por defecto sin SQL; `--probe=contabilidad-dev` es **opt-in**. Crea datos A/B dentro de una transacción con app_backend y IDs explícitos (sin nextval), verifica INSERT legítimo, lectura tenant A/B, UPDATE y DELETE cruzados con 0 cambios, INSERT cruzado rechazado por SQLSTATE 42501, sin contexto no hay filas, y termina siempre en ROLLBACK seguido de SELECT de limpieza. No copia ni borra datos existentes; sin embargo, **sí realiza escrituras temporales y debe ejecutarse solo en desarrollo**.
   
Pasos seguros desde backend:

```powershell
git pull --ff-only origin main
php -l scripts/phase1-neon-app-role-readonly.php
php -l scripts/phase1-neon-rls-rollback.php
php scripts/phase1-neon-app-role-readonly.php
php scripts/phase1-neon-rls-rollback.php --plan
```

Si los anteriores son PASS, solo cuando se desea realizar la prueba transaccional explícita de dev:

```powershell
php scripts/phase1-neon-rls-rollback.php --probe=contabilidad-dev
```

Las pruebas HTTP Pest `CompanyRoleAuthorizationTest` y `TenantContextNestedTest` se habían validado (12 tests/37 assertions) sobre Docker PG18.6 efímero. **No ejecutarlas directamente sobre Neon** sin diseñar antes un runner independiente con comprobación del entorno y limpieza verificable. La ejecución de CI GitHub Actions también se debe tratar como pendiente mientras no haya jobs exitosos.


## F1.8 — Pruebas RLS A/B completadas y limpieza confirmada (21/10/2026)

El usuario ejecutó en su backend conectado a Neon `contabilidad-dev`:

- `php scripts/phase1-neon-app-role-readonly.php`: **PASS** conexión operativa real `app_backend` en PostgreSQL 18, roles limitados y GRANTs, 13 FORCE RLS y contexto tenant 101/202 sin filtraciones.
- `php scripts/phase1-neon-rls-rollback.php --plan`: **PASS** sin abrir SQL.
- `php scripts/phase1-neon-rls-rollback.php --probe=contabilidad-dev`: **PASS** RLS A/B: lectura cruzada oculta, UPDATE/DELETE cruzados afectan cero filas, INSERT cruzado denegado, sin contexto no hay visibilidad y **ROLLBACK confirmado** con IDs explícitos sin consumir secuencias.

Después se ejecutó una segunda verificación **SELECT independiente a través del conector Neon**, con proyecto `soft-bonus-52215969`, rama `br-odd-lake-b6wshqv3`, base `neondb`: `companies=0`, `company_areas=0`, `audit_logs=0`, `migrations=16`, PostgreSQL `18.6`. No hay residuos de esas pruebas y se conserva el esquema íntegro.

**F1.8 completada** para RLS con el rol SQL real. No significa que las pruebas HTTP/REST Laravel sobre Neon hayan pasado: todavía falta cubrir autenticación Sanctum, permisos ADMIN/ACCOUNTANT/AUDITOR/DATA_ENTRY por empresa, elección de empresa, rechazo de tenant indebido y flujos de asientos. Las pruebas HTTP existentes con 12 casos y 37 aserciones se ejecutaron sobre Docker efímero, no sobre Neon. Antes de permitir escrituras HTTP sobre Neon, diseñar un runner de pruebas con rollback total y verificación de identidad, sin ejecutar `php artisan test` general contra Neon por error. Mantener Neon `production` y Supabase intactos.


## F1.9 — Probar autorización HTTP real sin dejar datos en Neon (21/10/2026)

F1.8 quedó aprobado: el usuario demostró PASS del rol `app_backend` y de las pruebas RLS A/B con transacción ROLLBACK. Una verificación independiente **SELECT** posterior a través del conector Neon confirmó `companies=0`, `company_areas=0`, `audit_logs=0`, `migrations=16`, servidor PostgreSQL `18.6` en `contabilidad-dev`. La rama production y Supabase no fueron tocadas.

Se añadió `backend/scripts/phase1-neon-http-rollback.php`, ejecutor cerrado para `contabilidad-dev` con dos modos:

- `--plan`: solo declara 8 escenarios de HTTP y los fixtures, sin conexiones SQL ni HTTP.
- `--probe=contabilidad-dev`: valida host directo exacto, base, rama, rol restringido, contraseñas coherentes y sin duplicados; comprueba que el backend nuevo todavía tiene cero empresas/usuarios; abre **una transacción exterior administrada por Laravel** (necesaria para que `TenantContext::run` haga savepoints); inserta dos empresas ficticias, tres usuarios y cuatro membresías con IDs explícitos sin consumir secuencias; hace solicitudes mediante el kernel HTTP y autenticación **simulada en memoria** `Sanctum::actingAs`, bajo `app_backend`; valida una combinación de HTTP **401, 403, 422 y 200**. Verifica que el nivel transaccional sigue siendo 1, realiza ROLLBACK obligatorio y confirma SELECT de limpieza de las dos empresas y tres usuarios. No guarda tokens ni contraseñas reales de usuarios ficticios y no hace llamadas HTTP externas.

**Diferencia importante:** esto prueba middleware `auth:sanctum` y `RequireCompanyRole` con identidades simuladas; **NO comprueba login real, emisión de token Sanctum, revocación, POST contable exitoso ni persistencia de asiento**. Esas serán pruebas posteriores en un escenario diseñado específicamente y verificado sin afectar otros entornos.

Los tests existentes `CompanyRoleAuthorizationTest` y `TenantContextNestedTest` pasaron 12 pruebas/37 aserciones en Docker PG18 efímero; esa referencia no es una ejecución directa del nuevo runner. Su sintaxis PHP en el equipo del usuario **aún debe verificarse**, y el modo `--probe` **no ha sido ejecutado por el asistente**.

Pasos:

```bash
cd ~/contabilidad/backend
git pull --ff-only origin main
php -l scripts/phase1-neon-http-rollback.php
php scripts/phase1-neon-http-rollback.php --plan
# ÚNICAMENTE si el lint y --plan pasan y se desea ejecutar fixtures temporales:
php scripts/phase1-neon-http-rollback.php --probe=contabilidad-dev
```

El script falla cerrado ante cualquier error y no imprime errores SQL/HTTP potencialmente sensibles. Si no completa las ocho comprobaciones o no confirma ROLLBACK, detener pruebas remotas y revisar el resultado antes de reintentar. En esta etapa no ejecutar `php artisan test` general directamente contra Neon.


## F1.9 aprobado y F1.10 preparado — Bearer real y revocación (21/10/2026)

El usuario ejecutó sobre Neon contabilidad-dev `php -l scripts/phase1-neon-http-rollback.php` (**sin errores**), `--plan` (**dry-run**) y `--probe=contabilidad-dev`: los **8 escenarios HTTP PASS** (401 anónimo, 422 administrador autorizado, 403 auditor, 200 lectura auditor, 422 contador autorizado, 403 acceso/rol ajeno y digitador) y **ROLLBACK** de empresas/usuarios ficticios confirmado. Esta fase usó `Sanctum::actingAs`, por lo que no prueba el intercambio de credenciales por un token Bearer ni su revocación.

Una nueva consulta independiente Neon `SELECT` en `br-odd-lake-b6wshqv3` confirmó **PG18.6, companies=0, users=0, personal_access_tokens=0, audit_logs=0 y migrations=16**, sin residuos de pruebas.

Se publicó el nuevo script `backend/scripts/phase1-neon-token-rollback.php` como **F1.10**. Está orientado a autenticación REST Sanctum real y solo puede conectarse como `app_backend` a la rama exacta `contabilidad-dev` en PostgreSQL 18. Tiene allowlist de host, usuario y credenciales coherentes, prohíbe claves `.env` duplicadas y rechaza configuración Laravel cacheada.

- **`--plan`**: muestra pasos sin conectar ni escribir.
- **`--probe=contabilidad-dev`**: abre una transacción exterior Laravel y verifica PG18, `app_backend` y que las tablas de usuarios, empresas y tokens estén inicialmente vacías; crea dos empresas, un usuario con contraseña **aleatoria en memoria y hash**, dos membresías con IDs explícitos; realiza HTTP vía kernel sin `Sanctum::actingAs`: **401** sin token, **422** login incorrecto, **200** login correcto, emisión real de token Sanctum con expiración de **12 horas**, **200** consulta de empresas usando token Bearer, **403** auditor de empresa B sin permiso de escritura usando `X-Company-Id`, **200** cambio a B, **200** logout y revocación, **401** reutilización del token revocado. Verifica 8 estados HTTP, rollback obligatorio y cero filas de empresa, usuario y token persistentes. No imprime contraseñas ni token y configura cache/sesión `array` solo dentro del proceso.
- **Importante:** una inserción real de token Sanctum utiliza secuencias PostgreSQL; las secuencias **pueden avanzar a pesar del ROLLBACK**. No es una prueba completamente sin efectos numéricos, aunque no deja filas de negocio ni tokens.
- Esta prueba no publica un asiento contable real; el motor POST balanceado, validación DB diferida, reversión y auditoría de operaciones quedan para F1.11.

### Comandos para el usuario

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase1-neon-token-rollback.php
php scripts/phase1-neon-token-rollback.php --plan
# Ejecutar solo si lint/plan pasan y se acepta el avance no transaccional de secuencias:
php scripts/phase1-neon-token-rollback.php --probe=contabilidad-dev
```

**No hay aún ejecución local ni PASS de F1.10; solo código y plan publicados en main.** Ante fallo, no reintentar a ciegas: reportar solo la salida sin secretos para analizar si existe un defecto de autenticación, RLS o persistencia. No ejecutar `php artisan test` general en Neon. Neon production y Supabase intactos.


## F1.10 y F1.11 — pruebas preparadas, NO ejecutadas localmente (21/10/2026)

**F1.9 verificada:** el usuario obtuvo PASS en sus 8 escenarios de HTTP con Sanctum simulado y ROLLBACK confirmado, sin datos persistentes. Las pruebas nuevas F1.10 y F1.11 se publicaron para evaluar las dos capacidades que aún faltan antes de acreditar el ciclo contable completo.

### F1.10 — autenticación Bearer real

`backend/scripts/phase1-neon-token-rollback.php` usa el endpoint directo conocido de Neon dev, usuario SQL `app_backend`, claves privadas locales, sin configuración cacheada, sin duplicados y una transacción Laravel externa. Valida un 401 anónimo, un 422 de contraseña incorrecta, login 200 con token Sanctum real expirable en 12 horas, dos empresas visibles mediante token Bearer, un 403 de auditor que intenta escribir en B, cambio de empresa autorizado, logout y rechazo del token revocado. La prueba requiere que empresas, usuarios y tokens estuvieran vacíos, y tras ROLLBACK verifica que continúen así. **No confundir con Sanctum::actingAs**: el token es realmente generado y consultado, pero todo se desarrolla mediante el kernel HTTP dentro de PHP CLI, no una petición remota pública. La secuencia de `personal_access_tokens` puede avanzar pese a ROLLBACK.

### F1.11 — contabilización balanceada con validación diferida real

Se añadió `backend/scripts/phase1-neon-journal-rollback.php`: prueba opt-in en la rama dev exacta, con `app_backend`, dos cuentas operativas del cuarto nivel bajo jerarquías PCGE, una empresa ficticia, usuario ADMIN y período octubre 2026, dentro de una única transacción Laravel que termina en ROLLBACK.

Verifica HTTP **201** del controlador de asientos con dos partidas de **120.0000** en Debe/Haber y ambas monedas, recuento y sumas exactas, `SET CONSTRAINTS ALL IMMEDIATE` para **forzar los triggers PostgreSQL diferidos antes del ROLLBACK** (un rollback por sí solo NO los ejecutaría), HTTP **422** al descuadrar una partida, rechazo SQLSTATE **23514** de UPDATE de asiento inmutable dentro de SAVEPOINT, y evento `JOURNAL_ENTRY_CREATED` en auditoría. Tras restaurar el tenant SQL para consultas sujetas a RLS, comprueba que empresas, usuarios, cuentas, asientos y auditoría ficticios no persistan.

Esta prueba también puede avanzar secuencias, aunque no persistan registros. **No se ha ejecutado todavía contra Neon** y debe pasar primero `php -l`; no confundir la disponibilidad del código con un PASS. Se corrigió la documentación de `PostJournalEntryService`: `DB::transaction` usa el aislamiento por defecto, no fuerza REPEATABLE READ. Las pruebas de concurrencia/correlativos y aislamiento SQL explícito siguen pendientes.

### Orden de ejecución sin mezclar fallos

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase1-neon-token-rollback.php
php -l scripts/phase1-neon-journal-rollback.php
php scripts/phase1-neon-token-rollback.php --plan
php scripts/phase1-neon-journal-rollback.php --plan
```

Después, solo si los dos lints y planes están correctos, ejecutar F1.10 primero:

```bash
php scripts/phase1-neon-token-rollback.php --probe=contabilidad-dev
```

**No lanzar F1.11** hasta verificar la ejecución y limpieza de F1.10. Si F1.10 pasa y el esquema permanece limpio, se habilita la comprobación F1.11:

```bash
php scripts/phase1-neon-journal-rollback.php --probe=contabilidad-dev
```

Al concluir usar lecturas independientes de Neon para constatar que no hay empresas, usuarios, tokens, asientos, partidas ni auditoría persistentes. En las pruebas transaccionales jamás correr `migrate:fresh`, ni usar el propietario `neondb_owner` para tráfico o tests de seguridad. Neon `production` y Supabase se conservan intactos.

Verificación independiente antes de estas nuevas pruebas: PG18.6, 16 migraciones, 0 empresas, 0 usuarios, 0 tokens, 0 asientos, 0 líneas, 0 eventos de auditoría y 2 triggers de partida doble configurados como DEFERRABLE INITIALLY DEFERRED.


## Continuación F1.10–F1.11 — hardening del motor contable (21/10/2026)

Se revisaron los controladores REST de login/logout, servicio de asientos y permisos PostgreSQL directamente en Neon `contabilidad-dev`. La consulta de catálogos confirmó `app_backend` con SELECT/INSERT/DELETE en `personal_access_tokens` (necesarios para login y revocación), sin DELETE en `journal_entries`, `journal_entry_lines` ni `audit_logs`. `personal_access_tokens.expires_at` es TIMESTAMPTZ; Laravel genera token con vencimiento de 12 h.

**F1.11 se amplió para comprobar el trigger diferido en el caso negativo.** Además de la validación PHP/HTTP 422, el nuevo test inserta directamente mediante app_backend una cabecera y dos líneas que descuadran la moneda local, dentro de `SAVEPOINT f111_deferred_bad`; configura `SET CONSTRAINTS ALL DEFERRED` y luego `SET CONSTRAINTS ALL IMMEDIATE` para exigir SQLSTATE 23514. Siempre ejecuta `ROLLBACK TO SAVEPOINT` y verifica que la cabecera inválida desaparezca antes del ROLLBACK global. Ahora espera **7 comprobaciones** (antes eran 6). Esto evita considerar probado el trigger solo por haber pasado por la validación previa de PHP.

**Verificación independiente antes de ejecutar pruebas**: Neon PG18.6, 16 migraciones, cero empresas, usuarios, tokens, asientos, partidas y auditorías. No hubo escrituras remotas en esta revisión.

Comandos recomendados, en orden y sin reintentos ciegos:

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase1-neon-token-rollback.php
php -l scripts/phase1-neon-journal-rollback.php
php scripts/phase1-neon-token-rollback.php --plan
php scripts/phase1-neon-journal-rollback.php --plan
```

Si ambos lint y planes terminan correctamente, ejecutar solo **F1.10** en la rama `contabilidad-dev`:

```bash
php scripts/phase1-neon-token-rollback.php --probe=contabilidad-dev
```

Solo cuando ese comando termina PASS con limpieza confirmada, proseguir con **F1.11**:

```bash
php scripts/phase1-neon-journal-rollback.php --probe=contabilidad-dev
```

Ambos `--probe` hacen escrituras dentro de transacciones y hacen ROLLBACK; pueden avanzar secuencias PostgreSQL. Sus resultados aún son **PENDIENTES** y los archivos deberán pasar lint PHP en el entorno local, pese al chequeo CI configurado. No ejecutar `php artisan test` general contra Neon. Neon production y Supabase siguen intactos.


### F1.11 — verificación de rollback realmente sujeta al tenant

Se corrigió una debilidad del control final: las tablas `journal_entries`, `accounting_accounts` y `audit_logs` tienen FORCE RLS, por lo que `COUNT(*)=0` **sin contexto tenant no prueba ausencia de filas**. Tras el ROLLBACK principal, el script abre una segunda transacción de `READ ONLY`, declara el `app.current_company_id` ficticio con `set_config(...,true)` y comprueba explícitamente cero filas del tenant para cabeceras, partidas, cuentas, períodos, membresías y eventos de auditoría, además de empresas y usuarios. Siempre hace rollback también de esta segunda transacción de lectura.

Combinada con la prueba negativa del trigger diferido usando SAVEPOINT, esta validación evita dos falsos positivos: «partida doble aprobada sin ejecutar el trigger SQL» y «limpieza aprobada porque RLS oculta el dato». **Aún pendiente de php -l y prueba local en entorno del usuario**; no afirmar F1.11 PASS hasta contar con esa salida.
