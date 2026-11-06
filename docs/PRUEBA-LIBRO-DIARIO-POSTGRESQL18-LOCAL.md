# Prueba segura del Libro Diario con PostgreSQL 18 local

**Objetivo:** comprobar el esquema contable actual, Sanctum en pruebas,
multiempresa, las políticas `FORCE ROW LEVEL SECURITY`, áreas, subdiarios,
correlativo e idempotencia usando un **PostgreSQL 18 exclusivo de Docker**,
sin conectar con Neon, Supabase ni la base remota configurada en `.env`.

**Estado:** herramienta de ensayo preparada; falta ejecutarla en el
Windows del usuario para validar su funcionamiento completo.

## Antes de iniciar

- Docker Desktop 29+ activo en modo **contenedores Linux**.
- PHP 8.5 y dependencias Composer disponibles en `backend/vendor`.
- Abrir **una nueva sesión de PowerShell**: en la sesión del benchmark
  anterior quedaron variables `DB_*` para PostgreSQL 17.
- No debe existir `backend/bootstrap/cache/config.php`.
- El Docker anterior `pg-latencia-local` fue creado con `--rm`,
  sin volumen, solo para medir `SELECT 1`; se puede detener si sigue
  encendido sin afectar las bases remotas.

```powershell
# Opcional: detener el contenedor de la prueba anterior si todavía existe.
docker ps --filter "name=pg-latencia-local"
# Solo si aparece pg-latencia-local:
docker stop pg-latencia-local

cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
Test-Path .\bootstrap\cache\config.php
```

El último comando debe imprimir `False`. **No ejecutar `migrate:fresh`,
`php artisan migrate` general ni `db:seed`**. Algunas migraciones del
repositorio son exclusivamente de una rama Neon/Supabase y otras contienen
guardas para un estado concreto de la base remota.

## Ejecutar suite funcional completa en un PostgreSQL 18 efímero

```powershell
cd C:\Proyectos\contabilidad\backend
.\scripts\phase2-active-rls-docker.ps1 -JournalAreaDimensionTest
```

Esta modalidad es nueva en el script existente. No sustituye ni altera
`-PeriodConcurrency` ni el ensayo normal de `phase2-active-rls-docker.ps1`.

El script:

1. Crea `postgres:18-alpine` con **puerto aleatorio enlazado a
   `127.0.0.1`** y contraseñas efímeras aleatorias.
2. Comprueba que el destino es exclusivamente `contabilidad_ci`
   dentro del nuevo contenedor. Las conexiones de administración y
   aplicación se fijan únicamente a ese contenedor.
3. Aplica **20 migraciones base/portables** ya usadas en el ensayo
   F2.3h, además de **5 migraciones** de entidades, áreas y subdiarios
   utilizadas por el Libro Diario actual, siempre con `--path`.
4. Ejecuta Pest sobre `JournalAreaDimensionTest.php` usando el rol
   limitado `app_backend`, con `FORCE RLS` vigente. El escenario
   actual contiene **15 pruebas**.
5. Comprueba el reporte JUnit: 15 casos, ninguno fallido u omitido.
   Una ejecución incompleta no se marca como aprobada.
6. Restaura las variables de entorno y **elimina** el contenedor
   temporal y el reporte JUnit, incluso cuando falla la prueba.

Los registros generados son exclusivamente ficticios. El comando no
crea un usuario ni una sesión Bearer reales y no habilita un entorno
persistente para abrir el editor en el navegador; esa es una etapa
posterior independiente.

## Qué resultado enviar

- Las líneas `[MIGRACIONES]`, `[DIARIO]` y el resumen final de Pest.
- Si aparece `[FAIL]`, el nombre de la migración o el test fallido.
  No copiar contraseñas ni cadenas `DB_URL`.
- **No repetir** un `migrate` general para subsanar un error.
  Revertir y corregir primero el harness o las migraciones específicas.

### Comparación de tiempos

La latencia de dos `SELECT 1` del PostgreSQL local anterior era ~1 ms
y abrir conexión ~7 ms, pero ese contenedor era PostgreSQL 17 vacío.
La prueba aquí utiliza la versión **18**, tablas reales y políticas RLS,
y puede mostrar tiempos de pruebas más próximos al flujo del Libro Diario.
**El tiempo total de Pest no es una métrica de petición HTTP**:
incluye preparación de fixtures y varias peticiones por prueba.

