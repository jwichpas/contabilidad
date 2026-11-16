# F5 — Configuración de flujos de efectivo por empresa (borrador de PR)

## Propósito

Todos los tenants utilizan el **catálogo estándar de 56 líneas** del
estado anual de flujos de efectivo. Su código, actividad, dirección,
subtotal y regla aritmética permanecen protegidos. La empresa puede
guardar una **superposición de presentación** para:

- Mostrar nombres internos distintos por concepto de detalle (ejemplo:
  1102 «Cobranza de ventas HISACOLOR»).
- Ocultar filas de detalle sin movimiento en **ninguno de los meses
  consultados**; nunca se ocultan filas con movimiento ni los subtotales,
  diferencias o saldos finales.
- Agregar hasta 40 conceptos internos `C0001–C9999` vinculados a una
  actividad y sentido (no son códigos PLE/SUNAT).
- Configurar reglas por **cuenta contable PCGE operativa** y por **área de
  flujo de efectivo** de la empresa activa (ambas verificadas en servidor).
- Mantener hasta las últimas 20 revisiones visibles, guardadas en tabla
  inmutable `company_cash_flow_presentation_revisions`.

## Prioridad de presentación y auditoría

1. El **asiento siempre determina el monto** (no se escribe o modifica
   Journal Entry ni la contrapartida).
2. La **actividad** operación/inversión/financiación siempre proviene del
   área declarada en la partida de caja, nunca de una regla de empresa.
3. Si el área tiene código explícito 3.18, se respeta el código.
4. Si no hay código explícito, una regla de empresa para esa área y
   categoría/dirección puede asignar un detalle.
5. Si no hay regla de área, un voucher no mixto de **una sola partida de
   efectivo + una sola contrapartida** puede usar una regla PCGE exacta.
6. Si tampoco hay regla personalizada, se aplica la detección contable
   conservadora (10 contra 12/42/45, etc.).
7. Sin evidencia suficiente, el movimiento se conserva en el rubro de
   «pendientes de desagregación». Jamás se infiere por glosa o por un nombre.

Los reportes históricos se vuelven a **presentar** con la configuración
vigente, lo cual puede variar su desglose, pero NO altera los vouchers
del Libro Diario, el balance de efectivo ni el neto de las actividades.
La revisión aplicada forma parte de la respuesta de la API. Las revisiones
anteriores permanecen disponibles como pista de auditoría; todavía
no hay un selector para recalcular reportes con una revisión histórica.

## Accesos

- `GET /api/v1/company/cash-flow-presentation`: miembros autenticados
  de la empresa activa, con RLS.
- `PUT /api/v1/company/cash-flow-presentation`: `ADMIN` o
  `CHIEF_ACCOUNTANT` de la empresa activa únicamente. Se exige
  `expected_revision`; cambio concurrente retorna HTTP 409.
- `GET /api/v1/accounting/reports/cash-flow/annual?year=2026&through_month=1`
  retorna la matriz y `presentation.revision/settings` del mismo tenant.

Los clientes **no pueden** indicar `company_id` al guardar. Las reglas
solo aceptan cuentas que existen en el plan operativo y áreas
configuradas dentro del mismo tenant. El catálogo base no se puede
escribir ni se pueden cambiar las fórmulas de totales.

## Interfaz

Abrir **Tesorería y reportes → Flujo de Efectivo (Cta. 10) →
Anual detallado → Configuración del Flujo de Efectivo**.
El botón se muestra únicamente a `ADMIN` y `CHIEF_ACCOUNTANT`,
pero el backend también valida esta autorización.

Cambiar nombres, ajustar visibilidad, agregar conceptos `C0001`,
agregar reglas por cuenta o área, y pulsar **Guardar configuración**.
Se registra la nueva revisión y se refresca el estado anual.
El PDF horizontal y el CSV salen del mismo arreglo de líneas calculadas.

## Migración SOLO Docker (revisar antes de aplicar)

La configuración necesita **una migración aditiva** aislada de cualquier
migración SEE/PLE de otros entornos.

```powershell
cd C:\Proyectos\contabilidad
git switch test/ciclo-contable-eeff-niif-2026
git pull --ff-only
cd backend
.\scripts\configurar-flujo-efectivo-pg18-local.ps1 -Accion Plan
.\scripts\configurar-flujo-efectivo-pg18-local.ps1 -Accion Aplicar
.\scripts\configurar-flujo-efectivo-pg18-local.ps1 -Accion Verificar
```

El script requiere **29 migraciones locales previas** verificadas:
25 iniciales, 3 fiscales y la migración portable F2
`2026_10_21_040000_create_reference_data_versions`.
Esta última la instala el cargador de catálogos PCGE: **no debe volver a
ejecutarse ni eliminarse**. Además de comprobar los nombres, el diagnóstico
verifica que exista la tabla `reference_data_versions` con sus siete columnas,
y que `app_backend` no pueda leer el registro global.

En el laboratorio Docker del usuario se observaron precisamente estas **29**
migraciones. Después de actualizar la rama, ejecutar el diagnóstico
`configurar-flujo-efectivo-pg18-local.ps1 -Accion Plan`. **No ejecutar
`actualizar-esquema-pg18-local.ps1 -Accion Aplicar` sobre este esquema ya
actualizado**. El plan y `Verificar` de ese script aceptan la migración F2
para reconocer el estado de 29, sin volver a escribir las 3 migraciones fiscales.
Un esquema parcial o distinto continuará bloqueado.

El script verifica etiqueta, volumen, puerto y PostgreSQL 18 **Docker
local**, migraciones base conocidas y FORCE RLS; crea un respaldo local
antes de aplicar **solo** la migración
`2026_11_16_120000_create_company_cash_flow_presentation_revisions`.
Bloquea estados desconocidos, aplicación repetida, acceso a Neon y
uso de `migrate:fresh`. Si aparece `[BLOQUEO]`, detener y revisar,
nunca reintentar ciegamente.

Después, ejecutar `pnpm test:unit` y `pnpm build` en `frontend`.
Las pruebas del backend que usan conexión real deben ejecutarse
únicamente en una base DEV configurada para tests, **nunca** sobre la
base con los 14 vouchers persistentes.

## Pruebas funcionales de regresión esperadas

1. Empresa A guarda etiqueta 1102 «Cobranza A» y agrega C0001.
2. Empresa B sigue mostrando «Venta de bienes y prestación de servicios»
   (código estándar); no ve C0001 de A.
3. Usuario `ACCOUNTANT` puede leer el reporte pero no guardar ajustes (403).
4. Una revisión desactualizada devuelve HTTP 409.
5. Un intento de renombrar el subtotal 1301 o apuntar una regla PCGE
   a una actividad distinta devuelve 422.
6. Ninguna configuración cambia la ecuación de conciliación.
7. En DEMO de enero: el asiento J07 permanece en 1102, J08 en 1201,
   neto de operación S/ 2,560.00, financiación S/ 20,000.00 y
   efectivo final S/ 62,560.00.

## Límites actuales

El formato 3.18 es **referencial**, NO una validación del archivo
oficial SUNAT PLE. Las personalizaciones solo afectan a la presentación
interna de la vista. No hay editor de asientos, reclasificación
retrospectiva de datos ni generación de documentos fiscales desde
este módulo. Las revisiones antiguas son auditables, pero no reemitibles
todavía desde la interfaz.
