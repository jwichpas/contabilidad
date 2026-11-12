# PostgreSQL 18 local (Docker): ampliación protegida de 25 a 61 migraciones

## Estado detectado el 12/11/2026

\`\`\`text
contabilidad-pg18-local Up ... 127.0.0.1:55433->5432/tcp
Verificar instalador inicial: PASS, 25 migraciones
Tablas public: 37; app_backend: existe
Empresas: 1; usuarios: 1; tipos de cambio: 1
Compras/Ventas y sus registros tributarios: todavía ausentes
\`\`\`

El instalador \`instalar-esquema-pg18-local.ps1\` **solo sirve para crear una
base vacía y verificar el baseline de 25 migraciones**. Su respuesta
\`[PLAN] Hay esquema parcial o un estado diferente\` es un bloqueo correcto;
NO indica corrupción y NO autoriza \`-Accion Instalar\` ni \`Recuperar\`.

## Actualizador aditivo (desde main, no sobre la base Neon)

Este repositorio incluye un **segundo script**, no sustituye al instalador
inicial: \`scripts/actualizar-esquema-pg18-local.ps1\`.

Primero revisar \`git status\` y actualizar \`main\`, conservando cambios
locales. En \`backend\`:

\`\`\`powershell
.\scripts\iniciar-postgres18-local.ps1 -Accion Estado
.\scripts\instalar-esquema-pg18-local.ps1 -Accion Verificar
.\scripts\actualizar-esquema-pg18-local.ps1 -Accion Plan
\`\`\`

El Plan consulta exclusivamente SQL de catálogo en **Docker local** y muestra
las 36 migraciones adicionales que faltan; no usa credenciales de Neon,
no ejecuta Laravel ni realiza escrituras.

Una vez revisado el plan y autorizado el cambio en esta base local:

\`\`\`powershell
.\scripts\actualizar-esquema-pg18-local.ps1 -Accion Aplicar
.\scripts\actualizar-esquema-pg18-local.ps1 -Accion Verificar
\`\`\`

### Seguridad antes de modificar la base local

\`Aplicar\` bloquea la ejecución salvo que se cumplan **todas** estas
condiciones: contenedor \`contabilidad-pg18-local\`, etiqueta \`pg18-dev\`,
volumen \`contabilidad-pg18-local-data\`, mapeo exacto \`127.0.0.1:55433\`,
base \`contabilidad_local\`, PostgreSQL 18, 5 tablas con FORCE RLS,
historial exacto de 25 migraciones sin extras, credenciales locales
\`backend/.env.pg18.local\` y configuración Laravel sin caché. La segunda
guardia PHP verifica las **conexiones efectivas** antes de escribir.

Se crea automáticamente un \`pg_dump\` binario personalizado en
\`%LOCALAPPDATA%\Contabilidad\Backups\` **fuera del repositorio** antes de la
primera migración. Nunca contiene ni imprime contraseñas en el resultado.
Si falla el respaldo no comienza ningún cambio. Después aplica **solo las
36 migraciones explícitamente permitidas**, una por una, verificando cada
registro en \`public.migrations\`. Ante cualquier fallo se detiene. No se
reintenta una actualización parcialmente aplicada de forma automática.

Las actualizaciones incluyen el perfil fiscal por empresa; catálogo SEE,
versiones PLE/INEI y referencias; libros de Compras/Ventas y registros internos
RCE/RVIE. **No** ejecuta cinco migraciones antiguas exclusivas de Supabase,
ni migraciones especiales de Neon (\`database/neon_migrations\` y las
migraciones portables condicionadas a credenciales/host Neon).
El registro opcional \`portable_migrations/2026_10_21_040000...\` sigue
fuera del baseline y de esta actualización, como en Neon DEV.

Se conservarán las empresas, los usuarios y las tasas locales existentes.
**NO crea ninguna sincronización entre Docker y Neon.**

### Después de actualizar

Para desarrollo, configurar \`backend/.env\` con la conexión local
\`DB_HOST=127.0.0.1\`, \`DB_PORT=55433\`,
\`DB_DATABASE=contabilidad_local\`, \`DB_USERNAME=app_backend\`,
\`DB_SSLMODE=disable\`, \`DB_URL=\`, \`DATABASE_URL=\`.
Usar **solo** la contraseña de la aplicación local que está en
\`backend/.env.pg18.local\`. La contraseña del propietario \`postgres\`
permanece exclusivamente en el bloque \`DB_MIGRATIONS_*\`; nunca usarla
para las peticiones HTTP normales.

Guardar copia privada del \`.env\` de Neon **fuera de Git** antes de sustituir
los valores. Después \`php artisan config:clear\`, reiniciar Laravel, verificar
que el servidor resuelve \`contabilidad_local\` y \`app_backend\`.

El usuario/empresa local son un **fixture independiente** (una empresa,
un usuario y una tasa) y no representan la información real de Neon. Si
se necesitan las empresas y los terceros reales debe diseñarse por separado
una restauración de Neon a local con desidentificación de información
personal, control de versiones del esquema y validación RLS.

### Qué no ejecutar

- No usar \`php artisan migrate\` general, \`migrate:fresh\`,
  \`migrate:refresh\`, \`db:seed\` general, \`docker volume rm\` ni
  \`instalar-esquema-pg18-local.ps1 -Accion Instalar\` sobre esta base poblada.
- No conectar el rol de aplicación al propietario del esquema.
- No ejecutar un script de Neon contra Docker ni configurar el host de
  migraciones con Neon mientras se actualiza Docker.

> Verificación disponible en CI: sintaxis PowerShell y PHP.
> Falta ejecutar las 36 migraciones en el contenedor local del usuario y
> realizar pruebas de integración/funcionamiento de los módulos.
