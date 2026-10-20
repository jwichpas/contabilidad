# Arquitectura de ambientes y futura portabilidad — PostgreSQL independiente

**Decisión del usuario (20/10/2026):** Supabase `contabilidad-database` es **solo desarrollo**. Producción se implementará en **PostgreSQL nativo/independiente**, manteniendo backend Laravel 11 y frontend Vue. No migrar a producción todavía ni crear instancia de pago sin aprobación.

## Alcance de la decisión

| Área | Desarrollo actual | Producción futura |
|---|---|---|
| Base de datos | Supabase PostgreSQL 17.11 | PostgreSQL independiente, versión objetivo inicial 17, por validar |
| Acceso Laravel | `pgsql` con `app_backend` vía Supavisor 6543 | `pgsql` con rol restringido `app_backend`, TLS y pooler propio si se requiere |
| Administración | `pgsql_migrations` con rol administrativo separado | `pgsql_migrations` con cuenta DDL controlada |
| Multitenancy | `company_id` y RLS FORCE | **Mismas políticas tenant** aplicadas mediante migraciones Laravel |
| Login | Laravel Sanctum, `public.users` | Laravel Sanctum; no depender de Supabase Auth |
| UI | Vue 3 + Vite | Bundle de producción Vue 3, API Laravel separada |
| Secrets/operación | Variables locales no versionadas | Gestor de secretos, TLS, rotación de claves, backup y monitoreo |

## Inventario real de dependencias

La base de desarrollo tiene extensiones `pgcrypto` y `uuid-ossp` en `extensions`, `pg_stat_statements` en `extensions` y `supabase_vault` en `vault` (además de `plpgsql` incorporado). Son componentes instalados en el servidor completo, **no implica que la aplicación utilice todos**. Defaults `public.uuid` utilizan `gen_random_uuid()`, función incorporada a PostgreSQL 17. Revisar referencias antes de decidir instalar extensiones en producción.

La exportación `pg_dump -Fc --schema=public --no-owner --no-acl` conserva tablas de ERP, filas, índices, constraints, políticas RLS, funciones y triggers del esquema elegido, pero **no todos los roles del clúster, ACL/GRANTs, `auth.users`, Storage ni configuraciones Supabase**.

### Preparación obligatoria para una nueva base de producción

1. Proveer un servidor PostgreSQL 17 gestionado fuera de Supabase, con cifrado en reposo y transporte TLS, cuentas separadas y política de backups/PITR.
2. Versionar migraciones Laravel **idempotentes y aptas para PostgreSQL nativo**. La migración inicial `backend/database/migrations/0001_01_00_000000_setup_extensions_and_app_backend_role.php` incluye una contraseña fallback histórica y un `CREATE ROLE`: **no reutilizar credenciales por defecto**. La migración aplicada de rotación `2026_10_20_000000_rotate_app_backend_credential.php` tampoco debe repetirse ciegamente. Revisar y preparar secuencia de aprovisionamiento/rotación segura antes de aplicar migraciones en servidor nuevo.
3. Crear por gestión controlada el rol operacional `app_backend` `NOBYPASSRLS`, grants mínimos, `company_id`, políticas RLS, roles de autorización y funciones tenant. Validar SQL `SET LOCAL` / `set_config(..., true)` en cada transacción; ninguna consulta operacional debe ejecutarse con rol DDL o `postgres`.
4. Configurar `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` y `DB_SSLMODE` separados por ambiente; mantener `DB_MIGRATIONS_*` independiente. `DB_EMULATE_PREPARES=true` responde a limitaciones del pooler Supavisor; **evaluar y probar** `false` en PostgreSQL directo, no cambiarlo en desarrollo a ciegas.
5. Regenerar claves y secretos de despliegue; considerar rotación de credenciales existentes. No compartir contraseñas, tokens, `.env` ni dumps en Git/ChatGPT.
6. Ejecutar pruebas de integración: login Sanctum, Bearer/TTL/revocación, RLS entre empresas, restricciones contables, entrada/salida de Libro Diario, catálogos SUNAT, cuentas, auditoría y permisos de todos los roles.
7. Configurar respaldo **verificado por restauración independiente**, monitorización, retención y política de RPO/RTO. Despliegue productivo solo con checklist y aprobación.

## Ensayo inmediato autorizado de recuperabilidad de `public`

Con la base **todavía en desarrollo**, el usuario autorizó el 20/10/2026 una copia lógica y un ensayo aislado. Herramientas preparadas (no ejecutadas en su equipo aún):

- [Preflight local](../backend/scripts/phase0-backup-preflight.ps1)
- [Respaldo Windows](../backend/scripts/phase0-backup-windows.ps1): solo lectura de Supabase, destino cifrado fuera de Git, contraseña administrativa introducida privadamente, SHA-256, manifiesto.
- [Restauración aislada PostgreSQL 17](../backend/scripts/phase0-restore-rehearsal.ps1): Docker `--network none` sin puertos, restauración y verificación de conteos/RLS, eliminación del contenedor.

Esta restauración comprueba estructura y datos de `public` en PostgreSQL 17 externo a Supabase. Al restaurar con `--no-acl`, **NO constituye todavía una instalación funcional de producción**; faltan los GRANTs reales del rol `app_backend`, permisos, secretos y pruebas completas de API.

**Estado:** política arquitectónica definida, scripts versionados. **F0 sigue abierta hasta recibir y validar resultados reales de respaldo y restauración**.

Documentos relacionados: [Plan Maestro](../README-PLAN-MAESTRO.md), [Plan de recuperación](FASE-0-PLAN-RECUPERACION.md), [Política de migraciones Laravel](ARQUITECTURA-BD-LARAVEL.md).
