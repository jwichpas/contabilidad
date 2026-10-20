# Fase 0 — Plan de recuperación y continuidad (sin ejecución)

> Proyecto: `jwichpas/contabilidad` · Supabase: `contabilidad-database` · Base actual: **única y con datos de desarrollo importantes**  
> Estado al 20/10/2026: **propuesta documental**, sin copia ni restauración realizada.  
> Regla: **no ejecutar `pg_dump`, `pg_restore`, `migrate:fresh`, `DROP`, `DELETE` masivos, ni restauración de Dashboard** sin autorización expresa y destino separado revisado.

## Primer ensayo de recuperación y corrección de inicialización (20/10/2026)

El usuario comprobó que Docker opera con motor Linux. El ensayo confirmó la huella SHA-256 y pudo crear un contenedor PostgreSQL 17 `--network none`; falló al ejecutar el `CREATE SCHEMA public;` del dump porque el esquema `public` ya viene creado en una base PostgreSQL recién inicializada. La limpieza `docker rm --force` terminó correctamente. **La restauración NO se completó.**

Se corrigió `backend/scripts/phase0-restore-rehearsal.ps1` en commit `86d1688`: una vez iniciado el contenedor aislado, elimina **solo `public` vacío** mediante `docker exec ... DROP SCHEMA public RESTRICT;` (sin CASCADE) para que lo recree el dump. El cambio **solo se aplica dentro del contenedor temporal sin red/puertos**, nunca en Supabase ni en el equipo local. Reintento aún pendiente.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only
cd backend
.\scripts\phase0-restore-rehearsal.ps1 -Archive "C:\CONTA\contabilidad-public-20261020T210205261Z.dump"
```

La comprobación `Get-BitLockerVolume -MountPoint "C:"` **no pudo obtener el estado del volumen** (acceso CIM denegado y mensaje de volumen BitLocker no asociado). Para verificar desde terminal con permisos administrativos:

```powershell
manage-bde -status C:
```

La información de `Protection Status` / `Conversion Status` determina si BitLocker protege `C:`. En Windows con Cifrado de dispositivo, verificar adicionalmente su interfaz de Configuración. **No asumir cifrado activo por haber pasado `-EncryptedDestinationConfirmed`**. Si C: no está cifrada, custodiar los tres archivos del respaldo en un volumen cifrado fuera de Git. El ensayo de Docker no certifica esta protección.

## Evidencia de respaldo generado en Windows — 20/10/2026

**Salida local aportada por el usuario:** se ejecutó `backend/scripts/phase0-backup-windows.ps1 -Destination "C:\CONTA" -EncryptedDestinationConfirmed`. El script informó **`[PASS]`** en `pg_dump` (PostgreSQL cliente 17), creación de inventario con `pg_restore --list`, huella SHA-256 y manifiesto. Esta evidencia es del equipo del usuario; **no tenemos acceso desde aquí a los bytes ni al archivo local**.

- Archivo origen: `C:\CONTA\contabilidad-public-20261020T210205261Z.dump`
- Índice: `C:\CONTA\contabilidad-public-20261020T210205261Z.dump.list`
- Manifiesto: `C:\CONTA\contabilidad-public-20261020T210205261Z.dump.manifest.json`
- SHA-256 comunicado: `8896DCC5F0B10279F95D6C8F55E32BD9278F217676C7953E7D2E081E617E423E`

**Estado de control:** **BACKUP CREADO / RESTAURACIÓN NO PROBADA**. El flag `-EncryptedDestinationConfirmed` requiere una afirmación manual: no verifica BitLocker ni permisos ACL. Verificar protección real de la unidad `C:`, acceso restringido a `C:\CONTA` y custodia del respaldo sin compartir los archivos en GitHub ni en el chat. No asumir que una carpeta ordinaria está cifrada.

**Próximo paso operativo autorizado, sin conexión a Supabase ni escritura en origen:**

```powershell
cd C:\Proyectos\contabilidad\backend
docker info --format '{{.OSType}}'
.\scripts\phase0-restore-rehearsal.ps1 -Archive "C:\CONTA\contabilidad-public-20261020T210205261Z.dump"
```

El segundo comando necesita Docker Desktop con contenedores Linux e imagen `postgres:17-alpine`. Solo creará/restaurará un contenedor temporal aislado por `--network none`, sin puertos publicados; el respaldo local no se altera. Si faltan herramientas o ocurre un error, no reintentar con otra BD: registrar salida y diagnosticar primero. **No se ha recibido todavía resultado de restauración**.

## Autorización nueva y transición futura a PostgreSQL independiente — 20/10/2026

**Decisión explícita del usuario:** autoriza preparar y ejecutar un respaldo de la base Supabase de desarrollo y un simulacro de restauración fuera de la base activa. También establece que **Supabase se usará solo en desarrollo**; producción operará en **PostgreSQL independiente**. **Esto no autoriza ejecutar migraciones destructivas en el entorno de desarrollo ni migrar hoy a producción.**

**Estado real:** scripts creados en GitHub/main, **ningún archivo de respaldo generado en el equipo del usuario desde el chat y ninguna restauración ejecutada todavía**. Para completar la ejecución en Windows hacen falta PostgreSQL client 17 (`pg_dump`, `pg_restore`, `psql`), una carpeta **existente en una unidad cifrada y fuera de Git**, credencial administrativa Supabase introducida directamente en pantalla de manera privada, y Docker Desktop (contenedores Linux) para el ensayo.

### A. Preparación y exportación desde Windows — solo lectura de Supabase

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only
cd backend

.\scripts\phase0-backup-preflight.ps1 -Destination "D:\RespaldosCifrados\contabilidad"

# SOLO SI el volumen/carpeta existe, tiene cifrado efectivo y acceso restringido:
.\scripts\phase0-backup-windows.ps1 -Destination "D:\RespaldosCifrados\contabilidad" -EncryptedDestinationConfirmed
```

