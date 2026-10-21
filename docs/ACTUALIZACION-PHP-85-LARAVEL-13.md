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


## Preflight PHP 8.5 confirmado / Composer estable — 21/10/2026

El usuario confirmó:
- PHP **8.5.12** ZTS x64 CLI, sin advertencias de arranque.
- Extensiones bcmath, pdo_pgsql, mbstring, openssl, ctype, tokenizer, xml y fileinfo: **PASS**.
- Composer **2.10.3 estable**, validación de manifest: **PASS**.
- `.\scripts\phase-php85-preflight.ps1`: **PASS**. La advertencia final del script sobre Laravel 11 es un pendiente real, no un error de plataforma.

Se agregó el asistente local `backend/scripts/phase-php85-upgrade-laravel.ps1` con dos modos:

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
# Primero solo resolver, sin alterar definitivamente el manifest ni instalar:
.\scripts\phase-php85-upgrade-laravel.ps1

# SOLO si el resultado del dry-run es PASS y se han revisado conflictos:
.\scripts\phase-php85-upgrade-laravel.ps1 -Apply
```

El script exige manifiestos limpios, valida PHP y Composer, hace copias externas en %TEMP%, usa `composer require ... --no-update` con constraints de PHP `^8.5`, Laravel `^13.0`, Pest `^4.0`, plugin Laravel `^4.0`, PHPUnit `^12.0` y resuelve `composer update --with-all-dependencies --dry-run`. En modo diagnóstico restaura automáticamente ambos archivos. Solo `-Apply` instala paquetes y genera nuevo `composer.lock`; valida dependencias, versión Artisan y paquetes clave. Si falla, restaura los archivos pero puede necesitar `composer install` para normalizar vendor/.

**No correr migraciones, seeders, ni otras pruebas que escriban en Supabase Dev.** Después de `-Apply`, ejecutar exclusivamente las pruebas GET/middleware autorizadas en F1 (sin DML), revisar las diferencias, actualizar los jobs CI a PHP 8.5 y subir **juntos** `backend/composer.json`, `backend/composer.lock` y configuración de CI. No subir lock obsoleto.

Este script ya está versionado, pero **no se ha ejecutado en Windows desde el conector**, y no acredita que Laravel 13 esté instalado ni que la resolución termine correctamente.


## Corrección de fallo Composer dry-run — 21/10/2026 (1234 h local)

**Entrada real del usuario:** PHP 8.5.12 y todas las extensiones PASS, Composer 2.10.3. La simulación inicial terminó con Composer exit 2 porque PowerShell pasó las restricciones `^8.5`, `^13.0`, `^4.0` y `^12.0` mediante `composer.bat`; cmd eliminó el carácter `^`, dejando versiones exactas: php 8.5 no satisfacía 8.5.12 y pest-plugin-laravel 4.0.0 no soportaba Laravel 13. **El script sí restauró composer.json y composer.lock originales; nunca se instaló un paquete.**

**Solución publicada en main:** `phase-php85-upgrade-laravel.ps1` evita `composer require` pasando rangos con caret por `composer.bat`. Ahora usa PowerShell para editar temporalmente el JSON y conservar los constraints `php:^8.5`, `laravel/framework:^13.0`, `pestphp/pest:^4.0`, `pestphp/pest-plugin-laravel:^4.1`, `phpunit/phpunit:^12.0`. Ejecuta `composer validate --no-check-lock` y `composer update --with-all-dependencies --dry-run --no-scripts`, y restaura ambos archivos al salir. Pest Laravel plugin 4.1.0 añade soporte para Laravel 13; Sanctum 4.3.3 y Collision 8.9.5 también son compatibles en su matriz actual.

**Próximo paso (solo dry-run):**
```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
.\scripts\phase-php85-upgrade-laravel.ps1
```

Si el siguiente solver muestra un conflicto diferente, **no usar -Apply**; analizar el mensaje preciso. Después de PASS se revisarán lock, cambios de Laravel y suites sin escritura antes de promover la actualización.

- https://getcomposer.org/doc/articles/versions.md
- https://packagist.org/packages/pestphp/pest-plugin-laravel
- https://packagist.org/packages/laravel/sanctum
- https://packagist.org/packages/nunomaduro/collision


## Dry-run Composer confirmado, pero falta auditoría de seguridad — 21/10/2026

El usuario confirmó la **resolución exitosa** sobre Windows / PHP 8.5.12 (sin instalar paquetes ni cambiar el lock):

- Laravel `v11.57.0 → v13.38.0`; Pest `v3.8.7 → v4.7.8`; plugin Laravel `v3.2.0 → v4.1.0`; PHPUnit `11.5.56 → 12.5.33`; Symfony `7.4 → 8.1`.
- Composer proyectó **6 instalaciones, 50 actualizaciones y 4 eliminaciones**.
- **Alerta:** `Found 4 security vulnerability advisories affecting 1 package.`
- El `composer.json` actual contiene `config.audit.block-on=false` y `config.policy.advisories.block=false`: no interpretar el éxito del solver como aprobación de seguridad.
- La simulación terminó `[PASS] Composer resolvio las dependencias objetivo en modo dry-run` y `[RESTORE] composer.json y composer.lock originales restaurados`.

Se corrigió el script para añadir `--audit-format=plain` al dry-run. **Puerta siguiente:** repetir la simulación con ese cambio y leer el paquete, CVE/GHSA, rangos vulnerables y versión corregida. No usar todavía `-Apply` ni desplegar hasta evaluar estos avisos. Las empresas A/B de Supabase Dev no se han modificado.


## Auditoría de cuatro avisos Laravel: comparación con versión resuelta

El usuario remitió 4 avisos de seguridad para `laravel/framework`. La resolución `--dry-run` había elegido **v13.38.0**; los intervalos afectados son: `<13.30.0` (XSS), `<13.12.0` (URLs firmadas), `<=13.9.0` y `<13.10.0` (CRLF). Por tanto **v13.38.0 queda fuera de esos cuatro rangos**. Es muy probable que la salida de auditoría del dry-run se refiera al lock aún vigente con Laravel 11.57; **no equivale a un audit aprobado del lock nuevo**, que todavía no existe.

Se añadió barrera al modo `-Apply` del script: comprobar Laravel >=13.30.0, Pest 4 y ejecutar `composer audit --locked --format=plain` después de regenerar el lock. Si falla la auditoría, el proceso **no declara PASS**, restaura composer.json/composer.lock y advierte que puede requerirse `composer install` para sincronizar vendor. No se desactivan alertas para evadir el test.

**Acción siguiente:** ejecutar `.scriptsphase-php85-upgrade-laravel.ps1 -Apply` **en Windows** luego de sincronizar `main`. El proyecto no se debe publicar todavía: primero comprobar versión real Artisan, auditoría y suite HTTP de solo lectura; luego revisar la guía Laravel 11→12→13, comprometer juntos los nuevos `composer.json` y `composer.lock` y actualizar CI PHP 8.5. Sin migraciones ni escrituras contra Supabase Dev.

Referencias de ventanas reparadas:
- https://github.com/advisories/GHSA-jh5r-qr3c-85q8
- https://github.com/advisories/GHSA-crmm-hgp2-wgrp
- https://github.com/advisories/GHSA-5vg9-5847-vvmq
- https://getcomposer.org/doc/03-cli.md#audit


## Incidencia -Apply: PowerShell ParserError (21/10/2026)

El usuario ejecutó `.\scripts\phase-php85-upgrade-laravel.ps1 -Apply` y PowerShell detuvo la interpretación antes de ejecutar: `ParserError ... line 114: Falta la expresión de índice de matriz`. Inspección del archivo en GitHub confirmó **corrupción del código versionado**: expresión `$laravelVersion` truncada y duplicación de un bloque `catch/finally`. No fue un fallo de Composer ni de PHP; el comando -Apply no llegó a instalar paquetes ni modificar manifests.

Se reemplazó **todo** el script por una implementación completa, con un único `try/catch/finally`, verificación del nuevo lock (Laravel >=13.30 y Pest 4), auditoría `composer audit --locked` posterior a la instalación, copia de seguridad externa y restauración en caso de fallo. Se confirmó integridad del archivo recuperado desde GitHub (156 líneas, bloque final único). **No se dispone de ejecución real del parser de Windows en el conector:** antes de llamar -Apply, comprobarlo en PowerShell de Windows:

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
$tok = $null
$err = $null
[System.Management.Automation.Language.Parser]::ParseFile(
    (Resolve-Path .\scripts\phase-php85-upgrade-laravel.ps1).Path,
    [ref]$tok, [ref]$err
) | Out-Null
if ($err.Count -gt 0) {
    $err | Format-List Message,Extent
    throw 'Error de sintaxis: NO ejecutar -Apply'
}
Write-Host '[PASS] Sintaxis PowerShell'
.\scripts\phase-php85-upgrade-laravel.ps1 -Apply
```

