# Fase 1 — Ejecución: seguridad, autenticación y aislamiento

**Inicio autorizado para desarrollo:** 20/10/2026. **Rama:** `main`. **Entorno:** Supabase de desarrollo `contabilidad-database` con backend Laravel y frontend Vue. No es una autorización para producción.

## Objetivo verificable

No permitir acceso a datos ajenos a otro `company_id`, ni lectura/escritura anónima indebida, y preservar el acceso legítimo del backend Laravel. Toda alteración de privilegios, funciones y políticas requiere antes una propuesta reversible y pruebas negativas.

## Orden de ejecución y controles

### F1.1 — Matriz y auditoría de privilegios (primero, sin DDL)

- Inventariar tablas `public`, propietarios, RLS (`relrowsecurity` / `relforcerowsecurity`), `GRANT` efectivos de `anon`, `authenticated`, `app_backend` y `PUBLIC`; incluir secuencias y funciones.
- Diferenciar globales privadas (`users`, `companies`, `personal_access_tokens`, `migrations`) de catálogos compartidos de solo lectura y tablas tenant. **No activar indiscriminadamente RLS en catálogos**.
- Revisar cinco avisos `function_search_path_mutable` con firmas y dependencias; probar `search_path` seguro en entorno independiente antes de aplicar migraciones.
- Auditar credenciales alguna vez publicadas y diseñar rotación y verificación de consumidores sin imprimir secretos.
- Entregables: matriz versión controlada, riesgos priorizados, SQL de migración y rollback revisados. No ejecutar cambios directos por consola sin validación.

### F1.2 — Contexto tenant y transacciones

- Inspeccionar `SetTenantContext`, middleware, alcance Eloquent, `set_config`, `SET LOCAL`, reinicio del contexto, pooler y conexiones reutilizadas.
- Casos negativos: tenant omitido, header manipulado, membresía inactiva, token revocado, cambio de empresa no autorizada, función con identificador malicioso.
- Verificar que errores y rollback no hereden `company_id` de una petición anterior.

### F1.3 — Pruebas de autorización y aislamiento

- Casos A/B con dos empresas ficticias, roles permitidos y no permitidos, rutas GET/POST/PATCH y accesos a entidades relacionadas.
- Pruebas seguras en base temporal aislada o transacciones con rollback demostrado; **prohibidos seeders destructivos y `migrate:fresh` en la base activa**.
- Verificar HTTP 401 (no autenticado) y 403 (autenticado sin privilegios), además de regresión de los flujos de login, empresa y plan de cuentas.

## Puerta de aprobación F1

- Matriz de privilegios verificada contra entorno real; cambios aplicados por migraciones versionadas con rollback revisado.
- Pruebas de aislamiento y roles aprobadas y reproducibles, sin acceso cruzado.
- Ausencia de permisos públicos indebidos comprobada.
- Protección y rotación de credenciales completadas en el ámbito aplicable.
- No confundir cobertura de F1 en desarrollo con preparación de producción: backup cifrado, recuperación integral y despliegue siguen en su propia puerta.

## Registro de inicio

El ensayo de restauración PostgreSQL 17 de `public` fue aprobado el 20/10/2026. El cifrado `age` fue **diferido a producción** por decisión del usuario, según [README Fase 0](README-FASE-0-CIERRE-Y-PRODUCCION.md). Las advertencias de seguridad de F0 continúan abiertas y se priorizan en F1. **Aún no se han ejecutado migraciones ni nuevas pruebas de F1**.

## F1.1 — Primer instrumento implementado (20/10/2026)

El script [`backend/scripts/phase1-permissions-audit.php`](../backend/scripts/phase1-permissions-audit.php) está versionado en `main`. Solo consulta catálogos `pg_class`, `pg_namespace` y `pg_proc` usando la conexión de desarrollo `app_backend`, luego muestra permisos efectivos de `anon`, `authenticated` y `app_backend`, RLS/FORCE y funciones ejecutables. No presenta filas de empresa ni secretos. El chequeo de objetos sin RLS **no indica automáticamente una vulnerabilidad**: algunos son catálogos/globales restringidos; se analizan con los permisos por separado.

**Ejecutar desde Windows luego de `git pull --ff-only origin main`:**

```powershell
cd C:\Proyectos\contabilidad\backend
php -l scripts/phase1-permissions-audit.php
php scripts/phase1-permissions-audit.php
```

**Estado:** código publicado, **no ejecutado aquí** contra Supabase ni aprobado por ejecución local. Se requiere observar los resultados antes de redactar migraciones de privilegios o tocar funciones. Si la conexión no cumple los parámetros exactos del entorno de desarrollo, falla cerrado.


## Integración continua F1 (20/10/2026)

Se agregó el workflow [`phase1-security-static.yml`](../.github/workflows/phase1-security-static.yml) y el contrato SQL [`phase1-rls-contract.sql`](../backend/tests/security/phase1-rls-contract.sql). GitHub Actions realiza controles estáticos del auditor y, en un PostgreSQL 17 **efímero, con datos ficticios**, comprueba: falta de contexto, lectura interempresa, escritura interempresa rechazada y ausencia de SELECT anónimo. Se activa en pushes de archivos de F1 o manualmente.

**Límite importante:** el SQL es una política de contrato simplificada y aislada, no las migraciones exactas del ERP; no acredita que la base Supabase actual esté correctamente configurada. Para cerrar F1 deben agregarse pruebas con migraciones/roles reales y auditar permisos efectivos del entorno de desarrollo con acceso de solo lectura y credenciales restringidas. Las ejecuciones de GitHub Actions todavía necesitan verificación; no dar PASS sin logs.

## Validación de migraciones reales en GitHub Actions

Se añadió el job `laravel-real-migrations` al workflow F1: aprovisiona PostgreSQL 17 efímero, instala Composer, ejecuta **las migraciones reales del backend Laravel** con `--database=pgsql_migrations` y comprueba que exista `app_backend` sin `SUPERUSER/BYPASSRLS` y que haya tablas `public` con `ENABLE + FORCE ROW LEVEL SECURITY`. Usa contraseñas ficticias de CI y no utiliza ninguna credencial ni conexión de Supabase.

**Estado:** commit publicado; ejecución de GitHub Actions **pendiente de confirmación**. No se marca PASS hasta comprobar logs. Si falla una migración por dependencias de Supabase o supuestos ambientales, el job debe permanecer rojo y el error corregirse de forma reproducible; no reemplazarlo por un chequeo que simule las migraciones. La prueba no valida todavía todas las autorizaciones de cada ruta API ni el diseño completo de recuperación.
