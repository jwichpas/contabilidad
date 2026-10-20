# Fase 0 — Plan de recuperación y continuidad (sin ejecución)

> Proyecto: `jwichpas/contabilidad` · Supabase: `contabilidad-database` · Base actual: **única y con datos de desarrollo importantes**  
> Estado al 20/10/2026: **propuesta documental**, sin copia ni restauración realizada.  
> Regla: **no ejecutar `pg_dump`, `pg_restore`, `migrate:fresh`, `DROP`, `DELETE` masivos, ni restauración de Dashboard** sin autorización expresa y destino separado revisado.

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

**Pendiente de decisión explícita:** autorizar una copia lógica de `public` a un destino cifrado fuera de Git y después la prueba de recuperación en una instancia aislada. Esto exige definir responsable, destino y custodia. `public` contiene el ERP pero un `--schema=public` no incluye todo el sistema administrado por Supabase.

## 1. Objetivo y alcance

Evitar pérdida accidental de datos y disponer de una ruta de recuperación comprobable antes de hacer nuevas migraciones o pruebas destructivas. Esto **no presupone** que existan copias automáticas; su disponibilidad depende del plan real de Supabase y de su estado de respaldo, aún por confirmar.

El usuario decidió previamente **no crear un respaldo por ahora**. Se respeta esa decisión: esta fase solo documenta la estrategia y valida código. Esta condición impide afirmar que la recuperación está certificada.

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
- [ ] Definir RPO, RTO, custodia y autorización para generar copia lógica.
- [ ] Probar restauración completa en entorno aislado si se autoriza.

**Estado:** Fase 0 sigue **EN PROGRESO**. Proyecto en plan Free y sin respaldo/restauración verificados. Se mantiene la prohibición de ejecutar operaciones destructivas o DDL sin una recuperación acordada. La decisión anterior de no generar copias no equivale a aprobar la recuperación.
