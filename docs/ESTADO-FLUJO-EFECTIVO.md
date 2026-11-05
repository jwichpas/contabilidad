# Estado de flujos de efectivo · método directo

## Acceso

En **Estados financieros → Flujo de efectivo**, seleccionar Desde/Hasta y pulsar Consultar.
La consulta utiliza el selector global de empresa, la autenticación existente y los importes
`debit_local` / `credit_local` en la moneda funcional configurada. Mantiene cuatro decimales
como cadenas; no convierte ni redondea importes a partir de saldos extranjeros.

No necesita una migración nueva. Reutiliza cuentas, áreas y dimensiones de partidas ya existentes.
No genera asientos, períodos, tipos de cambio ni reclasificaciones automáticas.

## Contrato HTTP

`GET /api/v1/accounting/reports/cash-flow`

Autenticación Sanctum y contexto de empresa (`X-Company-Id`) con membresía vigente y RLS,
igual que los demás reportes de lectura. Respuesta privada sin caché compartida.

| Parámetro | Regla |
|---|---|
| `from` | Obligatorio, `YYYY-MM-DD`, entre 2000-01-01 y 2100-12-31 |
| `to` | Obligatorio, mismo formato, mayor o igual a `from`, hasta 2100-12-31 |
| `section` | Opcional, `ALL` por defecto; `OPERATING`, `INVESTING`, `FINANCING`, `UNCLASSIFIED`, `TRANSFER`, `EXCHANGE_EFFECT`, `OPENING`, `OTHER_ADJUSTMENT` |
| `area_id` | Opcional, entero positivo; filtra el detalle, sin cambiar los totales |
| `page` | Opcional, entero 1–1000000; detalle de 50 partidas por página |

La respuesta `{ "data": ... }` contiene:

- `from`, `to`, `method: DIRECT`, `currency`, `is_preliminary: true`.
- `totals`: efectivo inicial antes del período (`opening_book_local`), aperturas al inicio
  (`opening_entries_local`), efectivo inicial presentado (`opening_local`), operación,
  inversión, financiamiento, flujo neto, efecto de cambio identificado, otros ajustes,
  partidas sin clasificar, efectivo calculado, efectivo en libros y diferencia.
- `counts`: número de partidas por sección, incluso cuando se compensan sus importes.
- `groups`: actividad y área, con cobros, pagos, neto y número de partidas.
- `cash_accounts`: composición del efectivo inicial y final por cuenta.
- `unconfigured_accounts`: cuentas del grupo 10 con movimientos sin rubro financiero
  y sin marca de efectivo equivalente. Sus importes no están incluidos en el efectivo configurado.
- `classification_complete`, `is_reconciled`: estado de clasificación y conciliación.
- `details`: filtro aplicado, filas, página, tamaño, total y última página. Cada fila incluye
  partida, asiento, fecha, glosa, cuenta, área, clasificación, importe y motivo de revisión.

Errores: 401 sin autenticación; 403 sin membresía en la empresa; 409 sin moneda funcional
activa configurada; 422 para filtros inválidos. Un `area_id` que no corresponde a las áreas
visibles de esta empresa devuelve detalle vacío, sin revelar datos de otro tenant.

## Reglas de cálculo

1. **Composición de efectivo explícita:** rubro `ESF_ACT_CORR_EFE` o marca
   `cash_flow_category=CASH_EQUIVALENT`. Una categoría OPERATING/INVESTING/FINANCING
   por sí sola no convierte una cuenta en efectivo: también puede restringir las áreas
   permitidas. No se supone que toda cuenta con prefijo 10 sea efectivo disponible.
2. **Fuente de clasificación:** área en la partida de efectivo. Las áreas de contrapartidas
   no vuelven a sumar el cobro/pago ni se usan para inventar asignaciones en asientos compuestos.
3. **Transferencia interna:** asiento con dos o más partidas de efectivo y neto cero,
   sin contrapartidas sustantivas fuera del efectivo. Se conservan las partidas en el detalle,
   pero no incrementan los cobros/pagos. Para esta detección se ignoran las cuentas analíticas
   9/79 y de orden. Un asiento con efectivo en ambos sentidos y otras contrapartidas se
   mantiene sin clasificar; no se prorratea una comisión o una transferencia arbitrariamente.
4. **Extornos:** incluir tanto el original con estado REVERSED como el extorno POSTED en
   sus fechas respectivas. La dirección del área se contrasta contra el movimiento original.
   Un extorno de cobro reduce cobros; no crea artificialmente un pago. No se descartan
   cuentas o áreas por estar inactivas actualmente.
