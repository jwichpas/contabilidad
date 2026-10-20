# Fase 0 — Auditoría de funciones PostgreSQL (solo lectura)

**Proyecto:** contabilidad-database · **Fecha:** 2026-10-20 · **Estado:** DIAGNÓSTICO; cambios DDL pendientes de revisión y autorización.

## Validación independiente y propuesta preparada — 20/10/2026

Nueva revisión mediante `get_advisors(security)` y consultas SQL de solo lectura:
- Las **5 funciones** continúan con `proconfig=NULL`, `SECURITY DEFINER=false` y permiso EXECUTE concedido a `app_backend`, pero **no** a `anon` ni a `authenticated`.
- Los roles `anon`, `authenticated` y `app_backend` carecen de permiso `CREATE` en el esquema `public`. Este control limita la posibilidad de introducir objetos maliciosos, aunque no corrige el `search_path` mutable.
- Las tablas internas usadas por los triggers están explícitamente cualificadas como `public.*`; el resto usa funciones y operadores incorporados en PostgreSQL.
- **Propuesta técnica revisable:** fijar el parámetro de cada función a `search_path = ''`, como aconseja Supabase, preservando sus definiciones, firmas, permisos y `SECURITY INVOKER`; reversión mediante `RESET search_path` a condición de no haber intervenido otras configuraciones. **Borrador completo NO EJECUTADO:** [FASE-0-PROPUESTA-SEARCH-PATH.md](FASE-0-PROPUESTA-SEARCH-PATH.md).
- Security Advisor también presenta **1 aviso separado** de `auth_leaked_password_protection` para **Supabase Auth**. El ERP usa **Laravel Sanctum**, no Supabase Auth, para iniciar sesión; no atribuir esta advertencia a Sanctum. Si se conserva Supabase Auth para otras aplicaciones, decidir si habilitar protección de contraseñas filtradas. [Documentación de Supabase](https://supabase.com/docs/guides/auth/password-security#password-strength-and-leaked-password-protection).

El proyecto Supabase pertenece a la organización `grupo ichpas` en **plan Free**, por lo que no puede darse por disponible una copia diaria automática. Un cambio DDL sobre la única base sigue **bloqueado** hasta acordar un respaldo verificable. [Plan de recuperación](FASE-0-PLAN-RECUPERACION.md).

## Evidencia

Consulta únicamente de catálogo: `pg_proc`, `pg_namespace` y `pg_get_functiondef`. El Security Advisor de Supabase marca **cinco funciones de `public`** con `function_search_path_mutable`, según la revisión del 20 de octubre de 2026.

| Función | Tipo | SECURITY DEFINER | `proconfig` actual | Observación |
|---|---|---|---|---|
| `public.app_current_company_id()` | SQL estable | No | NULL | Utiliza `current_setting`, `nullif`; no referencias a tablas |
| `public.trg_fn_enforce_accounting_account_hierarchy()` | Trigger PL/pgSQL | No | NULL | Referencias explícitas a `public.accounting_accounts` |
| `public.trg_fn_prevent_journal_entry_mutation()` | Trigger PL/pgSQL | No | NULL | Usa OLD/NEW y TG_OP; sin SQL contra tablas |
| `public.trg_fn_validate_journal_line_context()` | Trigger PL/pgSQL | No | NULL | Tablas explícitas `public.accounting_accounts`, `public.journal_entries`, `public.fiscal_periods` |
| `public.trg_fn_enforce_double_entry_balance()` | Trigger PL/pgSQL | No | NULL | Tabla explícita `public.journal_entry_lines` |

Se observa que todas las referencias actuales a tablas del aplicativo están cualificadas con el esquema `public`. **Eso reduce riesgo de resolución ambigua, pero no equivale a tener `search_path` fijado ni elimina el aviso del asesor**. Las funciones siguen ejecutándose con privilegios del invocador (`SECURITY INVOKER`), según `prosecdef=false`. Las transacciones y permisos/RLS asociados no se han modificado.

**Referencia Supabase:** https://supabase.com/docs/guides/database/database-linter?lint=0011_function_search_path_mutable

## Propuesta para una fase autorizada (NO APLICADA)

Evaluar fijar el parámetro de cada función a **`search_path = ''`**, como aconseja el linter de Supabase, mediante futura migración Laravel nueva e incremental, sin recrear sus definiciones, elevar permisos ni deshabilitar RLS. Todas las referencias del cuerpo a tablas del aplicativo ya usan el esquema `public` explícitamente; debe verificarse la resolución de funciones integradas y la regresión contable antes de ejecutar el cambio.

**El cambio no debe aplicarse aún**: primero se requiere estrategia de recuperación acordada, revisión de dependencias y prueba de regresión de movimientos contables en un entorno apropiado. La reversión debe restituir el estado original (`proconfig=NULL`), manteniendo definición, propietario, permisos y triggers.

La prueba de regresión propuesta debe incluir:
- Lecturas RLS sin contexto y con empresa A/B.
- Activación/integridad de jerarquía del PCGE.
- Validación de líneas de asientos contables y partida doble.
- Inmutabilidad de asientos contabilizados y rechazo de modificaciones no permitidas.
- Conteos de tablas antes/después y control de artefactos sin persistencia de prueba.

**No ejecutar `ALTER FUNCTION`, `CREATE OR REPLACE FUNCTION`, `DROP FUNCTION`, cambios de rol ni `SET ROLE` contra la única base compartida antes de cumplir la puerta de recuperación.**

## Estado de controles relacionados

El frontend Vue 3 pasó `npm run test:unit` (**16/16, 0 fallos**), `npm run typecheck` (**sin errores**) y `npm run build` (**1.615 módulos, compilación Vite en 2,66 s**) según salida de PowerShell presentada por el usuario. Estas validaciones no prueban un navegador real ni cambian la configuración de funciones.

La Fase 0 permanece en progreso. El procedimiento de recuperación está documentado en [FASE-0-PLAN-RECUPERACION.md](FASE-0-PLAN-RECUPERACION.md); no se ha generado ni restaurado un respaldo en este trabajo.