**Estado:** actualización Laravel 13 pendiente de ejecución real; preservar las empresas A/B y no ejecutar migraciones ni pruebas destructivas en Supabase Dev.


## Actualización local aplicada: Laravel 13 / Pest 4 — 21/10/2026

**Evidencia enviada por el usuario de `phase-php85-upgrade-laravel.ps1 -Apply`:**

```text
No security vulnerability advisories found.
[PASS] Auditoria de seguridad del nuevo composer.lock aprobada.
[PASS] Lock: Laravel v13.38.0 / Pest v4.7.8
[SIGUIENTE] php artisan test tests/Feature/Security/ExistingCompaniesHttpReadOnlyTest.php
```

PHP del equipo: **8.5.12**; Composer 2.10.3 estable. **Actualización real ejecutada solo en Windows**, con `composer.json`/`composer.lock` regenerados localmente; estos **todavía no se han subido a GitHub** y el repositorio remoto puede seguir con Laravel 11/Pest 3 hasta que el usuario los publique.

**Importante:** que Composer haya resuelto paquetes y auditado el lock **no demuestra** todavía que la aplicación ERP sea compatible con Laravel 13: continúan pendientes las pruebas F1 HTTP, guard de roles, arranque/route-list y revisión de middleware/RLS. No ejecutar migraciones ni pruebas de escrituras sobre Supabase Dev. El cambio no incluyó migraciones por el script.

