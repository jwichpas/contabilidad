# Decisión técnica: reducir la latencia Laravel ↔ PostgreSQL

Estado: **diagnóstico confirmado; cambio de infraestructura pendiente de evaluación**
Fecha: 2026-11-05
Rama: `main`
Alcance: entorno local de desarrollo, Libro Diario Bimoneda.

## Evidencia medible

Se ejecutó `php artisan contabilidad:medir-latencia-db --muestras=5`
desde Windows con la conexión de aplicación `pgsql`. El comando solo
abrió conexiones nuevas y ejecutó `SELECT 1` dos veces por muestra.

| Muestra | Conexión (ms) | 1.er SELECT (ms) | 2.º SELECT (ms) |
|---|---:|---:|---:|
| 1 | 2665.9 | 316.4 | 313.1 |
| 2 | 897.5 | 291.3 | 292.0 |
| 3 | 970.7 | 313.5 | 313.0 |
| 4 | 918.7 | 291.7 | 292.0 |
| 5 | 954.2 | 308.5 | 306.8 |
| **Mediana** | **954.2** | **308.5** | **306.8** |

En la solicitud HTTP de `editor-bootstrap`, anterior a este ensayo:

- `auth_sql_queries=2`
- `auth_sql_token=1237.2ms`
- `auth_sql_user=299.8ms`
- `auth_sql_total=1537.0ms`
- `request_total=3522.1ms`

El tiempo de búsqueda del token es aproximadamente compatible con
una conexión nueva y un viaje a PostgreSQL (954.2+308.5=1262.7ms),
pero son muestras de procesos diferentes y **no hay identidad exacta
de tiempos ni prueba de causalidad exclusiva**.
Las dos consultas `SELECT 1` duran ~300 ms aun con conexión
ya establecida, lo cual revela que el costo de comunicación
domina las consultas triviales en este entorno.

La primera conexión del ensayo (2665.9ms) es una observación
atípica que debe considerarse al estudiar conexiones frías.

## Decisión

1. **Mantener** la verificación Sanctum de token revocado,
   expiración de 12 horas, pertenencia activa a la empresa y
   `SET LOCAL app.current_company_id` / FORCE RLS.
2. **No habilitar** caché de autenticación, PDO persistente,
   ni desactivar comprobaciones para compensar la latencia.
3. **Priorizar** que el servicio de Laravel se ejecute próximo
   al endpoint PostgreSQL en una futura prueba de despliegue
   controlado, con conexiones TLS y el rol restringido
   `app_backend`. Es posible mantener el frontend en otra
   ubicación, pero habrá que validar CORS, HTTPS y sesiones
   al preparar el despliegue.
4. **Evaluar** el endpoint con pooler del proveedor solo
   después de verificar su disponibilidad real y que no
   rompa transacciones, `SET LOCAL`, permisos ni modos
   de preparación de sentencias PDO. El pooler gestiona
   conexiones del servidor y concurrencia; no elimina
   el tiempo de ida y vuelta entre un PHP local lejano y
   la base de datos.
5. Conservar la conexión administrativa para migraciones
   separada de la conexión de aplicación. **No mover datos
   ni configurar un nuevo host automáticamente.**

## Arquitecturas a comparar

| Alternativa | Qué cambia | Qué esperar | Precauciones |
|---|---|---|---|
| A. Actual: PHP Windows ↔ DB remota | Nada | Referencia de 3.52 s HTTP | 954 ms conexión, ~307 ms por ida y vuelta |
| B. Laravel cerca de la DB | Ubicación del proceso PHP | Menor RTT PHP–PostgreSQL; la conexión de usuarios al servidor PHP seguirá siendo remota | TLS, entorno aislado, rol RLS, costes y pruebas E2E |
| C. Pooler del proveedor | Endpoint de conexión de APP | Puede mejorar gestión de concurrencia, no necesariamente el RTT desde Windows | Transacciones, `SET LOCAL`, compatibilidad de driver y modo de preparación |
| D. PostgreSQL DEV local con datos sintéticos | Base aislada en el PC | Desarrollo rápido sin red remota | No reproducir latencia ni configuración exacta de la DB remota; evitar datos reales |

La opción B es la candidata principal para la experiencia final
de usuarios, pero requiere plan de alojamiento, región efectiva
del proveedor y verificación de seguridad antes de desplegar.

## Criterios de aceptación para cambiar infraestructura

- La petición `editor-bootstrap` devuelve `200` con el mismo
  contrato JSON de catálogos, correlativo y tipos de cambio.
- Pruebas de Laravel `JournalAreaDimensionTest.php` aprobadas,
  además de aislamiento entre empresas, revocación de token,
  expiración, cambio de empresa y auditoría de asientos.
- Rol de aplicación sin `BYPASSRLS`, FORCE RLS efectivo;
  `SET LOCAL` revierte al terminar cada transacción, incluso
  cuando la solicitud falle.
