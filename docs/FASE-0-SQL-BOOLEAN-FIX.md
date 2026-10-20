# Fase 0 — SQLSTATE 42883: boolean = integer en PostgreSQL

**Estado al 2026-10-20:** **PASS** de pruebas locales confirmadas por el usuario (7 pruebas, 29 aserciones, 19.56 s); sin migraciones ni escrituras sobre la base de datos efectuadas por este cambio de código. La verificación manual en navegador sigue pendiente.

## Error confirmado

`GET /api/v1/accounting/accounts/operational` devolvía `SQLSTATE[42883]`:
```sql
WHERE is_operational = 1 AND is_active = 1
```
Son columnas BOOLEAN de PostgreSQL. En el proyecto PDO utiliza `ATTR_EMULATE_PREPARES=true` por Supavisor (modo transacción puerto 6543). En esas condiciones los parámetros booleanos de ciertas comparaciones Eloquent pueden serializarse como números: PostgreSQL **no compara BOOLEAN con INTEGER** de forma implícita.

## Corrección preparada (sin DDL)

Se reemplazaron **29 comparaciones booleanas SQL en 19 archivos** por predicados fijos `IS TRUE` y `IS FALSE`. Incluye el listado de cuentas operativas, scope `AccountingAccount::operationalActive`, árbol de cuentas, monedas, configuración de empresas, catálogos normativos/SUNAT, centros de costos, terceros y preparación de asientos. Las condiciones `Collection::where('is_base', false)` se mantuvieron intactas porque se evalúan en memoria.

Ejemplo:
```php
->whereRaw('accounting_accounts.is_operational IS TRUE')
->whereRaw('accounting_accounts.is_active IS TRUE')
```
No se interpolan datos del usuario en estos SQL fijos. No se cambió PDO, la seguridad RLS ni las políticas de Supabase.

## Validación automática aprobada; comprobación visual pendiente

Se añadieron dos pruebas HTTP de regresión a `ReadOnlyTenantApiSmokeTest.php` (ahora 7), exclusivamente GET. **El usuario ejecutó la suite y reportó PASS: 7 pruebas / 29 aserciones / 19.56 segundos.** Las comprobaciones `php -l` sobre el controlador, modelo y pruebas también pasaron sin errores.

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only
cd backend
php -l app/Http/Controllers/Api/V1/Accounting/ListOperationalAccountsController.php
php -l app/Models/AccountingAccount.php
php -l tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php
php artisan test tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php
```

Después comprobar carga de cuentas en Libro Diario con Laravel puerto 8010 y Vite 5180. **No ejecutar migraciones, seeders ni suite global sobre la única base compartida.**

**Resultado:** 7 pruebas aprobadas y la solicitud HTTP del catálogo operacional devolvió 200. En consulta independiente SELECT a Supabase: 1 empresa, 1 usuario, 1 membresía, 1.764 cuentas, 2 asientos, 4 líneas y 18 migraciones. El número de tokens pasó a 29 y el de auditorías a 40 respecto de líneas base anteriores (28/39), por actividad de autenticación; no atribuir esos cambios a la suite GET sin evidencia adicional. **Queda pendiente la validación visual del editor en navegador.**
