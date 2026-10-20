# Fase 1 — Evidencia de auditoría Supabase Dev

Fecha 20/10/2026. Proyecto `contabilidad-database` (`bnjwxphwtkhblvhsnylj`). **Auditoría mediante SELECT y Security Advisor, sin DDL ni escrituras.**

## Evidencia en base real

- Conexión de administración para lectura: `postgres` / BD `postgres`.
- Tablas `public`: **32**. Registros de `public.migrations`: **18**.
- Tablas `public` con **ENABLE y FORCE RLS**: **14**, coinciden con las tablas tenant observadas.
- Evaluación de `has_table_privilege` sobre las **32** tablas: tanto `anon` como `authenticated` tienen **SELECT denegado en todas**. El rol `app_backend` tiene SELECT en 31; `migrations` es la excepción.
- Las 18 tablas sin RLS incluyen catálogos oficiales y tablas globales (por ejemplo `companies`, `users`, `personal_access_tokens`). Que no tengan RLS **no implica acceso público** porque se verificó que `anon` y `authenticated` no poseen SELECT. Queda pendiente revisar INSERT/UPDATE/DELETE, grants indirectos y el aislamiento al usar `app_backend`.
- Security Advisor: **cinco** hallazgos `function_search_path_mutable`: `app_current_company_id`, `trg_fn_enforce_accounting_account_hierarchy`, `trg_fn_prevent_journal_entry_mutation`, `trg_fn_validate_journal_line_context` y `trg_fn_enforce_double_entry_balance`.
- Otra advertencia separada: `auth_leaked_password_protection` en Supabase Auth. El inicio de sesión ERP usa Laravel Sanctum.

## Límites y siguientes pruebas

Esta comprobación solo determina las propiedades del catálogo PostgreSQL y los privilegios SELECT efectivos; **no simula `app_backend` ni demuestra aislamiento por filas**. La sesión usada tiene identidad administrativa, por lo que una lectura de negocio con ella no probaría el comportamiento RLS de la aplicación. No se han corrido POST, migraciones, seeders, UPDATE ni rollback de datos de prueba contra Supabase Dev.

Próximo paso: ejecutar una batería de diagnóstico controlada con el rol real `app_backend` y contexto de tenant, primero solo lectura y luego transacciones reversibles sobre registros ficticios, con protección contra efectos externos. Preparar la migración Laravel incremental para las cinco funciones una vez probado su impacto en triggers y aprobado el cambio. Docker seguirá reservado para restauraciones y pruebas destructivas.

Referencia del aviso: https://supabase.com/docs/guides/database/database-linter?lint=0011_function_search_path_mutable

## Ampliación comprobada de permisos DML — 20/10/2026

Consulta real de solo lectura sobre las 32 tablas de `public`, usando `has_table_privilege` para `anon` y `authenticated`: **0 tablas** con permiso efectivo `INSERT`, **0** con `UPDATE` y **0** con `DELETE` para cualquiera de esos dos roles. Combinado con la comprobación SELECT anterior, **0 de 32 tablas** presentan permisos efectivos DML públicos para esos roles. Esto no valida privilegios sobre secuencias, funciones, vistas ni políticas ejecutadas bajo `app_backend` y no sustituye pruebas HTTP. No hubo escrituras a Supabase.