Para la siguiente etapa, una vez aprobadas las 15 pruebas, configurar
un entorno **persistente PostgreSQL 18** con rol `app_backend`, datos
sintéticos, y un backend PHP separado apuntando solo a ese contenedor.
Comparar entonces al menos cinco tiempos reales `Server-Timing` del
`editor-bootstrap` con el entorno remoto (referencia 3522 ms).

## Consideración Docker/PostgreSQL 18

En la imagen oficial de PostgreSQL 18, el almacenamiento persistente
cambió a `/var/lib/postgresql/18/docker` bajo
`/var/lib/postgresql`. Para el **futuro** contenedor persistente se
debe montar un volumen en `/var/lib/postgresql`, no reutilizar el
punto de montaje de PostgreSQL 17 (`/var/lib/postgresql/data`).

Referencia oficial:
https://hub.docker.com/_/postgres

Esta versión efímera no monta un volumen, y se destruye por seguridad
al finalizar, por lo que no precisa una configuración de persistencia.

## Corrección tras primera ejecución real en Windows (2026-11-05)

La primera ejecución confirmó:

- **PostgreSQL 18.6** y rol `app_backend` sin `BYPASSRLS`.
- **Cinco tablas FORCE RLS** verificadas.
- **14 de 15 pruebas** completadas, **125 aserciones**, **2.68 s** de Pest.
- Contenedor temporal eliminado por `finally` después del error.

La única prueba falló con `SQLSTATE[42501]` al ejecutar un
`firstOrCreate` de `PEN` en la tabla global `currencies`. El rol
de aplicación tiene únicamente `SELECT` sobre ese catálogo, conforme
a la migración de referencia; **ese rechazo es correcto**.

### Corrección aplicada, sin ampliar permisos

- La prueba `keeps exact, historical and default multicurrency rates...`
  usa ahora `where('code', ...)->firstOrFail()` para `PEN`, `USD`, `EUR`.
  El test deja de intentar insertar datos de referencia como `app_backend`.
- El harness `-JournalAreaDimensionTest` comprueba primero la ausencia de
  `INSERT` sobre `public.currencies` y que `FORCE RLS` sigue activo.
- Solo dentro del **contenedor temporal Docker** instala las tres monedas
  oficiales usando el propietario `postgres` antes de ejecutar Pest.
  Comprueba que estén las tres y luego ejecuta los tests con `app_backend`.
- No cambia las migraciones del catálogo, la configuración de permisos del
  ERP, `.env`, Neon/Supabase, credenciales ni producción.

Desde una nueva terminal de PowerShell:

```powershell
cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
.\scripts\phase2-active-rls-docker.ps1 -JournalAreaDimensionTest
```

Resultado esperado, todavía pendiente de ejecución funcional tras este cambio:
**15 tests aprobados, 0 fallidos**, con borrado del contenedor. La
validación CI solo comprueba sintaxis PHP y PowerShell, no sustituye
la ejecución PostgreSQL 18 que debe hacerse en el equipo local.

## Validación funcional confirmada y siguiente etapa persistente

El usuario ejecutó en Windows el modo `-JournalAreaDimensionTest`
después de corregir la preparación del catálogo de monedas. Resultado
**verificado**:

- PostgreSQL **18.6** en contenedor efímero; `app_backend` sin `BYPASSRLS`.
- Cinco tablas con `FORCE RLS`.
- `currencies` sin permiso de escritura para `app_backend`.
- Monedas PEN/USD/EUR instaladas exclusivamente por el propietario.
- **15/15 tests PASS, 142 aserciones, duración Pest 2.48 s.**
- Contenedor descartable eliminado correctamente en `finally`.

El resultado confirma las pruebas funcionales; 2.48 segundos no son el
tiempo HTTP de apertura de pantalla.

### Preparación PostgreSQL 18 persistente (primera etapa, sin migraciones)

Se añadió `backend/scripts/iniciar-postgres18-local.ps1`. En una
**nueva terminal PowerShell**, ejecutar:

