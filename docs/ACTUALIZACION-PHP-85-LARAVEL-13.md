# Plan controlado — PHP 8.5 + Laravel 13 (Windows, proyecto contabilidad)

**Estado:** PREPARACIÓN / diagnóstico, **no migrado todavía**.
**Fecha:** 21/10/2026. **Repo:** `jwichpas/contabilidad`, rama `main`. **Backend actual verificado en GitHub:** Laravel 11.57, PHP declarado ^8.2, Pest ^3, plugin Pest Laravel ^3, Composer lock antiguo. Las pruebas F1.3 HTTP GET pasaron en ese stack: 4 tests/40 aserciones.

## Decisión

El objetivo es PHP **8.5.x estable**, **Laravel 13.x**, Pest 4 y PHPUnit 12 (resueltos por Composer). Laravel 11 **no tiene soporte oficial PHP 8.5** y terminó soporte de seguridad (marzo de 2026); Laravel 13 soporta PHP 8.3–8.5 y tiene soporte de seguridad hasta marzo de 2028. La migración debe pasar por las guías oficiales **11→12→13**, revisando cambios de Laravel 12/13 y el lock; **prohibido publicar `composer.json` que no coincida con `composer.lock`**.

Documentación oficial:
- https://www.php.net/manual/en/migration85.php
- https://windows.php.net/download#php-8.5
- https://laravel.com/framework/docs/12.x/upgrade
- https://laravel.com/framework/docs/13.x/upgrade
- https://laravel.com/framework/docs/13.x/releases

## Restricciones del proyecto

- **No usar `migrate:fresh`, `db:wipe`, `db:seed`, `composer update` en producción, ni ejecutar suites que creen datos sobre Supabase Dev.**
- Supabase Dev contiene empresas A=1 y B=182, 1.764 cuentas PCGE cada una. Preservarlas, igual que tokens, asientos y `migrations`.
- Conexiones operativa `pgsql`/`app_backend` y administrativa `pgsql_migrations` deben conservar separación y semántica `SET LOCAL` / `FORCE RLS`.
- No publicar credenciales de `.env`, ni sobrescribir las de Composer/GitHub. Todos los cambios van en `main`, sin rama adicional.
- Ejecutar diagnósticos y actualización Composer localmente, **sin migraciones de esquema** ni despliegues hasta que los tests pasen.
- Guardar copia *fuera del repositorio* de `composer.json` y `composer.lock` antes de resolver, para restauración si Composer falla. El lock nuevo debe crearse **por Composer**, nunca editando versiones manualmente.

## Fase P85.0 — Instalar en Windows lado a lado (sin eliminar PHP 8.3)

1. Descargar el último PHP 8.5 x64 estable para Windows desde https://windows.php.net/download#php-8.5; para `php artisan serve` y CLI es habitual un ZIP x64 **NTS** (si depende de Apache como módulo puede requerir TS). Verificar hashes publicados y VC++ Redistributable.
2. Extraer, por ejemplo en `C:\Tools\php85`. Crear/ajustar `php.ini` propio con `extension_dir="ext"` y extensiones requeridas: `bcmath`, `pdo_pgsql`, `mbstring`, `openssl`, `fileinfo`, `xml`; también `curl`, `zip`, `intl` según Composer.
3. Para **una sola sesión** PowerShell, dar precedencia a PHP nuevo sin tocar PATH global:

```powershell
$env:PATH = "C:\Tools\php85;$env:PATH"
where.exe php
php -v
php --ini
php -m
cd C:\Proyectos\contabilidad\backend
.\scripts\phase-php85-preflight.ps1
```

4. Si aparece una versión distinta de 8.5 o faltan módulos, detenerse; no ejecutar `composer update` todavía. Mantener PHP antiguo como ruta de retorno.

## Fase P85.1 — Composer y framework (pendiente)

**Solo cuando PHP 8.5 + extensiones + Composer pasen preflight y se haya revisado la guía y compatibilidad:**