La ruta es **un ejemplo**, no se debe crear ni asumir que `D:` existe o está cifrado. El backup usa **puerto 5432** de Supabase (pooler sesión o conexión directa) y el rol administrativo `postgres`; valida proyecto, host, usuario y base, solicita la contraseña mediante `Read-Host -AsSecureString` (sin enviarla a ChatGPT, PowerShell arguments, Git o archivos de proyecto), exporta `public` con `pg_dump --format=custom --no-owner --no-acl` y genera:

- `contabilidad-public-<fecha>.dump`: archivo custom con esquema `public` y datos, **sensibles**.
- `contabilidad-public-<fecha>.dump.list`: índice legible con `pg_restore --list`.
- `contabilidad-public-<fecha>.dump.manifest.json`: SHA-256 y conteos fuente obtenidos con SELECT **después** de generar el dump; si la BD cambia durante la captura, dichos conteos pueden no reflejar exactamente el snapshot.
 
**No** ejecutar `pg_dump` desde Docker con credenciales que no conozcas ni introducir la contraseña en línea de comandos o en la conversación. La presencia de hash e índice no equivale a un restore exitoso.

### B. Simulacro sin red y sin alterar Supabase

**Tras obtener el archivo .dump** y su manifiesto, con Docker Desktop encendido:

```powershell
.\scripts\phase0-restore-rehearsal.ps1 -Archive "D:\RespaldosCifrados\contabilidad\contabilidad-public-AAAA...dump"
```

El script valida SHA-256, crea un **Docker temporal** `postgres:17-alpine` con `--network none`, **sin publicar puertos**, y un volumen de datos de memoria temporal. En ese servidor aislado crea únicamente roles ficticios sin inicio de sesión para satisfacer políticas RLS exportadas, restaura mediante `pg_restore --no-owner --no-acl --exit-on-error`, compara conteos de empresas, usuarios, membresías, cuentas, asientos, partidas y migraciones, verifica al menos 14 tablas RLS forzadas y 5 funciones `public`. Por último **destruye el contenedor temporal**, incluso si falla. **La única base Supabase nunca es destino del restore.**

La restauración puede fallar si el esquema exportado depende de características exclusivas de Supabase (por ejemplo, extensiones o roles fuera del alcance `public`). Si falla, **no forzar la importación ni relajar la seguridad**: revisar compatibilidad y registrarla antes de declarar éxito. Si otro proceso escribió asientos mientras se hacía el dump, los conteos origen posteriores pueden diferir de la instantánea exportada.

### C. Alcance de la portabilidad a PostgreSQL de producción

La organización usa **Supabase Free**, PostgreSQL **17.11**. Las extensiones instaladas en el servidor de desarrollo incluyen `pgcrypto` y `uuid-ossp` (esquema `extensions`), `pg_stat_statements` y `supabase_vault` (esquema `vault`); ninguna se debe presumir disponible en PostgreSQL externo sin validación. Se detectaron defaults en `public` que utilizan `gen_random_uuid()`, función incluida en PostgreSQL 17.

