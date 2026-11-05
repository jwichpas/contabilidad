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
  PostgreSQL, autenticación Sanctum y ubicación/red del PostgreSQL configurado. No omitir
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

## Segunda medición del usuario — 2026-11-05

Nueva solicitud del mismo endpoint y fecha, después de combinar la numeración y las
tasas de cambio. Respuesta **200 OK**:

| Métrica | Antes (ms) | Ahora (ms) | Diferencia (ms) |
|---|---:|---:|---:|
| `request_total` | 4050.4 | 3444.7 | -605.7 |
| `controller_total` | 1702.0 | 1062.0 | -640.0 |
| `catalogs_sql` | 760.7 | 712.0 | -48.7 |
| `exchange_rates` | 626.3 | 306.9 | -319.4 |
| `tenant_membership` | 301.0 | 301.2 | +0.2 |
| `tenant_total` | 2505.3 | 1869.6 | -635.7 |
| `pre_auth_pipeline` | No disponible | 1573.7 | Nueva métrica |

La latencia total bajó aproximadamente **15 %** en estas dos muestras.
`next_number` ahora aparece como `included_in_catalogs_sql`, sin tiempo
independiente. La bajada observada no constituye todavía una mediana ni prueba
que todas las peticiones tengan igual rendimiento.

### Instrumentación adicional, sin alterar acceso

El siguiente cambio de `main` únicamente agrega métricas:

- `pre_auth_route`: desde el marcador de inicio global hasta comenzar el
  middleware de la ruta protegida.
- `auth_route_segment`: desde ese marcador de ruta hasta el middleware
  posterior a Sanctum; incluye el costo de autenticación y otras tareas
  de pipeline entre ambos, **no equivale exclusivamente a SQL de Sanctum**.
- `tenant_context_setup`: inicio del middleware de empresa hasta entrar al
  callback que ya tiene `SET LOCAL` y transacción.
- `tenant_context_finalize`: tiempo restante después de salir del callback
  del controlador, incluyendo cierre/commit transaccional.

Se conservan `pre_auth_pipeline` y los indicadores anteriores. No se
desactiva Sanctum, no se cachean membresías ni se alteran políticas RLS.

### Siguiente comparación solicitada

1. Actualizar `main`, reiniciar el backend si es necesario y abrir el Libro
   Diario con la misma empresa y fecha.
2. Capturar cinco respuestas de `editor-bootstrap`, no solo una: idealmente
   tres con la conexión ya caliente y dos tras inactividad controlada.
3. Copiar los valores de `pre_auth_route`, `auth_route_segment`,
   `tenant_context_setup`, `tenant_membership`,
   `tenant_context_finalize`, `catalogs_sql`, `exchange_rates` y
   `request_total`.
4. Comparar la mediana. Si domina el tramo de autenticación, revisar número
   de consultas por Bearer, reapertura de conexión y distancia de la BD,
   **sin cachear la validez de tokens revocados**.
5. Si dominan `tenant_context_setup` o `tenant_context_finalize`,
   verificar tiempos de BEGIN/SET LOCAL/COMMIT y tipo de conexión con el proveedor PostgreSQL configurado.
   El contexto RLS debe seguir siendo local a la transacción.

Un endpoint agrupado en Laravel PHP con una base PostgreSQL remota puede sufrir
varios tiempos de ida y vuelta. El proyecto incluye configuración para un pooler PostgreSQL
(Supavisor); no cambiar el host automáticamente ni usar conexiones
administrativas de migración en solicitudes del ERP. Comprobar compatibilidad
con `SET LOCAL`, transacciones, conexión TLS y el entorno real antes de alterar
el despliegue. La existencia de esa opción **no demuestra** que sea la causa
de esta muestra de 1.57 s.

## Tercera medición del usuario — 2026-11-05 21:53 (antes de corregir la prioridad)

La solicitud devolvió `200 OK` y estos tiempos:

| Etapa | ms |
|---|---:|
| `request_total` | 3653.6 |
| `pre_auth_pipeline` | 1679.4 |
| `pre_auth_route` | 1679.4 |
| `auth_route_segment` | 0.0 |
| `tenant_total` | 1973.3 |
| `tenant_context_setup` | 434.7 |
| `tenant_membership` | 326.3 |
| `controller_total` | 1102.4 |
| `catalogs_sql` | 770.1 |
| `exchange_rates` | 328.4 |
| `tenant_context_finalize` | 108.6 |

**Interpretación:** `auth_route_segment=0.0` no prueba que el Bearer no
requiera consultas. Laravel aplica una lista de prioridad a los middleware de
ruta. El marcador de inicio de autenticación estaba declarado antes de
`auth:sanctum`, pero no estaba registrado en esa lista de prioridad; su
posición efectiva podía no corresponder a lo que asumíamos.

