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