- No hacer cambios sobre `production` ni migraciones
  destructivas en entornos existentes.
- Repetir al menos cinco solicitudes con condiciones comparables
  y comparar medianas de `request_total`, `auth_sql_total`,
  `tenant_context_setup`, `tenant_membership` y
  `controller_total`.
- Si se usa un pooler, ejecutar pruebas de aislamiento con
  conexiones reutilizadas, no solo pruebas unitarias.

## Acciones inmediatas sin riesgo

- Conservar la configuración actual que ya pasó pruebas
  funcionales, en vez de introducir opciones de conexión
  aún no verificadas.
- Seleccionar un entorno de ejecución Laravel próximo a
  la **región real** de la base (obtenida del panel del
  proveedor, no inferida de ejemplos del repositorio).
- Preparar una prueba controlada y reversible, no una
  migración de datos ni un cambio de producción.
- Usar este documento como línea base de rendimiento
  y decisión de arquitectura en `main`.

## Referencias de proveedor

- Neon: `https://neon.com/docs/connect/connection-pooling`
- Neon: `https://neon.com/docs/get-started/connect-neon`
- PHP: `https://www.php.net/manual/en/features.persistent-connections.php`

La configuración privada efectiva (`.env`) define qué base y
modo de conexión usa la aplicación. No publicar hosts privados,
usuarios, URLs de conexión ni secretos al comparar opciones.

## Comparación verificada: PostgreSQL local en Docker

El 05/11/2026 se repitió el comando de latencia usando **PHP 8.5 en
Windows** y un contenedor temporal **PostgreSQL 17**, enlazado únicamente
a `127.0.0.1:55432`. El comando confirmó cinco conexiones nuevas y
dos `SELECT 1` por conexión; no ejecutó consultas contables, migraciones
ni escrituras.

| Muestra | Conexión (ms) | 1.er SELECT (ms) | 2.º SELECT (ms) |
|---|---:|---:|---:|
| 1 | 10.4 | 2.4 | 1.2 |
| 2 | 7.7 | 1.2 | 1.2 |
| 3 | 6.6 | 1.1 | 1.0 |
| 4 | 7.1 | 1.0 | 0.9 |
| 5 | 7.1 | 1.3 | 1.0 |
| **Mediana** | **7.1** | **1.2** | **1.0** |

### Comparación de medianas

| Operación | Remota (ms) | Local (ms) | Remoto / local |
|---|---:|---:|---:|
| Apertura PDO | 954.2 | 7.1 | ~134× |
| 1.er `SELECT 1` | 308.5 | 1.2 | ~257× |
| 2.º `SELECT 1` | 306.8 | 1.0 | ~307× |

**Interpretación:** hay una diferencia de órdenes de magnitud entre
las conexiones y consultas triviales locales frente a las remotas.
El tiempo remoto incorpora distancia de red y la configuración real
del servicio (posibles proxy/pooler, negociación TLS, etc.).
Los resultados no demuestran por sí solos que todos los costes sean
puro RTT, ni equivalen a un benchmark del Libro Diario completo.

### Precauciones de reproducibilidad y seguridad

- La instancia local está **vacía** y no tiene tablas del ERP,
  usuarios Sanctum, migraciones, políticas FORCE RLS ni datos de prueba.
  Por ello **no ejecutar todavía** el editor ni pruebas funcionales del
  sistema apuntando a esa instancia.
- La prueba local utilizó `DB_SSLMODE=disable` y credenciales
  temporales. No extrapolar esa configuración a un servidor remoto:
  mantener TLS habilitado en cualquier acceso de red.
- Las variables `DB_*` y `POSTGRES_PASSWORD` inyectadas en
  PowerShell **siguen vigentes en esa sesión**. Antes de continuar
  pruebas del ERP remoto, detener el contenedor temporal y cerrar esa
  terminal, abrir una nueva terminal, y comprobar la configuración
  efectiva sin exponer secretos.
- El contenedor se inició con `--rm`; al ejecutar
  `docker stop pg-latencia-local` se eliminará. No guardar allí
  información que deba persistir.
- Para medir el **Libro Diario entero** en local haría falta otro
  entorno aislado con esquema, configuración RLS y datos sintéticos,
  usando un rol sin privilegios administrativos. Esa preparación es
  una tarea distinta, no realizada en este ensayo.

### Decisión tras comparar local/remoto

Se prioriza una **prueba de backend Laravel alojado cerca del PostgreSQL
remoto** y comparación de latencia HTTP end-to-end (mediana de al menos
cinco solicitudes). No cambiar automáticamente el host, la región ni
el modo de pooling de la conexión que ya funciona. Mantener en todo
momento Sanctum, expiración/revocación de tokens y RLS multiempresa.