```powershell
cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
.\scripts\iniciar-postgres18-local.ps1 -Accion Crear
.\scripts\iniciar-postgres18-local.ps1 -Accion Estado
```

El script:
- Crea un contenedor `contabilidad-pg18-local` (Docker `postgres:18-alpine`)
  accesible solo desde `127.0.0.1:55433`.
- Usa el volumen persistente nombrado `contabilidad-pg18-local-data`
  montado en `/var/lib/postgresql` (estructura PostgreSQL 18).
- Genera una contraseña local aleatoria y la conserva en
  `backend/.env.pg18.local`, ignorado por Git. **No compartir este archivo**.
- Bloquea la inicialización si ya existe el contenedor, el volumen
  o el archivo local: no sobrescribe bases existentes.
- No cambia `backend/.env`, no conecta con Neon/Supabase y
  **no ejecuta migraciones ni crea usuarios o empresas**.
- Ofrece `-Accion Estado`, `-Accion Iniciar` y `-Accion Detener`;
  `Detener` conserva el volumen y sus datos.

**Atención:** el instalador se valida estáticamente en CI; aún debe
probarse en Windows. Si la instalación se detiene por puerto ocupado,
no modificar la base remota ni ejecutar manualmente migraciones generales.
Verificar y resolver el conflicto local primero.

### Próxima etapa posterior a una creación correcta

Aplicar las 25 migraciones **explícitas y revisadas**, únicamente a
`contabilidad_local` PostgreSQL 18, con preflight de conexión, puerto,
versión y rol. Instalar referencias con rol de propietario, y preparar
usuario, empresa y cuentas **sintéticos** con `app_backend` limitado,
sin copiar información contable real. Solo entonces iniciar el backend
en puerto separado y medir cinco `editor-bootstrap` desde el navegador.

Nunca ejecutar `php artisan migrate` general ni `migrate:fresh`
contra `backend/.env` (base remota).

## Esquema persistente PG18: instalación controlada

**Instalación Docker verificada por el usuario:** PostgreSQL **18.6** local,
contenedor `contabilidad-pg18-local`, volumen persistente
`contabilidad-pg18-local-data`, puerto `127.0.0.1:55433`.
Se creó `backend/.env.pg18.local` con la contraseña administrativa,
sin cambiar `backend/.env`. Al finalizar esa etapa el esquema estaba vacío.

### Instalador seguro publicado en main

Se añade `backend/scripts/instalar-esquema-pg18-local.ps1`.

```powershell
cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Plan
```

Si se ve `[ESTADO] ... = 0|0|0` y
`[PLAN] Base vacía: lista para 25 migraciones revisadas.`,
ejecutar la instalación **expresamente**:

```powershell
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Instalar
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Verificar
```

El script exige todos estos controles:

- Etiqueta Docker `contabilidad.local=pg18-dev`, nombre
  `contabilidad-pg18-local`, montaje del volumen esperado, puerto
  `127.0.0.1:55433`, identidad `contabilidad_local/postgres/PG18`.
- Archivo privado con conexión administrativa **exclusivamente local**.
- Base vacía y rol `app_backend` aún inexistente, sin
  `bootstrap/cache/config.php`.
- Antes de migrar, crea una tabla de guardia en el Docker
  previamente verificado y una marca aleatoria de 32 dígitos.
- Con la configuración de proceso temporal obliga **tanto** a
  `pgsql` como a `pgsql_migrations` a localhost; desactiva las URL
  de conexión heredadas de `.env`; comprueba el destino real desde PHP.
- Dos migraciones de endurecimiento de 2026-10-21 mantienen las guardas
  originales de Neon/Supabase/CI y admiten el caso **adicional** de la
  base local solo si el token de instalación coincide en PostgreSQL,
  el rol es `postgres`, el motor es PG18 y las configuraciones resueltas
  apuntan a `127.0.0.1:55433/contabilidad_local`.
- Ejecuta las 25 migraciones revisadas **una a una con `--path`**.
  Después de cada una comprueba la fila exacta en `public.migrations`.
  Oculta el texto SQL bruto ante errores para evitar exponer contraseñas.
