# Integración con SUNAT para tipo de cambio USD/PEN y futuros cron jobs

## Fuente oficial y límites

Fuente fija: https://www.sunat.gob.pe/a/txt/tipoCambio.txt

Ejemplo de respuesta:

```text
12/11/2026|3.407|3.414|
```

Formato: **DD/MM/YYYY | COMPRA | VENTA |**. El adaptador `SunatCurrentExchangeRateClient`
verifica formato, fecha real, rangos y HTTPS (timeout de conexión 2 s, total 4 s,
sin redirecciones). Rechaza HTML, respuestas incompletas y valores inválidos.

**Este archivo NO es una API de búsqueda histórica.** El proceso solo guarda el
resultado si la fecha publicada coincide exactamente con la fecha solicitada.
Nunca copia la tasa publicada hoy a una fecha anterior. Para fechas históricas
no disponibles en el TXT se requiere una fuente histórica validada o registro
manual con trazabilidad.

## Arquitectura (extensible)

```text
SUNAT TXT (punto de acceso único)
  └── App/Services/ExchangeRates/SunatCurrentExchangeRateClient
       └── App/Services/ExchangeRates/SunatExchangeRateSynchronizer
            ├── POST /api/v1/company/exchange-rates/import-sunat
            ├── POST /api/v1/accounting/document-journals/{module}/documents
            │    └── solo intenta obtener el tipo de cambio cuando no existe
            └── php artisan accounting:sync-sunat-fx
                 └── App/Scheduling/AccountingTaskSchedule
                      └── bootstrap/app.php ->withSchedule()
```

La sincronización es **idempotente por empresa, fecha y par USD/PEN** mediante
`ExchangeRate::firstOrCreate` y el índice único de PostgreSQL. **Nunca modifica
la cotización que ya existe**, aunque haya sido registrada manualmente.
Se usa `TenantContext::run()` y `app_backend` con FORCE RLS, tanto en
peticiones como en comandos. Se escribe un evento de auditoría
`SUNAT_EXCHANGE_RATE_IMPORTED` para nuevas importaciones.
No hay cambios de esquema ni nuevas migraciones.

La lectura `GET /company/exchange-rates` sigue siendo **solo lectura**;
nunca produce llamadas externas o escrituras. La importación HTTP necesita
`POST` autenticado y rol contable. Los asientos de Compras/Ventas usan el
servicio al detectar una cotización faltante, y vuelven a validar el valor
exacto antes de contabilizar. La consulta externa tiene timeout y no
bloquea indefinidamente la transacción de petición.

## Uso manual desde el formulario

En Compras/Ventas, seleccione fecha contable. Si no existe el tipo de cambio
registrado, aparece **Importar tasa SUNAT**. El formulario también intentará
la importación al confirmar un comprobante con tasa faltante, y el POST
contable tiene un segundo respaldo del lado del servidor. Si la fecha no
coincide con la publicación SUNAT, se informa el desfase y **no se contabiliza**
con un tipo de cambio inventado.

## Comando de sincronización (manual)

Utilice exclusivamente las credenciales de la aplicación restringida
(`DB_CONNECTION=pgsql`, `DB_USERNAME=app_backend`).

```powershell
cd C:\Proyectos\contabilidad\backend
php artisan accounting:sync-sunat-fx --company=1
php artisan accounting:sync-sunat-fx --company=1 --date=2026-11-12
```

El ID debe corresponder a una empresa real que tenga PEN base y USD activa.
El comando nunca enumera por sí solo todas las empresas (no dispone de
permisos globales). Para varias empresas, repita `--company=ID`.

## Cron de Laravel (desactivado por defecto)

`config/accounting_jobs.php` centraliza flags y opciones. En `.env` privado:

```dotenv
ACCOUNTING_SUNAT_FX_SCHEDULE_ENABLED=false
ACCOUNTING_SUNAT_FX_COMPANY_IDS=1,2
```

Solo después de completar las pruebas y autorizar la sincronización:

```dotenv
ACCOUNTING_SUNAT_FX_SCHEDULE_ENABLED=true
ACCOUNTING_SUNAT_FX_COMPANY_IDS=1,2
```

Cambiar `1,2` por identificadores **reales y aprobados**; no suponer que
coinciden con otros entornos. Ejecutar `php artisan config:clear` después
de modificar el entorno y comprobar las tareas con
`php artisan schedule:list`. El job está registrado a las **10:30 y 16:30
(America/Lima)**. Los dos intentos evitan perder el día si el valor tarda
en publicarse; cotizaciones ya registradas se omiten.

Para un servidor Linux dedicado, el operador instala **una sola entrada
de cron del sistema operativo** para todos los jobs Laravel:

```cron
* * * * * cd /ruta/segura/contabilidad/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

En desarrollo Windows, opcionalmente, lanzar `php artisan schedule:work`
en una consola dedicada. **Este PR no crea ningún cron externo ni activa el
scheduler.** Para despliegues con varias instancias, configurar un
almacenamiento compartido de locks (Redis) y `onOneServer` en la tarea.
El actual `withoutOverlapping(10)` emplea la caché configurada y solo
protege un host si usa `CACHE_STORE=file`.

### Más tareas a futuro

Agregar nuevas clases en `App/Scheduling` por dominio (comprobantes, SIRE,
conciliación, reportes, alertas) y registrarlas desde un único punto central,
`AccountingTaskSchedule`, manteniendo independencia de servicios,
observabilidad y feature flags. No mezclar lógica de red y persistencia
directamente en el comando ni en `bootstrap/app.php`.

## Pruebas y seguridad

- Unit: formato del TXT, fecha calendario, números válidos, HTML o respuesta
  corrupta, BOM y finales de línea.
- Feature: `Http::fake` verifica importación exacta, ausencia de overwrite,
  HTTP fuera de servicio, fecha histórica, controles de acceso y RLS.
- Frontend: el formulario no usa cotizaciones históricas ni presets como
  si fueran cotizaciones registradas; el botón importa vía POST autorizado.
- CI del PR: PHP lint y frontend. Integración PostgreSQL RLS debe
  ejecutarse en la rama Neon DEV antes de habilitar cron en producción.

**No utilizar credenciales `neondb_owner` para el cron.** No se requiere
ni se concede ningún nuevo permiso de administrador para esta función.
