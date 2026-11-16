# F5 — Flujo de efectivo anual detallado (método directo), referencia Formato 3.18

## Objetivo

La captura de referencia del usuario tiene columnas **enero a diciembre + total**, grupos
**operación, inversión y financiación**, rubros de cobros/pagos con códigos, subtotales,
aumento/disminución neta, saldo inicial, efecto de diferencia de cambio y saldo final.

El reporte implementado es una **vista preliminar inspirada en la presentación**.
No es un archivo SUNAT PLE ni afirma certificación o cumplimiento de sus estructuras
electrónicas. El Formato 3.18 oficial debe validarse por separado al implementar la
exportación tributaria.

## Ubicación

Menú **Tesorería y reportes → Flujo de Efectivo (Cta. 10)** o
**Estados financieros → Flujo de efectivo → Anual detallado**.
La pestaña **Detalle por período** mantiene los movimientos y la conciliación anteriores.

Seleccionar **año** y **mes de corte**. Se muestran las doce columnas, con importes
posteriores al mes seleccionado en blanco y total hasta el cierre indicado.
Descargar CSV (punto y coma + BOM, números sin cambiar su valor) o
**PDF horizontal** (imprimir navegador → guardar como PDF; papel A3 apaisado).

## Fuente contable

- Endpoint protegido por sesión y X-Company-Id:
  `GET /api/v1/accounting/reports/cash-flow/annual?year=2026&through_month=1`
- Reutiliza `CashFlowReportService` y `CashFlowCalculator`: **una lectura de cursor**
  del Libro Diario de esa empresa. No duplica la lógica de caja ni consulta doce
  veces el historial.
- Moneda funcional configurada, importes exactos a cuatro decimales, cálculo PHP con
  `bcadd/bcsub`, cálculo frontend con enteros `BigInt`.
- Reversiones, aperturas, transferencias, diferencia de cambio y áreas desactivadas
  históricamente mantienen el tratamiento vigente del cálculo base.
- Validación mensual: saldo inicial del mes + flujo de actividades + efecto de
  cambio + otros ajustes + movimientos sin clasificar = saldo final.
  El reporte **no considera clasificado** un período con movimientos no identificados
  aunque se compensen entre sí.

## Desglose del Formato 3.18

Se respetan como etiquetas **referenciales** las secciones 1100, 1200, 1301,
1400, 1500, 1601, 1700, 1800, 1901, 2001 y 2999 de la captura.

**Regla de integridad:** una partida de flujo no se identifica como venta, compra,
tributo, préstamo o dividendo únicamente por su descripción, importe o cuenta
contrapartida. El desglose específico exige **código explícito del área de flujo**:
`F318-1102`, `F318-1201`, `F318-1703` (o el código de cuatro dígitos sin prefijo),
siempre dentro de la categoría y dirección correcta. La cuenta 10 conserva
la categoría económica que ya asignó el motor de cálculo.

Ejemplos:
- Cobros por venta con área **F318-1102** → 1102.
- Pagos a proveedor con área **F318-1201** → 1201.
- Desembolso del préstamo con área **F318-1703** → 1703.
- Área genérica existente `OPE-IN` → 1198 «Cobros pendientes de desagregación».
- Área genérica `FIN-IN` → 1798 «Cobros de financiación pendientes de desagregación».

Estos residuos son **flujos clasificados correctamente por actividad**, pero no
desglosados por concepto específico. No deben mezclarse con partidas de efectivo
`UNCLASSIFIED`, que no se incluyen en los subtotales y se muestran en una línea
de advertencia independiente.

Los códigos 1198, 1298, 1498, 1598, 1798, 1898, 2997 y 2998 son
**códigos internos de presentación**, no una declaración de que estén contenidos
en el catálogo tributario oficial de la captura.

## Escenario DEMO

Los 14 vouchers F5 de enero 2026 ya registrados sirven como regresión:
- Enero, saldo inicial: **S/ 40,000.00**.
- Flujo neto operación: **S/ 2,560.00**.
- Flujo neto financiación: **S/ 20,000.00**.
- Flujo neto inversión: **S/ 0.00**.
- Enero, efectivo final: **S/ 62,560.00**.
- Febrero, saldo inicial: **S/ 62,560.00** si no hay movimientos.

Los importes proceden del endpoint real; la vista no usa esos valores de forma
fija. La mayoría de áreas del fixture `OPE-IN/OPE-OUT/FIN-IN` son
genéricas y los movimientos aparecerán correctamente como pendientes de
desagregación. No se modifican los vouchers ni se crean documentos SUNAT.

## Revisión

```powershell
cd C:\Proyectos\contabilidad
git switch test/ciclo-contable-eeff-niif-2026
git pull --ff-only
cd frontend
pnpm test:unit
pnpm build
```

Revisar en la vista anual: empresa activa, año 2026, corte enero; comparar
con el reporte por período 01/01/2026–31/01/2026. Verificar el PDF A3
en vista previa (ancho de 14 columnas, saltos de página, período de corte).

## Pendiente antes de uso contable definitivo

1. Configuración de los códigos de áreas por empresa y reasignación **controlada**
   de movimientos anteriores, con pista de auditoría; no reclasificación automática
   ni reescritura silenciosa de asientos.
2. Concordancia línea a línea con especificaciones tributarias vigentes para
   elaborar un verdadero archivo PLE Formato 3.18.
3. Pruebas de informes comparativos, partidas no monetarias, intereses/dividendos
   según política NIC 7 aplicable, fiscalización y notas de revelación.
4. QA visual de impresión real de A3 en Windows y pruebas PHP integradas con
   PostgreSQL local (no realizadas desde ChatGPT).