- Instala PEN, USD y EUR como propietario y comprueba que
  `app_backend` conserva únicamente permisos de lectura en
  `currencies` y no tiene `BYPASSRLS`.
- Agrega las credenciales `app_backend` solo a
  `backend/.env.pg18.local`, protegido por `.gitignore`.
  **No copiar ese archivo al chat ni a GitHub.**
- Recupera las variables de entorno anteriores en un bloque `finally`.
  Una instalación parcial de una base persistente **no se borra ni
  se reintenta automáticamente**; si falla, investigar el punto exacto
  sin ejecutar `migrate:fresh` ni migraciones generales.

`Plan` y `Verificar` no ejecutan escrituras.

**Estado de ejecución:** código y validaciones sintácticas preparados.
La instalación real en el Windows del usuario es la próxima prueba.
Ni el repositorio GitHub ni sus acciones CI tienen acceso a Docker
local ni pueden comprobar los datos hasta esa ejecución.

### Etapa siguiente

Tras verificar las 25 migraciones y RLS en la base persistente,
crear datos **sintéticos** suficientes para el editor del Libro Diario:
un usuario de prueba con Sanctum real, empresa, membresía,
monedas PEN/USD, tasas de cambio, subdiarios y plan de cuentas.
Preparar un backend Laravel de medición aislado en otro puerto,
sin cambiar el servicio actual contra Neon. Comparar medianas de
al menos cinco solicitudes HTTP `editor-bootstrap`.

## Recuperación controlada tras fallo de preflight (2026-11-06)

El usuario ejecutó `-Accion Plan` con el contenedor persistente
`contabilidad-pg18-local` y obtuvo `0|0|0`. El primer
`-Accion Instalar` creó correctamente la tabla
`local_pg18_install_guard` y los roles auxiliares
`anon` y `authenticated` (ambos `NOLOGIN`), pero se detuvo
en el preflight Laravel **antes de ejecutar migraciones**.

### Causa identificada en el instalador

Se usaba `-notmatch '^[PASS] Laravel...'` para verificar un texto que
comienza literalmente por `[PASS]`. En expresiones regulares,
`[PASS]` es una clase de caracteres y **no** coincide con la cadena
literal `[PASS]`. Esa condición rechazaba incluso una respuesta
correcta. La salida interna de PHP se ocultaba por seguridad, de modo
que no puede descartarse otro error independiente.

**Correcciones en main:**
- `php -r` sustituido por
  `backend/scripts/verificar-preflight-pg18-local.php`: preflight
  CLI robusto, valida ambas conexiones resueltas, rol, motor y token,
  no imprime secretos ni SQL;
- el resultado se comprueba mediante **comparación literal exacta**,
  no regex ambigua;
- `-Accion Recuperar` permite reanudar solo tras comprobar un estado
  parcial específico: exactamente **una tabla** (la guardia),
  **una fila** de guardia, ambos roles auxiliares sin capacidad LOGIN,
  ningún `app_backend` y ninguna tabla de migraciones.
  Además revalida el nombre/etiqueta Docker, puerto/volumen,
  base `contabilidad_local` y PostgreSQL 18;
- la recuperación **renueva el token de la única fila de guardia**;
  no ejecuta `DROP`, no borra el volumen y no reescribe datos contables;
- si hay cualquier otro estado, se interrumpe sin intentar reparar
  automáticamente. Si alguna migración futura falla, analizar la base
  parcial; no repetir `Instalar` ni `Recuperar` ciegamente.

### Comandos en Windows

En una ventana PowerShell limpia:

```powershell
cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Plan
```

Si devuelve `[ESTADO] ... = 1|0|0` y
`[PASS] Estado parcial exacto verificado`, entonces:

```powershell
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Recuperar
```

Si finaliza correctamente:

```powershell
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Verificar
```

Se espera `25|3|1|0|5` internamente (migraciones, monedas,
rol restringido, ausencia de INSERT en monedas, cinco tablas
`FORCE RLS`) y el mensaje `[PASS] 25 migraciones...`.

**Importante:** la corrección está validada por la sintaxis en CI,
pero aún no se ha ejecutado el flujo de recuperación funcional sobre
el Docker persistente del usuario. No publicar `.env.pg18.local`,
contraseñas, `DB_URL` ni trazas con SQL confidencial.