**No se traslada automáticamente lo siguiente:** Supabase Authentication `auth.users`, Storage y sus objetos, extensiones internas Supabase, contraseñas de roles, GRANTs omitidos por `--no-acl`, secretos ni configuración del pooler Supavisor. El ERP autentica con **Laravel Sanctum** en `public.users`; el entorno de producción requerirá su propia autenticación, aprovisionamiento `app_backend`, GRANTs y RLS, y separación `pgsql` (operacional) / `pgsql_migrations` (administrativo), todo versionado en migraciones/configuración. En PostgreSQL independiente podrán usarse prepared statements nativos: **evaluar** `DB_EMULATE_PREPARES=false` solo tras pruebas de compatibilidad y sin cambiar desarrollo actual.

Una restauración exitosa de `public` constituye **prueba de recuperabilidad de datos y esquema contable**, no certifica todavía continuidad integral de la aplicación (roles reales, autorizaciones de todos los endpoints, secretos, gestión de sesiones ni despliegue productivo).

**Referencia de Supabase para migraciones de plataforma a self-hosted:** https://supabase.com/docs/guides/self-hosting/restore-from-platform . Nuestro destino futuro es PostgreSQL independiente, **no** una réplica self-hosted completa de Supabase.

## Hallazgo verificado el 20/10/2026 — plan Free, sin garantía de copia administrada

La consulta a la **organización real de Supabase**, `grupo ichpas`, informó `plan=free` / `tier=tier_free`; la API del proyecto confirmó `contabilidad-database` con `status=ACTIVE_HEALTHY`, PostgreSQL `17.11` y región `ca-central-1`. El tamaño total aproximado de PostgreSQL informado por `pg_database_size` fue **17 MB**. Son datos de configuración observados, no un backup.