1. Revisar las dependencias congeladas mediante `composer show --locked`, `composer validate --strict`, `composer outdated --direct`, `composer why-not laravel/framework ^13.0`; no ejecutar `--ignore-platform-reqs`, ni `--no-scripts` a ciegas.
2. Actualizar de forma controlada **Laravel 11 → 12** y luego **12 → 13**, ajustando `composer.json` y generando `composer.lock` en cada etapa con `composer update --with-all-dependencies`. Evaluar: Laravel Sanctum 4, Pint, Collision, Pest 4 / plugin Laravel 4, PHPUnit 12, Faker y Mockery. No agregar automáticamente dependencias opcionales como Boost/Tinker.
3. Vigilar los cambios significativos de Laravel 12/13: Carbon 3, UUIDv7, comportamiento de arrays/rutas, protección de solicitudes, Symfony y PHP 8.5 deprecations. Inspeccionar `bootstrap/app.php`, migraciones propias, middleware, tests Pest, Sanctum y reglas `company_user` RLS.
4. No fijar `php:^8.5` en `main` hasta actualizar/resolver el lock en el mismo cambio; preferir `>=8.5 <8.6` si realmente se decide exigir SOLO PHP 8.5, o una cota `^8.3` compatible con Laravel 13 cuando sea útil la portabilidad. Decidir según los entornos donde se ejecutará.
5. Ejecutar `composer validate --strict`, `composer check-platform-reqs` y `php artisan --version`, chequeos PHP sintácticos y tests **solo lectura**. Resolver avisos deprecados de PHP 8.5 antes de declarar compatibilidad.
6. Publicar los cambios de dependencias/lock juntos, documentando pruebas y posibilidad de restaurar desde Git, y ajustar `.github/workflows/phase1-security-static.yml` para instalar PHP 8.5 únicamente tras comprobar lock/Pest nuevos.
7. No iniciar el servidor web PHP 8.5 desde una consola con PATH antiguo; `where.exe php` y `php -v` deben confirmar la instancia correcta.

## Puerta de aceptación

- [ ] Windows CLI `PHP 8.5.x`, extensiones y Composer preflight PASS.
- [ ] Laravel 13.x + `composer.lock` coherente y plataforma comprobada.
- [ ] `composer validate --strict` y `php artisan --version` PASS.
- [ ] Test F1 HTTP A/B solo lectura: `php artisan test tests/Feature/Security/ExistingCompaniesHttpReadOnlyTest.php` PASS (base previa 4/40).
- [ ] Test F1 middleware de roles solo GET simulado: `ExistingCompaniesRoleGuardReadOnlyTest.php` PASS (solo cuando su ejecución esté autorizada).
- [ ] Seguridad `app_backend`, `RLS` y consulta multiempresa sin regresiones, sin nuevas migraciones.
- [ ] Confirmar que tablas, empresas, usuarios y balances en Supabase no cambiaron.
- [ ] CI con PHP 8.5/Composer nuevo PASS; si Actions está bloqueado, dejar pendientes y no inventar certificados.

**Estado F1:** en pausa de pruebas nuevas hasta completar P85; se conserva la evidencia 4/40 aprobada en el stack actual.

## Diagnóstico real de Windows — 21/10/2026

La consola del usuario ejecuta **PHP 8.5.12 ZTS x64** en `C:\Program Files\PHP\current\php.exe`, con configuración `C:\Program Files\PHP\current\php.ini`. El preflight anterior detectó las extensiones en `php -m` y declaró PASS, pero **no era un PASS válido**: el propio PHP mostró `PHP Startup: Unable to load dynamic library 'bcmath'` y `Module "curl" is already loaded`. Composer actual: `2.10-dev`, con advertencia de desarrollo anterior a 60 días.

**Acciones pendientes antes de actualizar Composer/Laravel:**

1. Ejecutar `php -n -m` para saber si **bcmath** y **curl** son módulos compilados dentro del ejecutable, sin leer `php.ini`.
2. Examinar las entradas de `bcmath`, `curl` y `extension_dir` de `C:\Program Files\PHP\current\php.ini`. Si el módulo aparece con `php -n -m`, comentar su `extension=...` adicional, sin tocar las otras extensiones. Si no aparece, verificar si existe DLL de la misma versión y arquitectura; no copiar DLL de versiones anteriores ni desactivar a ciegas.
3. Confirmar con `php -v` y `php -m` **sin warnings**, y repetir `scripts/phase-php85-preflight.ps1`.
4. Se corrigió el preflight para tratar advertencias de carga de PHP como **FAIL**, incluso si el proceso terminó con exit code cero.
5. Resolver la versión de Composer estable y después analizar `composer.lock`; no ejecutar aún `composer update`.

**Estado:** pendiente de confirmar la limpieza del `php.ini`. No se ha modificado Supabase, `composer.json` ni `composer.lock`.