**Puerta siguiente (local, solo GET/lectura):**

```powershell
cd C:\Proyectos\contabilidad\backend
php artisan --version
php artisan route:list --path=api/v1
php artisan test tests/Feature/Security/ExistingCompaniesHttpReadOnlyTest.php
php artisan test tests/Feature/Security/ExistingCompaniesRoleGuardReadOnlyTest.php
```

Si pasan y no existen errores PHP 8.5, confirmar `git status --short`, revisar `git diff -- composer.json composer.lock` (desde `backend/`), subir ambos archivos **juntos**, y actualizar `.github/workflows/phase1-security-static.yml` a PHP 8.5 con las nuevas dependencias. No declarar validación CI hasta ver sus logs.


## Compatibilidad inicial Laravel 13 aprobada en Windows — 21/10/2026

**Evidencia obtenida del usuario** bajo PHP 8.5.12 / Laravel 13.38.0 / Pest 4.7.8:

- `php artisan --version` respondió `Laravel Framework 13.38.0`.
- `php artisan route:list --path=api/v1` registró **29 rutas**; no hubo error de bootstrap/routing.
- `php artisan test tests/Feature/Security/ExistingCompaniesHttpReadOnlyTest.php`: **4 tests PASS, 40 assertions, 38.13 s** (casos HTTP A/B, anónimo, tenant inválido, A→B→A).
- `composer audit --locked` en la instalación había reportado **No security vulnerability advisories found**.
- `git status --short` muestra modificaciones locales en `backend/composer.json`, `backend/composer.lock` y caché PHPUnit, y **eliminaciones locales de dos PDF PCGE**. Los PDF y la caché no forman parte de la actualización y **no deben incluirse en commit** ni restaurarse automáticamente sin intención del usuario.
- `composer.json` fue reserializado desde PowerShell con CRLF y mantiene descripción anterior "Laravel 11"; la normalización de fin de línea es opcional, pero si se edita descripción debe comprobarse `composer validate`.