## PG18 persistente verificado: fixture sintético y banco de pruebas HTTP

La base persistente `contabilidad_local` se instaló en Docker PostgreSQL
**18.6** después de recuperar un preflight que se había detenido
antes de migrar. El usuario aportó pruebas de:

- **25/25 migraciones aplicadas** y validadas.
- Catálogo global `PEN/USD/EUR`, solo lectura para `app_backend`.
- Rol de aplicación `app_backend` limitado y 5 tablas `FORCE RLS`.
- `-Accion Verificar` volvió a confirmar el estado anterior.
- `backend/.env` remoto intacto; no se usó `migrate:fresh`.

### Objetivo siguiente: Libro Diario con Sanctum real y 200 cuentas

Se añadieron estos archivos a `main`:

- `backend/scripts/crear-fixture-pg18-local.php`: crea una empresa
  completamente ficticia, usuario con contraseña aleatoria, membresía
  `ADMIN`, configuración PEN/USD, subdiarios, áreas y **200 cuentas
  sintéticas operativas** con jerarquía completa de seis niveles.
  La creación utiliza únicamente el rol `app_backend` y
  `TenantContext::run` con RLS. No habilita `BYPASSRLS`.
  Conserva las credenciales de demostración en
  `backend/.env.pg18.demo.local` **antes de confirmar la transacción**,
  para poder revertir la base si no se puede escribir el archivo.
- `backend/scripts/preparar-demo-pg18-local.ps1`: permite ejecutar
  el fixture una sola vez, levantar el backend de prueba en
  `127.0.0.1:8011` sin sustituir el backend normal `8010` y
  medir cinco solicitudes HTTP `editor-bootstrap` con token Sanctum
  real. Las credenciales de usuario y token nunca aparecen en la salida.

**Protecciones del lanzador:** lee exclusivamente la configuración
local privada; comprueba nombre y etiqueta Docker, puerto `55433`,
volumen persistente, permisos de conexión y `app_backend`;
fija todas las conexiones `pgsql` y `pgsql_migrations` en
`127.0.0.1:55433` y usa el rol restringido también para
`pgsql_migrations` durante el banco de pruebas. No importa las
credenciales administrativas de Neon.

### Instrucciones Windows

En una nueva ventana PowerShell:

```powershell
cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Verificar
.\scripts\preparar-demo-pg18-local.ps1 -Accion Crear
```

El segundo comando se ejecuta **una sola vez** y rechaza instalar el
fixture si ya existe cualquier usuario, empresa o token local, o si
falta el esquema. No necesita permisos de propietario PostgreSQL.
No compartir el archivo `.env.pg18.demo.local`.

Si la creación pasa, en la misma terminal o en una segunda
ventana ejecuta:

```powershell
cd C:\Proyectos\contabilidad\backend
.\scripts\preparar-demo-pg18-local.ps1 -Accion Servidor
```

La consola queda ocupada por el servidor de prueba; detener con
`Ctrl+C` al finalizar. El servidor solo escucha en
`http://127.0.0.1:8011` y usa `contabilidad_local`.

En **otra ventana PowerShell**:

```powershell
cd C:\Proyectos\contabilidad\backend
.\scripts\preparar-demo-pg18-local.ps1 -Accion Medir
```

Este comando inicia sesión contra el backend **local** con credenciales
ficticias, usa el token Sanctum y `X-Company-Id` de la empresa creada,
solicita `editor-bootstrap` cinco veces y muestra medianas de
`request_total`, `auth_sql_total`, `catalogs_sql`.
La solicitud GET es de solo lectura, aunque el inicio de sesión
crea el token de prueba y sus registros de auditoría.
Los tiempos deben compararse con el registro remoto de
`request_total=3522.1` ms, **sin confundir datasets diferentes**:
el remoto tiene sus propias cuentas y distribuciones.

### Validaciones posteriores

Si las pruebas HTTP pasan, confirmar acceso de Vue al backend de
laboratorio mediante `VITE_API_PROXY_TARGET=http://127.0.0.1:8011`
en una instancia de frontend separada, sin reutilizar sesiones
de empresas reales. No se configura todavía un cambio de
la base remota ni se propone copiar información productiva.