Según [la documentación oficial de Supabase](https://supabase.com/docs/guides/platform/backups), las copias diarias automáticas se ofrecen en **Pro, Team y Enterprise**; en **Free** Supabase recomienda exportaciones externas periódicas. **NO hay evidencia de una copia recuperable** para este proyecto. La capacidad o no de restauración debe comprobarse en el [Dashboard > Database > Backups](https://supabase.com/dashboard/project/bnjwxphwtkhblvhsnylj/database/backups/scheduled) o por una exportación autorizada y probada.

**Consecuencia:** no marcar F0 como aprobada por tener un script `pg_dump` escrito o por los rollbacks de pruebas. Tampoco ejecutar migraciones de seguridad (p. ej. endurecimiento de `search_path`) hasta definir y verificar una recuperación real. El tamaño de ~17 MB no incluye objetos externos que puedan estar en Storage.

**Herramienta Windows incorporada en GitHub/main:** `backend/scripts/phase0-backup-preflight.ps1`. Se limita a inspeccionar presencia y versión de `pg_dump`/`pg_restore`, existencia (sin imprimir valor) de variables administrativas y carpeta externa propuesta; **no conecta a la BD, no genera archivos ni ejecuta backup**.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only
cd backend
.\scripts\phase0-backup-preflight.ps1
# Opcional: -Destination "D:\RespaldosCifrados" (una carpeta ya existente, fuera del repo)
```

**Autorización concedida el 20/10/2026** para copia lógica y simulacro aislado. Pendiente: verificar que el usuario disponga de unidad cifrada, herramientas y Docker, ejecutar los scripts, conservar evidencia y aprobar custodia/RPO/RTO. `public` contiene el ERP pero un `--schema=public` no incluye todo el sistema administrado por Supabase.

## 1. Objetivo y alcance

Evitar pérdida accidental de datos y disponer de una ruta de recuperación comprobable antes de hacer nuevas migraciones o pruebas destructivas. Esto **no presupone** que existan copias automáticas; su disponibilidad depende del plan real de Supabase y de su estado de respaldo, aún por confirmar.

El usuario había decidido no generar respaldos en una fase anterior, pero el **20/10/2026 autorizó explícitamente** preparar y realizar copia de desarrollo y simulacro aislado. Todavía deben ejecutarse en Windows y registrarse sus resultados; no existe una recuperación certificada.

## 2. Línea base verificable

La última comprobación SQL y las pruebas HTTP/transaccionales confirmaron:

| Control | Valor |
|---|---:|
| Empresas | 1 |
| Usuarios | 1 |
| Membresías | 1 |
| Cuentas del plan | 1.764 |
| Tokens Sanctum | 28 |
| Auditorías | 34 |
| Asientos | 2 |
| Partidas | 4 |
| Migraciones | 18 |

Son **valores de referencia históricos**, no cantidades que deban forzarse si la aplicación crece. Las dos pruebas de inserción transaccional ejecutadas culminaron con `ROLLBACK`; **eso no es un respaldo ni una restauración**.

## 3. Fuentes posibles de recuperación

### A. Backups administrados por Supabase

1. Verificar manualmente en Dashboard > Database > Backups la disponibilidad de copias y el plan del proyecto. No inferirlo desde el plan ChatGPT.
2. Supabase documenta copias diarias para proyectos Pro, Team y Enterprise, con retención variable; PITR depende de la configuración y el coste.
3. **No pulsar restaurar** en la base actual para comprobar que funciona. El proceso puede dejarla inaccesible durante la restauración.
4. Las copias de base de datos **no incluyen los objetos de Storage**, solo sus metadatos; tampoco conservan contraseñas de roles personalizados en las copias diarias. Una recuperación debe incluir restablecimiento seguro de credenciales y comprobación de Storage cuando se use.

Referencia oficial: https://supabase.com/docs/guides/platform/backups

### B. Exportación lógica controlada

Existe `backend/scripts/phase0-backup.sh`, sin ejecutar. Utiliza `pg_dump --schema=public --format=custom`, valida `pg_restore --list` y calcula SHA-256 fuera del repositorio. Es una copia **del esquema `public`**, no de todas las configuraciones de Supabase: no garantiza recuperar roles de PostgreSQL, contraseñas, grants de plataforma, objetos Storage ni todos los esquemas ajenos a `public`.

Al planificar una copia real:
- Definir **RPO/RTO** y quién está autorizado a realizarla.
- Usar un destino cifrado fuera de Git, sin credenciales en scripts, historial de terminal ni documentación.
- Confirmar versión compatible de herramientas PostgreSQL, conectividad y espacio disponible.
- Verificar el archivo, su suma de comprobación y, en un **entorno aislado autorizado**, ensayar restauración y pruebas de lectura. El comando `pg_restore --list` solo verifica el inventario del archivo; **no demuestra** que los datos puedan restaurarse.

**Preflight estrictamente local y no destructivo en Windows PowerShell:**

```powershell
Get-Command pg_dump, pg_restore -ErrorAction SilentlyContinue |
  Select-Object Name, Source
```

Si no aparecen herramientas, dejar el control como PENDIENTE; no instalar ni exportar automáticamente. El script `.sh` requiere un entorno Bash, no es directamente ejecutable por PowerShell.

## 4. Pasos para un futuro simulacro de recuperación

**Requiere autorización expresa y un destino de recuperación separado.** Nunca restaurar un dump de prueba sobre el proyecto original.

1. Verificar integridad del archivo, fecha, cifrado, credenciales y origen.
2. Preparar un entorno de restauración aislado, sin endpoints públicos de aplicación.
3. Restaurar la copia en ese entorno mediante un procedimiento adaptado a Supabase/PostgreSQL. No introducir contraseñas reales en comandos copiados a documentación.
4. Restablecer roles, permisos, políticas RLS, funciones, índices y secretos necesarios; comprobar que `anon`/`authenticated` sigan sin acceso directo indebido.
5. Conciliar `companies`, `users`, `company_user`, cuentas, asientos y partidas contra el manifiesto del respaldo; validar integridad de Libro Diario, claves externas, migraciones y usuario `app_backend`.
6. Verificar autenticación, autorización de empresas y rechazo de consultas sin contexto; garantizar que el ambiente de recuperación no emita mensajes reales.
7. Documentar RPO y RTO medidos, custodia y fecha. **Solo entonces** pasar el control de recuperación a APROBADO.

## 5. Puertas pendientes de Fase 0

- [x] Pruebas RLS SQL entre empresas A/B con rollback y conteos sin cambios.
- [x] Pruebas HTTP de acceso, listado y asientos autorizados.
- [x] Login Sanctum, Bearer, TTL de 12 horas, caducidad 401, revocación, auditoría y rollback.
- [x] Frontend: compilar, 16/16 pruebas unitarias, TypeScript y build; recorrido principal de navegador confirmado por el usuario (sin validar todos los casos 401/403 manuales).
- [x] Confirmar plan de Supabase: **Free**, sin copias diarias automáticas garantizadas.\n- [ ] Confirmar en Dashboard si existe alguna copia recuperable por otros mecanismos o generar una exportación externa autorizada.
- [x] Obtener autorización explícita para respaldo lógico y ensayo aislado (20/10/2026).\n- [ ] Acordar RPO, RTO, custodia y ubicación cifrada y ejecutar copia lógica de public.
- [ ] Ejecutar y comprobar el ensayo de restauración del esquema public en Docker aislado.\n- [ ] Validar recuperación de la aplicación con roles/GRANTs reales en PostgreSQL independiente antes de producción.

**Estado:** Fase 0 sigue **EN PROGRESO**. Proyecto en plan Free y sin respaldo/restauración verificados. Se mantiene la prohibición de ejecutar operaciones destructivas o DDL sin una recuperación acordada. La autorización ya está concedida; falta ejecutar y verificar realmente la copia y restauración.