**Corrección de instrumentación en `main`:** registrar únicamente
`JournalAuthenticationStartTiming` *antes* de
`AuthenticatesRequests` usando `prependToPriorityList`; no se altera
`auth:sanctum`, sus verificaciones, los permisos, la transacción de empresa,
el token ni los endpoints. Se agregó prueba del orden efectivo de middleware
mediante `Router::gatherRouteMiddleware()`.

Este cambio **no acelera** por sí mismo las peticiones: busca separar
correctamente el tramo de autenticación de la preparación anterior. Deben
compararse las métricas bajo la nueva posición del marcador antes de decidir
si hay que optimizar las conexiones o los índices.

**Proveedor de datos:** el código de `config/database.php` documenta el
uso de Supavisor/Supabase con el rol `app_backend`. El proveedor y endpoint
realmente utilizados se definen por variables `DB_URL`, `DB_HOST`, etc.,
del entorno de ejecución; no se deben suponer ni divulgar sus credenciales.

## Cuarta instrumentación: tiempo SQL dentro del tramo de autenticación

En la medición posterior a corregir la prioridad de middleware:

| Métrica | Tiempo |
|---|---:|
| `request_total` | 3649.5 ms |
| `pre_auth_route` | 3.5 ms |
| `auth_route_segment` | **1653.1 ms** |
| `tenant_context_setup` | 432.2 ms |
| `tenant_membership` | 324.5 ms |
| `catalogs_sql` | 769.4 ms |
| `exchange_rates` | 327.1 ms |

**Hallazgo:** el 45 % de la solicitud ocurre entre el marcador previo a
`auth:sanctum` y el marcador posterior. No necesariamente todo ese período
corresponde a consultas SQL.

### Nuevas métricas SQL, sin alterar la autenticación

El `AppServiceProvider` registra un único listener de eventos
`Illuminate\Database\Events\QueryExecuted` usando `DB::listen`.
Únicamente recopila duraciones agregadas durante el tramo de autenticación
del endpoint `GET /api/v1/accounting/journal-entries/editor-bootstrap`.
No almacena ni expone SQL, parámetros, tokens, IDs, RUC o datos de usuario.

El campo `QueryExecuted::$time` representa milisegundos de ejecución
reportados por Laravel. No garantiza separar espera de conexión/TLS de
ejecución en la base.

Las métricas nuevas son:

- `auth_sql_total;dur=N`: suma de los tiempos SQL registrados en el tramo.
- `auth_sql_queries;desc="N"`: número de consultas SQL registradas.
- `auth_sql_first;dur=N`: duración de la primera consulta observada.
- `auth_sql_max;dur=N`: duración de la consulta más lenta.
- `auth_sql_token;dur=N`: tiempo de las consultas de la tabla de tokens
  (clasificación interna; SQL/credenciales nunca aparecen en el header).
- `auth_sql_user;dur=N`: tiempo de las consultas de usuarios.

Los marcadores son **por solicitud** y se desactivan al entrar al middleware
`JournalAuthenticatedTiming`: las consultas posteriores del tenant y del
controlador no alteran los tiempos de autenticación. El listener se registra
una sola vez en el arranque de Laravel, para no acumularse en workers largos.
Si la prueba usa `actingAs` sin consultas SQL de Sanctum, el contador puede
ser cero; no confundir esa prueba con una sesión Bearer real.

**Seguridad:** permanece intacto `auth:sanctum`, comprobación de expiración,
revocación de tokens, membresía por empresa y PostgreSQL FORCE RLS. El
proyecto configura `SANCTUM_TRACK_LAST_USED_AT=false` por defecto; no se
cambia el seguimiento de última actividad ni se activa caché de tokens.

### Qué comparar en el navegador

Actualizar `main`, repetir cinco solicitudes con el mismo usuario,
empresa y fecha, y registrar `auth_route_segment`,
`auth_sql_total`, `auth_sql_queries`, `auth_sql_first`,
`auth_sql_max`, `auth_sql_token`, `auth_sql_user` y
`request_total`.

Interpretación:
- Si `auth_sql_total` explica casi todo `auth_route_segment`, revisar
  latencia de consulta/primera conexión PostgreSQL; no eliminar lecturas de
  revocación del token.
- Si `auth_sql_first` domina, investigar costo de conexión inicial,
  configuración TLS, host/región del proveedor y modo de pooler.
- Si el tiempo SQL es pequeño, investigar resolución del guard y otro
  procesamiento entre los marcadores.
- Las cifras de un solo request no establecen una reducción sostenida; usar
  mediana de cinco o más mediciones.

**Validación pendiente de esta instrumentación:** ejecutar el test
`php artisan test tests/Feature/JournalAreaDimensionTest.php --filter="loads journal editor catalogs"`.
El lint de GitHub Actions solo verifica sintaxis, no conexiones PostgreSQL.

## Quinta medición: token y usuario, y diagnóstico de conexión

El usuario verificó `php artisan test ... --filter="loads journal editor catalogs"`:
**1 prueba PASS y 33 aserciones**. La solicitud de desarrollo del
2026-11-05 devolvió **200 OK** con:

