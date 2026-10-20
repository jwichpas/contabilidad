# F1.3 — Autorización multiempresa en la API Laravel

**Fecha:** 20/10/2026 · **Rama:** `main` · **Estado:** IMPLEMENTADO EN CÓDIGO / VALIDACIÓN CI PENDIENTE

## Alcance de la implementación

Cada petición de escritura primero exige `auth:sanctum`, después `SetTenantContext` verifica la membresía activa y fija `app.current_company_id` a nivel de transacción, y finalmente `RequireCompanyRole` **vuelve a leer el rol vigente en PostgreSQL** para la empresa pedida. El rol no se toma de `X-Company-Id`, de un claim del Bearer ni del frontend.

La comprobación deniega peticiones sin usuario, sin tenant, sin roles configurados, con roles desconocidos, sin membresía, con membresía inactiva o con rol no autorizado.

## Matriz de mutaciones habilitadas

| Endpoint / función | ADMIN | CHIEF_ACCOUNTANT | ACCOUNTANT | DATA_ENTRY | AUDITOR |
|---|---:|---:|---:|---:|---:|
| Crear, actualizar o desactivar cuentas contables | Sí | Sí | No | No | No |
| Crear/contabilizar asientos (POST actual) | Sí | Sí | Sí | No | No |
| Cambiar parámetros de empresa | Sí | Sí | No | No | No |
| Guardar tipos de cambio | Sí | Sí | Sí | No | No |
| Inicializar plan contable de empresa | Sí | Sí | No | No | No |

**Importante:** `DATA_ENTRY` permanece sin autorización de POST de asientos porque el endpoint actual contabiliza, no guarda un borrador. Implementar un flujo separado de borradores/aprobación en la fase de motor contable antes de otorgarle permiso. `AUDITOR` solo conserva el acceso de lectura bajo la membresía activa; las rutas de lectura mantienen `auth:sanctum` y el middleware de tenant, no un permiso de escritura.

Las rutas `/tenant/companies` (alta) y `/tenant/switch-company` tienen guardas propias que deben seguir siendo revisadas y probadas; la alta requiere rol ADMIN por `CompanyOnboardingGuard`. No se asumió que todas las rutas estén cubiertas por una matriz completa.

## Aislamiento del contexto

- `TenantContext` ahora utiliza el ciclo `scoped` de Laravel en lugar de `singleton`.
- `TenantContext::run()` restaura el valor PostgreSQL previo al liberar una transacción anidada en caso de éxito, porque la liberación de SAVEPOINT no limpia `SET LOCAL`. En caso de excepción, rollback del SAVEPOINT restaura el valor previo.
- La transacción raíz sigue limpiando automáticamente el `SET LOCAL` al terminar.

## Pruebas CI en PostgreSQL 17 temporal

- `backend/tests/Feature/Security/CompanyRoleAuthorizationTest.php`: administrador A / auditor B, contador B, digitador B, membresía revocada, empresa ajena, y sin autenticación; 403/401 y acceso hasta validación 422 para rol habilitado.
- `backend/tests/Feature/Security/TenantContextNestedTest.php`: cambio de tenant A→B→A en transacciones anidadas y rollback del SAVEPOINT.
- `backend/tests/security/phase1-real-schema-audit.sql`: controles de privilegios y RLS de tablas reales migradas.

Solo ejecutar estas pruebas sobre PostgreSQL efímero preparado por el workflow `.github/workflows/phase1-security-static.yml`. **No ejecutar la suite con usuarios/empresas ficticias en la base Supabase activa.** El workflow no contiene claves de Supabase.

## Puerta pendiente

La publicación de código no es evidencia de que CI esté verde. Faltan logs verificables de GitHub Actions, confirmar compatibilidad de todas las migraciones en PostgreSQL independiente, probar políticas RLS sin privilegios de superusuario y revisar rutas no incluidas en esta matriz. Ninguno de estos cambios aplica DDL contra Supabase.
