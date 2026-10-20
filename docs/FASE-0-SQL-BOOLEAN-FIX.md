# Fase 0 — SQLSTATE 42883: boolean = integer en PostgreSQL

**Estado:** corrección de código prevista en `main`; **aún sin pruebas locales confirmadas**. Sin migraciones ni escrituras sobre la base de datos.

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

## Verificación pendiente

Se añadieron dos pruebas HTTP de regresión a `ReadOnlyTenantApiSmokeTest.php` (ahora 7), exclusivamente GET.

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

Criterio PASS: 7 pruebas aprobadas, GET operacional responde 200, se conserva el número de cuentas/asientos. Actualizar diagnóstico cuando el usuario confirme resultados.