**Pendiente de verificar antes del commit local:** `php artisan test tests/Feature/Security/ExistingCompaniesRoleGuardReadOnlyTest.php` (solo consulta y middleware simulado). Los nuevos `composer.json` y `composer.lock` están en el disco Windows del usuario, **no en GitHub remoto**: deben subirse **juntos**, con `git add backend/composer.json backend/composer.lock` desde la raíz (nunca `git add -A`) y `git diff --cached --check`. No se pueden sustituir por archivos lock construidos a mano.

**Después del push de ambos:** revisar/actualizar CI `.github/workflows/phase1-security-static.yml` de PHP 8.3 a 8.5, añadir filtros de `backend/composer.json` y `backend/composer.lock` y validar la ejecución de las migraciones en PostgreSQL efímero; ajustar README y badge de versiones. Sin afirmar CI PASS hasta ejecución comprobada.

**Aún no cerrar migración de plataforma completa:** faltan el guard de roles, pruebas CI / integración y revisión de cambios 11→12→13. Supabase Dev no sufrió migraciones ni escrituras en esta fase.


## Publicación definitiva del stack PHP 8.5 / Laravel 13 (21/10/2026)

Confirmado por ejecución y resultados enviados por el usuario:

| Hito | Evidencia |
|---|---|
| PHP 8.5.12 y Composer 2.10.3 | Preflight sin errores de carga |
| Laravel 13.38.0 | `php artisan --version` PASS |
| Pest 4.7.8 / PHPUnit 12.5.33 | Lock nuevo y Composer |
| Auditoría de dependencias | `composer audit --locked`: **sin avisos** |
| Rutas de API | `php artisan route:list --path=api/v1`: **29 rutas** |
| HTTP GET multiempresa | **4 pruebas, 40 aserciones PASS** |
| Middleware de roles ADMIN/CHIEF_ACCOUNTANT | **2 pruebas, 18 aserciones PASS**, 11,06 segundos, sin DML |
| Publicación del lock | **commit `be67509`**, `main`, `composer.json` y `composer.lock` juntos, `git push` exitoso |

**Total de suites probadas después de Laravel 13:** **6 pruebas / 58 aserciones PASS**. Los cambios locales de la caché PHPUnit y la eliminación de dos archivos PDF PCGE no fueron incluidos en el commit de dependencias.

**CI adaptado** mediante `.github/workflows/phase1-security-static.yml`: dos jobs que utilizaban PHP 8.3 pasan a PHP 8.5; `composer.json`/`composer.lock` activan el workflow; se validan lock, PHP, versión del framework y `composer audit --locked`. Para mantener el ensayo real del esquema PostgreSQL 17, el job retira de su workspace **efímero** el archivo de migración `2026_10_20_235900_create_dev_company_b_via_laravel.php`, que es una fixture de datos diseñada exclusivamente para Supabase Dev y rechaza por guard el entorno testing. La migración original sigue intacta en `main`.

**Pendiente, sin atribuir PASS:** comprobar ejecución/estado real de GitHub Actions, unidad/funcionalidad restante en PostgreSQL efímero, reglas de escritura interempresa, advertencias de `search_path`, y revisión integral de compatibilidad y de seguridad F1. No ejecutar `migrate:fresh` ni suites de escritura contra Supabase Dev.
