# PCGE completo y datos de prueba sobre PostgreSQL 18 Docker (no Neon)

El laboratorio existente tiene 28 migraciones, una empresa `20999990099`,
un usuario `demo.pg18@example.test`, 203 cuentas ficticias y una tasa USD/PEN.
**No** es una copia de Neon. Los datos sintéticos no permiten probar
correctamente todo el sistema. No usar `db:seed` genérico ni borrar la
empresa ficticia; es necesaria para las pruebas originales de latencia/RLS.

## Flujo seguro (base Docker exclusiva)

Desde PowerShell, dentro de `backend` y después de actualizar `main`:

```powershell
.\scripts\preparar-pcge-pg18-local.ps1 -Accion Plan
```

Con la inspección previa del plan y respaldo local, ejecutar **cada paso
únicamente en el contenedor** `contabilidad-pg18-local`:

```powershell
.\scripts\preparar-pcge-pg18-local.ps1 -Accion CargarReferencias
.\scripts\preparar-pcge-pg18-local.ps1 -Accion CrearEmpresa
.\scripts\preparar-pcge-pg18-local.ps1 -Accion Verificar
```

Ambos pasos de escritura crean antes un respaldo `pg_dump -Fc` en
`%LOCALAPPDATA%\Contabilidad\Backups`. Si el respaldo no existe, no
escriben. El programa valida etiqueta `pg18-dev`, nombre, volumen, puerto
`127.0.0.1:55433`, PostgreSQL 18 y FORCE RLS. Los comandos PHP requieren
la configuración exacta de Docker, provista por el archivo privado
`backend/.env.pg18.local`. Todas las variables de proceso se restauran al
terminar. Ninguno cambia `backend/.env` ni se conecta a Neon.

### Etapa de catálogos

`CargarReferencias` preserva las tres monedas que ya creó el instalador
Docker y carga *transaccionalmente* las 12 referencias faltantes de F2
a partir de los seeders y archivos versionados en Git. Exige coincidencia
de SHA Git blob con `database/reference_data/manifest.json` y crea el
registro `reference_data_versions` mediante la migración portable
específica. El resultado esperado son:

- 3 monedas (conservadas)
- 9 catálogos SUNAT básicos (163 entradas)
- 43 registros de normas NIC/NIIF y 48 rubros
- 3.401 plantillas PCGE: 1.764 del PCGE 2019 + 1.637 del PCGE 2026

**No se ejecutan las migraciones históricas SEE/PLE** con dependencias
especiales, ni se sobrescriben las cuentas del laboratorio.

### Empresa de prueba con PCGE

`CrearEmpresa` utiliza únicamente el rol de aplicación `app_backend`
con RLS y crea una **segunda empresa claramente ficticia**,
`EMPRESA DEMO PCGE 2019 - SOLO DOCKER`, para el mismo usuario
`demo.pg18@example.test` con su contraseña actual, sin cambiarla.

En una única transacción crea asociación ADMIN, configuraciones PEN/USD,
ocho subdiarios, noviembre de 2026 abierto, dos áreas organizacionales,
dos áreas de flujo, dos terceros de prueba marcados como ficticios, un
perfil fiscal de laboratorio y el plan PCGE 2019 completo, incluidos los
desdoblamientos operativos a seis dígitos, cuentas analíticas privadas y
centros de costo. Conserva la primera empresa y sus cuentas ficticias.

**Cuidado:** la nueva empresa no recibe un tipo de cambio inventado. Se
debe registrar un USD/PEN **verificado** para la fecha del comprobante
en la propia empresa, ya que las tasas están aisladas por tenant.

## Limitaciones y verificación funcional

El PCGE y los catálogos básicos aquí instalados son los mismos *archivos
fuente versionados* que usa el repositorio para Neon DEV; **NO** son una
sincronización automática de compras, terceros, usuarios reales, asientos,
ni de las tablas SEE/PLE posteriores. No se crean comprobantes falsos
ni se realizan envíos a SUNAT.

La empresa ficticia permite probar la navegación PCGE, registros de
compras/ventas, períodos, entidades, monedas y asientos, pero puede ser
necesario configurar cuentas automáticas de Compras/Ventas desde la
interfaz antes de contabilizar. Los catálogos reguladores SEE/PLE
posteriores siguen pendientes de un instalador propio.

Tras verificar en Docker y reiniciar Laravel conectado a
`contabilidad_local` como `app_backend`, cerrar sesión y volver a
ingresar con `demo.pg18@example.test` y la contraseña privada existente
de `backend/.env.pg18.demo.local`. El usuario deberá encontrarse en
la nueva empresa PCGE y podrá volver a seleccionar la empresa del
laboratorio cuando quiera.

CI comprueba sintaxis PowerShell y PHP, sin acceso al contenedor
Windows del usuario. **La primera ejecución real debe hacerse leyendo
el resultado de `Plan` y detenerse si el script informa BLOQUEO.**