**Estado:** los archivos pasan por verificación sintáctica CI;
su ejecución end-to-end en Docker/Windows queda pendiente hasta
que el usuario ejecute los comandos y envíe el resultado.

## Corrección de fixture PG18 al iniciar demostración (2026-11-06)

El usuario completó la instalación de PostgreSQL 18.6 persistente,
25 migraciones, tres monedas y cinco tablas FORCE RLS. La primera
ejecución de `preparar-demo-pg18-local.ps1 -Accion Crear` devolvió
solo `Fixture PG18 no completada: RuntimeException`, sin aportar
la causa interna.

Se identificaron y corrigieron **dos riesgos reales** en la fixture:

1. Se intentaban crear cuentas de hasta **nivel 6** cuando la
   migración del plan de cuentas exige `level BETWEEN 1 AND 4`
   y `is_operational` únicamente en nivel 4. Ahora el árbol
   sintético genera 3 cuentas jerárquicas no imputables
   (`1 -> 12 -> 121`) y **200 operativas de nivel 4 con
   códigos de seis dígitos**, sin alterar la migración.
2. El lanzador dependía de `APP_KEY` heredado de
   `backend/.env`, que puede faltar o no ser apropiado
   para el laboratorio. Ahora genera una clave aleatoria
   independiente de 256 bits y la conserva en
   `backend/.env.pg18.local` como `LOCAL_DEMO_APP_KEY`,
   que se aplica a las tres acciones de la demostración.
   No modifica `.env` remoto ni la conexión Neon.

También se añadieron **códigos de error seguros por fase** a
`crear-fixture-pg18-local.php` (incluido SQLSTATE sin SQL
ni parámetros), por ejemplo `fase=CONFIG; motivo=LOCAL_CONFIG`.
La excepción original `RuntimeException` no identifica por sí
sola qué validación falló; la corrección de la clave es preventiva,
no una causa demostrada.

### Cómo reintentar una sola vez

```powershell
cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Verificar
.\scripts\preparar-demo-pg18-local.ps1 -Accion Crear
```

Si `Crear` falla, no ejecutar migraciones ni borrar volúmenes.
Compartir solo la línea `[FAIL] Fixture PG18: fase=...; motivo=...;
tipo=...; SQLSTATE=...`, sin secretos ni archivos privados.
Si pasa, seguir con `-Accion Servidor` y `-Accion Medir`
según la sección anterior.

**Estado:** correcciones publicadas; la creación de la fixture
y las cinco mediciones HTTP deben confirmarse todavía en Windows.

## Corrección DB_STATE: SQLSTATE 42501 por catálogo de migraciones

Al ejecutar `preparar-demo-pg18-local.ps1 -Accion Crear`, con
el esquema persistente correctamente verificado, el usuario reportó:
`fase=DB_STATE; tipo=Illuminate\\Database\\QueryException; SQLSTATE=42501`.

**Causa identificada:** la consulta de preflight del fixture PHP
incluía `SELECT count(*) FROM public.migrations`, ejecutada con el
rol de aplicación restringido `app_backend`. PostgreSQL denegó
correctamente ese permiso: `migrations` es una tabla administrativa.
El fallo ocurrió **antes de iniciar la transacción de creación**
de usuario, empresa y cuentas.

**Corrección:**
- El fixture PHP conserva sus comprobaciones de conexión y datos de
  aplicación, pero **ya no consulta `public.migrations`**.
- El lanzador PowerShell invoca ahora `Check-InstalledSchema` solo
  dentro del contenedor Docker de identidad y volumen comprobados,
  mediante `psql -U postgres`, y valida las 25 migraciones, tres
  monedas, rol `app_backend` restringido, falta de INSERT sobre
  `currencies` y cinco tablas FORCE RLS antes de ejecutar PHP.
- **No se ha otorgado ningún permiso sobre `migrations` a
  `app_backend`**. No se modificaron migraciones ni `backend/.env`.