5. **Aperturas y cierres:** aperturas en `from`, incluidos sus extornos en esa fecha,
   forman parte del efectivo inicial. Aperturas posteriores y cierres que mueven efectivo
   se separan como ajustes que requieren revisión. Los movimientos anteriores a `from`
   forman el saldo previo, coherente con el Libro Mayor; no se deduplican aperturas históricas
   ni se corrige automáticamente un arrastre contable duplicado.
6. **Efecto de cambio:** se identifica únicamente en asientos ADJUSTMENT (o su extorno)
   sin variación del importe extranjero de las partidas de efectivo y cuyas contrapartidas
   sustantivas son exclusivamente las cuentas `fx_gain_account_id`/`fx_loss_account_id`
   configuradas. No se calcula una revaluación ni se crea un nuevo tipo de asiento.
   La posibilidad de registrar esos ajustes depende del motor de asientos existente.
7. **Revisión pendiente:** falta de área O/I/F, dirección incompatible, efectivo en ambos
   sentidos con contrapartidas o tipo de original no identificable. No se asigna una actividad
   por defecto. Los ajustes de apertura/cierre pendientes también impiden clasificación completa.

La conciliación compara:

`efectivo inicial + operación + inversión + financiamiento + efecto de cambio + otros ajustes`

contra el efectivo final de las mismas cuentas en libros. Las partidas sin clasificar quedan
fuera de esa igualdad y explican la diferencia. Aunque su neto sea cero, sus conteos impiden
mostrar el estado «Conciliado · clasificación completa». Las cuentas pendientes de configurar
también impiden ese estado.

El reporte siempre es preliminar. Usa la configuración **vigente** de cuentas y áreas;
no hay instantáneas históricas de sus nombres o clasificaciones. No constituye un estado
financiero definitivo con notas, comparativos ni método indirecto.

El cálculo recorre en un único SELECT los asientos pertinentes hasta `to`, ordenados por fecha,
asiento y partida. Así, apertura, cierre y página de detalle usan la misma instantánea de
movimientos, incluso frente a contabilizaciones concurrentes. No hay consultas por partida.
El tiempo crece con el historial pertinente; rendimiento masivo y medición real en Neon quedan
pendientes de verificación. La configuración de moneda se lee antes de esa consulta.

Referencia de diseño: [NIC 7, IFRS Foundation](https://www.ifrs.org/issued-standards/list-of-standards/ias-7-statement-of-cash-flows.html/).

## Validación · 05/11/2026

- Calculador PHP de producción: **19/19 escenarios PASS**, ejecutados con PHP 8.5.12 y BCMath,
  sin base de datos. Incluye apertura, cobros, activos, préstamos, transferencias, comisión mixta,
  extorno del mismo período y del siguiente, áreas faltantes, omisiones de neto cero,
  cambio, precisión decimal, filtros y segunda página.
- Frontend: compilación Vue/TypeScript y Vite **PASS**.
- Conjunto frontend completo, incluidas las nuevas regresiones: **62/62 PASS**.
- Interfaz en Chromium con respuestas de prueba generadas por el calculador PHP: **PASS**
  en escritorio y móvil de 390 px, sin desbordamiento horizontal ni errores de JavaScript.
  Se verificaron la pestaña del reporte, saldos, segunda página, partidas sin clasificar
  y selector personalizado. Esta prueba usa respuestas simuladas; no acredita el endpoint Laravel.
- Regresión de solicitudes: **3/3 PASS**, cambio de empresa con respuesta tardía,
  descarte al desmontar la vista y error/reintento; comprueba Bearer y cabecera de empresa.
- Pruebas HTTP Laravel preparadas en `tests/Feature/Accounting/CashFlowStatementTest.php`:
  empresa vacía USD y GET sin escrituras, cobro real y extorno con área inactiva, filtros,
  rechazo de empresa ajena y moneda funcional faltante. **Pendientes de ejecución en Laravel/Neon DEV**.
  Las pruebas puras y de frontend no acreditan RLS ni el recorrido HTTP real.

Comandos en el equipo local, con el entorno DEV ya configurado:

```powershell
git pull --ff-only origin main
cd backend
php scripts/phase2-neon-connection-diagnostic.php --check
php scripts/verify-cash-flow-calculator.php
php artisan test tests/Feature/Accounting/CashFlowStatementTest.php
```

Ejecutar la prueba Feature solo si el diagnóstico corresponde a `contabilidad-dev`.
Usa transacciones reversibles, sin reinicializar tablas ni ejecutar migraciones masivas.
Para las pruebas y compilación del frontend:

```powershell
cd ../frontend
pnpm test:unit
pnpm build
```
