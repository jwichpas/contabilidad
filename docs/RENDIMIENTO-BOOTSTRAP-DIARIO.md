# Rendimiento: bootstrap del Libro Diario bimoneda

## Línea base proporcionada por el usuario (2026-11-05)

Solicitud: `GET /api/v1/accounting/journal-entries/editor-bootstrap?date=2026-11-05`.

| Métrica Server-Timing | Antes (ms) | Interpretación |
|---|---:|---|
| `catalogs_sql` | 760.7 | Un SQL agregado de cinco catálogos |
| `next_number` | 314.9 | Vista previa de número, consulta independiente |
| `exchange_rates` | 626.3 | Configuración de monedas + hasta 100 tasas previas |
| `controller_total` | 1702.0 | Incluye los pasos anteriores |
| `tenant_membership` | 301.0 | Validación obligatoria de acceso vigente |
| `tenant_total` | 2505.3 | Incluye RLS, membresía y controlador |
| `post_auth_total` | 2505.7 | Tiempo posterior al middleware de autenticación |
| `request_total` | **4050.4** | Tiempo total medido dentro del middleware |

**Importante:** estas métricas se solapan. No sumarlas. Se observó además
`Waiting for server response` de ~4.09 s y descarga ~6.49 ms. La latencia
dominante es del servidor y de los viajes a PostgreSQL, no de Vue.

## Optimización publicada en `main`

1. **Correlativo referencial:** se integra en el SQL agregado de catálogos
   mediante búsqueda del período y `MAX(right(entry_number,5)::integer)` con
   el mismo filtro mensual. Se evita una consulta separada.
   El `Server-Timing` ahora incluye
   `next_number;desc="included_in_catalogs_sql"`: la duración queda incorporada
   en `catalogs_sql`, **no** se presenta una duración de 0 ms inventada.
2. **Tipos de cambio:** las monedas activas y las últimas 100 tasas hasta
   la fecha se leen en **una sola sentencia SQL** con dos subconsultas JSONB,
   en lugar de dos consultas Eloquent. Se mantienen la prioridad de tasa exacta,
   la tasa histórica, el predeterminado USD/EUR, las seis cifras decimales,
   el histórico de hasta 40 filas y el aislamiento por empresa mediante
   predicados explícitos y FORCE RLS.
3. **Atribución pre-auth:** el nuevo indicador `pre_auth_pipeline` mide
   la etapa anterior a `JournalAuthenticatedTiming`, incluyendo configuración
   de ruta y autenticación. Su cifra no debe interpretarse exclusivamente como
   tiempo SQL de Sanctum.
4. **Sin cambios en escrituras:** no se alteraron POST contable, reserva y
   bloqueo `FOR UPDATE`, membresías, tokens, permisos ni migraciones.

Estas son reducciones de consultas, **no una nueva medición de latencia**.
No asumir que la solicitud pasó de 4 s a un valor concreto sin probarla.

## Validación obligatoria con la BD de desarrollo

En PowerShell:

```powershell
cd C:\Proyectos\contabilidad
git switch main
git pull --ff-only
cd backend
php artisan test tests/Feature/JournalAreaDimensionTest.php --filter="loads journal editor catalogs"
php artisan test tests/Feature/JournalAreaDimensionTest.php --filter="returns the same non-reserving number preview"
php artisan test tests/Feature/JournalAreaDimensionTest.php --filter="loads the journal bootstrap in two accounting read statements"
php artisan test tests/Feature/JournalAreaDimensionTest.php --filter="keeps exact, historical and default multicurrency"
```

Luego, con el backend de desarrollo y el frontend corriendo, abrir DevTools >
Red > solicitud `editor-bootstrap` > Timing y Response Headers.
Medir al menos cinco respuestas **sin cambiar la empresa ni la fecha** y
comparar mediana y rango con la línea base. Revisar
`request_total`, `pre_auth_pipeline`, `tenant_total`,
`tenant_membership`, `catalogs_sql`, `exchange_rates`,
`controller_total`. El `next_number` estará incluido en catálogos.

### Interpretación de resultados

- Si `pre_auth_pipeline` sigue cerca de 1.5 s, investigar la conexión
  PostgreSQL, autenticación Sanctum y ubicación/red de Neon. No omitir
  la validación de Bearer ni cachear membresías activas.
- Si `catalogs_sql` resulta alto, ejecutar `EXPLAIN (ANALYZE, BUFFERS)`
  **solo de forma controlada** sobre una base de pruebas, sin credenciales
  ni datos privados en los logs. Revisar índices existentes antes de crear DDL.
- Si `exchange_rates` sigue alto, distinguir latencia fija de red de costo
  real del SQL. No almacenar tasas diarias como si fueran inmutables.
- La conexión del entorno de pruebas se debe mantener con el rol restringido
  `app_backend`, sujeto a FORCE RLS; nunca usar las credenciales de
  migraciones en las solicitudes de usuarios.

## Estado de verificación

La comprobación sintáctica PHP se ejecuta automáticamente en GitHub Actions
al modificar `backend/**`. Las pruebas funcionales de Laravel que necesitan
la base PostgreSQL de desarrollo deben ejecutarse en el entorno autorizado;
no se reemplazan por lint ni por una prueba estática.

## Validación funcional del 05/11/2026

Evidencia de ejecución local aportada por el usuario, sobre `main` después de la corrección de importación `Illuminate\\Support\\Facades\\Schema`:

- Prueba de regresión del registro idempotente: **1 aprobada / 10 aserciones**, duración 33.92 s.
- `php artisan test tests/Feature/JournalAreaDimensionTest.php`: **14 aprobadas / 132 aserciones**, duración 276.21 s y 0 fallidas.
- Cubrió la equivalencia del correlativo de bootstrap con el endpoint independiente, dos lecturas contables agregadas, tipos de cambio exactos/históricos/predeterminados, aislamiento del tenant, reglas de dimensiones, subdiarios e idempotencia.
- El tiempo de toda la suite **no representa latencia HTTP** del editor. La mejora del bootstrap necesita volver a medirse con `Server-Timing` desde DevTools después de esta validación.