Tras `git pull --ff-only`, desde `backend` ejecutar
`./scripts/preparar-demo-pg18-local.ps1 -Accion Crear` (en
PowerShell, usar `.\\scripts\\preparar-demo-pg18-local.ps1`).
No reinstalar el esquema ni eliminar el volumen. Si la demo
vuelve a fallar, compartir **solo** la línea diagnóstica segura
`fase=...; motivo=...; tipo=...; SQLSTATE=...` antes de reintentar.

**Estado:** corrección de código preparada y sujeto a verificación
estática CI; la creación real de datos ficticios queda pendiente en
Windows. El servidor Laravel 8011 y las cinco mediciones HTTP tampoco
se han ejecutado aún.

## Login local 8011: membresía ausente por política RLS de lectura (2026-11-06)

Después de crear la empresa ficticia y usuario con 200 cuentas operativas,
el usuario abrió el Laravel local en `127.0.0.1:8011` e intentó
`preparar-demo-pg18-local.ps1 -Accion Medir`. El login devolvió una
identidad y empresa actual válidas, pero `data.companies` no contenía
la membresía esperada. El medidor detuvo las solicitudes antes de
consultar el Libro Diario.

**Causa verificada en el repositorio:** la migración original
`backend/database/migrations/2026_10_20_010000_scope_company_listing_to_authenticated_user.php`
agrega la política `company_user_authenticated_member_listing_policy`,
una política `SELECT` que permite consultar exclusivamente las filas
con `user_id = current_setting('app.current_user_id', true)::bigint`
dentro de una transacción. Esa migración estaba restringida
deliberadamente a un entorno antiguo y **no** estaba entre las
25 migraciones locales. La política base `company_user_tenant_isolation_policy`
es por `app.current_company_id`; no permite listar empresas
antes de que exista una empresa activa en la transacción.

### Solución local separada, sin modificar producción

El script `backend/scripts/habilitar-listado-empresas-pg18-local.ps1`
replica solo el SQL **SELECT** autorizado de aquella migración, pero
con guardas específicas y explícitas del laboratorio PostgreSQL 18:

- En `-Accion Verificar` no hace escrituras.
- Comprueba Docker Linux, nombre, etiqueta `pg18-dev`, puerto
  `127.0.0.1:55433`, volumen `contabilidad-pg18-local-data`,
  motor PostgreSQL 18 y base `contabilidad_local`.
- Comprueba 25 migraciones, un usuario y una empresa ficticios,
  exactamente una membresía activa, rol limitado `app_backend`,
  `FORCE RLS` y la política de tenant original activa.
- En `-Accion Aplicar` instala exclusivamente
  `company_user_authenticated_member_listing_policy` para
  `SELECT TO app_backend`. No concede privilegios DML,
  no altera políticas existentes y no usa Laravel ni Neon.
- Verifica con el rol restringido en una transacción que el usuario
  vea la membresía propia y que otro identificador no vea filas.
- La instalación es idempotente: si la política existe y supera
  la validación, no la crea por segunda vez.

### Pasos en Windows

Mantener cerrado únicamente el proceso Laravel `8011` mientras
se actualiza código; el contenedor PostgreSQL **debe permanecer
encendido**. En PowerShell:

```powershell
cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
.\scripts\habilitar-listado-empresas-pg18-local.ps1 -Accion Verificar
```

Si confirma que falta la política y las comprobaciones pasan:

```powershell
.\scripts\habilitar-listado-empresas-pg18-local.ps1 -Accion Aplicar
.\scripts\habilitar-listado-empresas-pg18-local.ps1 -Accion Verificar
```

Luego reiniciar el servidor Laravel en `8011` con
`preparar-demo-pg18-local.ps1 -Accion Servidor` y, en otra
terminal, ejecutar `preparar-demo-pg18-local.ps1 -Accion Medir`.

**No volver a ejecutar `-Accion Crear`** ni instalar nuevas
migraciones generales. Si hay error en las validaciones,
no realizar modificaciones manuales de RLS: conservar el
estado actual y compartir solo la salida sin credenciales.

**Estado:** la corrección se publicó en GitHub; las comprobaciones
de sintaxis en CI y el ensayo real contra Docker local deben
confirmarse antes de considerar el flujo resuelto.