| Métrica | ms |
|---|---:|
| `request_total` | 3522.1 |
| `auth_route_segment` | 1543.1 |
| `auth_sql_total` | 1537.0 |
| `auth_sql_queries` | 2 consultas |
| `auth_sql_first` y `auth_sql_token` | **1237.2** |
| `auth_sql_user` | **299.8** |
| `tenant_context_setup` | 401.2 |
| `tenant_membership` | 301.6 |
| `catalogs_sql` | 789.3 |
| `exchange_rates` | 314.5 |

Esto indica que el tramo de autenticación se explica casi íntegramente
por dos operaciones de base de datos. La primera puede incluir el
establecimiento de una conexión PDO, pero **los contadores SQL por sí
solos no prueban esa hipótesis**.

### Diagnóstico seguro de conexión publicado en `main`

El nuevo comando **solo para `APP_ENV=local`** mide por muestra una
nueva conexión PDO y luego ejecuta dos veces `SELECT 1`:

```powershell
cd C:\Proyectos\contabilidad\backend
php artisan contabilidad:medir-latencia-db --muestras=5
```

- Usa exclusivamente `DB::connection('pgsql')`, nunca la conexión
  administrativa `pgsql_migrations`.
- Para cada muestra invalida la conexión anterior con `DB::purge` y
  obtiene una nueva PDO para separar **conexión inicial**, **primera
  consulta** y **segunda consulta**.
- No obtiene ni imprime registros de usuarios, tokens, empresas o asientos;
  tampoco imprime hosts, nombres de usuario, cadenas de conexión ni errores
  completos del driver. Solo reporta tiempos y medianas.
- No escribe datos, altera permisos, opciones PDO ni políticas RLS.
- Los resultados reflejan el proceso CLI, que no es idéntico al ciclo
  HTTP del servidor local. Úsalos para diagnosticar, no para afirmar
  un tiempo de respuesta exacto.

**Cómo interpretar:**

1. Si `conexión` ronda 700–1000 ms y cada SELECT 1 ronda 250–350 ms,
   la mayor parte del costo proviene de conexión + red a PostgreSQL,
   no de un algoritmo complejo de búsqueda Sanctum.
2. Si `conexión` es baja pero SELECT 1 tarda cientos de ms, investigar
   proximidad del servidor y configuración de acceso.
3. Si `conexión` y ambos SELECT 1 son rápidos, revisar los planes de
   consulta/índices para la búsqueda de tokens y usuarios en un entorno
   autorizado, sin mostrar datos confidenciales.
4. Medir cinco veces y comparar medianas. **No** cambiar `DB_URL`,
   `DB_HOST`, pooling, `PDO::ATTR_PERSISTENT` ni región de datos
   sin demostrar compatibilidad con `SET LOCAL`, FORCE RLS, TLS y
   rotación/revocación de sesiones.

### Posibles decisiones posteriores

El repositorio contiene configuraciones y scripts históricos para más
de un proveedor PostgreSQL. Solo la configuración privada efectiva del
entorno local determina el host/region/pooler que usa una solicitud.
No asumir Neon ni Supabase ni una región concreta a partir del nombre
de un archivo del repositorio.

La documentación oficial de Supabase sobre conexiones diferencia
pooler en transacción, pooler en sesión y conexión directa: el modo
recomendado depende de la duración de las conexiones del servidor,
capacidad IPv4/IPv6 y características del despliegue. No migrar a
ningún modo sin medir primero y conservar el rol `app_backend`.

**Pendiente:** ejecutar este comando desde Windows, adjuntar las
cinco muestras y confirmar que el nuevo archivo PHP pasó el lint.

## Diagnóstico local de conexión confirmado (cinco muestras)

El usuario ejecutó en Windows `php artisan contabilidad:medir-latencia-db --muestras=5`
con la conexión de aplicación. Las cinco muestras completaron correctamente.

- **Mediana de apertura de conexión:** 954.2 ms.
- **Mediana de primer SELECT 1:** 308.5 ms.
- **Mediana de segundo SELECT 1:** 306.8 ms.
- La muestra inicial de apertura fue mayor: 2665.9 ms.
- El tiempo HTTP anterior de autenticación (`auth_sql_token=1237.2ms`)
  es compatible en escala con abrir conexión y hacer un SELECT,
  pero se midió en otro proceso y no debe equipararse exactamente.
- **Conclusión:** el principal obstáculo para el rendimiento local
  es la comunicación PHP–PostgreSQL; se prioriza evaluar ubicación
  del backend próxima a la DB, y no desactivar controles Sanctum/RLS.

La decisión de arquitectura, cuatro alternativas, criterios de
seguridad y mediciones completas están en
[`docs/DECISION-LATENCIA-POSTGRESQL.md`](DECISION-LATENCIA-POSTGRESQL.md).
No se cambió ningún endpoint, rol, política RLS, credencial o migración.
