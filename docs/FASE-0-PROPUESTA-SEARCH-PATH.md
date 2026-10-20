# Fase 0 — Endurecimiento propuesto de `search_path` (NO EJECUTADO)

> **Base:** Supabase `contabilidad-database` · PostgreSQL 17.11 · **Fecha:** 2026-10-20  
> **Estado:** PROPUESTA EN REVISIÓN. No ejecutar DDL ni subirlo a `database/migrations` antes de confirmar un respaldo recuperable y contar con aprobación explícita. **No se ha alterado PostgreSQL.**

## Inventario comprobado por SELECT

El Security Advisor de Supabase devuelve **cinco** advertencias `function_search_path_mutable` y una advertencia distinta de Supabase Auth (`auth_leaked_password_protection`). Estas cinco funciones permanecen `proconfig=NULL` y `prosecdef=false` (SECURITY INVOKER). Los roles `anon` y `authenticated` no pueden ejecutarlas, y los roles `anon`, `authenticated` y `app_backend` no poseen privilegio CREATE en el esquema `public`.

| Función | Objetos del cuerpo | Observación |
|---|---|---|
| `public.app_current_company_id()` | `current_setting`, `nullif` (built-ins PostgreSQL) | Función de tenant, estable |
| `public.trg_fn_enforce_accounting_account_hierarchy()` | `public.accounting_accounts` | Trigger de jerarquía PCGE |
| `public.trg_fn_prevent_journal_entry_mutation()` | `OLD`, `NEW`, `TG_OP` | Inmutabilidad de asiento |
| `public.trg_fn_validate_journal_line_context()` | `public.accounting_accounts`, `public.journal_entries`, `public.fiscal_periods` | Contexto y periodo |
| `public.trg_fn_enforce_double_entry_balance()` | `public.journal_entry_lines` | Partida doble |

Todas las tablas de los cuerpos de las funciones están ya cualificadas como `public.nombre`. Los built-ins se resuelven mediante `pg_catalog`. El ajuste recomendado por el linter es `search_path = ''`, que elimina esquemas controlados por el invocador de la resolución de nombres.

**Riesgo residual actual:** estos controles reducen las vías de ataque, pero `proconfig=NULL` sigue siendo una inconsistencia y una advertencia formal. El ajuste no debe probarse sobre la única base de datos sin posibilidad comprobada de recuperación.

Referencias:
- [Supabase — Function Search Path Mutable](https://github.com/supabase/splinter/blob/main/docs/0011_function_search_path_mutable.md)
- [Supabase — Seguridad de funciones](https://supabase.com/docs/guides/database/functions)
- [PostgreSQL 17 — ALTER FUNCTION](https://www.postgresql.org/docs/17/sql-alterfunction.html)

## DDL propuesto, sujeto a autorización y recuperación previa

**NO EJECUTAR ESTE BLOQUE AHORA.** Es un borrador para una futura migración Laravel independiente y revisada. No cambia firmas, contenido, roles, permisos ni RLS de las funciones.

```sql
BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '20s';

ALTER FUNCTION public.app_current_company_id()
  SET search_path = '';
ALTER FUNCTION public.trg_fn_enforce_accounting_account_hierarchy()
  SET search_path = '';
ALTER FUNCTION public.trg_fn_prevent_journal_entry_mutation()
  SET search_path = '';
ALTER FUNCTION public.trg_fn_validate_journal_line_context()
  SET search_path = '';
ALTER FUNCTION public.trg_fn_enforce_double_entry_balance()
  SET search_path = '';

-- Después de validar que las cinco configuraciones son visibles.
SELECT p.oid::regprocedure::text AS signature, p.proconfig
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public'
ORDER BY signature;

COMMIT;
```

La migración a futuro debe ejecutar una comprobación previa de firmas exactas, permisos de propietario, `proconfig` original y concurrencia, con un único plan de despliegue, en lugar de ejecutar este borrador ad hoc.

## Reversión propuesta, también sujeta a autorización

**NO EJECUTAR AHORA**:

```sql
BEGIN;
ALTER FUNCTION public.app_current_company_id() RESET search_path;
ALTER FUNCTION public.trg_fn_enforce_accounting_account_hierarchy() RESET search_path;
ALTER FUNCTION public.trg_fn_prevent_journal_entry_mutation() RESET search_path;
ALTER FUNCTION public.trg_fn_validate_journal_line_context() RESET search_path;
ALTER FUNCTION public.trg_fn_enforce_double_entry_balance() RESET search_path;
COMMIT;
```

La reversión restaura `proconfig=NULL` observada actualmente. **No debe utilizarse si entretanto cambió la configuración legítimamente**, ni sustituye restaurar datos perdidos en otro cambio.

## Condiciones para aprobar el cambio en otra etapa

1. Verificar existencia, antigüedad y custodia de un respaldo **restaurable**, preferentemente con simulacro en un entorno separado, sin restaurar jamás sobre `contabilidad-database` para probar.
2. Capturar estado y dependencias de las funciones y sus triggers por `SELECT`.
3. Confirmar compatibilidad con PostgreSQL 17 y con el backend (especialmente el motor POST, RLS multiempresa y triggers de partida doble).
4. Programar ventana de cambio y elaborar migración Laravel incremental; aplicar con cuenta de migraciones, no con `app_backend`.
5. Ejecutar pruebas controladas de RLS, jerarquía PCGE, inmutabilidad/partida doble, POST y conteos, sin datos persistentes de prueba.
6. Revisar Security Advisor; confirmar que desaparezcan las **cinco** advertencias sin introducir otras nuevas.

La advertencia `auth_leaked_password_protection` corresponde a **Supabase Auth**. El frontend del ERP actualmente autentica por **Laravel Sanctum**, por lo que esa advertencia no cambia la vigencia de sus Bearer tokens. Si se mantiene Supabase Auth para otros usos, evaluar activar la protección de contraseñas filtradas en su configuración, sin duplicar el sistema de inicio de sesión.

**Estado:** propuesta lista para discusión, **ningún DDL ejecutado y ningún respaldo creado**.
