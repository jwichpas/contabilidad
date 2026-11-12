# Registro tributario separado: Compras RCE y Ventas RVIE

## Modelo contable y tributario

Cada comprobante tiene un asiento en el Libro Diario y una anotación independiente en el registro tributario. Ambos se crean en la misma transacción y se relacionan por su documento fuente.

El asiento es inmutable; el registro tributario conserva el período mensual, el tercero identificado en ese momento, la distribución del impuesto y la categoría tributaria de cada parte del documento.

Los códigos de clasificación son internos, no códigos SUNAT oficiales:

| Tipo | Categoría interna | Concepto |
| --- | --- | --- |
| Compra | P_TAXED_EXCLUSIVE | Gravada destinada a gravadas/exportación; IGV potencialmente creditable |
| Compra | P_TAXED_MIXED | Gravada de destino mixto; prorrata indicada expresamente |
| Compra | P_TAXED_NON_TAXED | Gravada destinada a no gravadas; IGV sin crédito |
| Compra | P_TAXED_NO_CREDIT | Gravada sin derecho al crédito |
| Compra | P_NON_TAXED | Compra no gravada |
| Venta | S_TAXED | Venta gravada |
| Venta | S_EXONERATED | Venta exonerada |
| Venta | S_UNAFFECTED | Venta inafecta |
| Venta | S_EXPORT | Exportación |

Las operaciones de compras mixtas requieren indicar el IGV a considerar como crédito fiscal. El resto del IGV se incorpora a la cuenta principal de compra/gasto; no se supone de manera automática el 100 % de crédito. Las cuentas contables principales se pueden mapear por categoría, por empresa y módulo.

## Período y estado

La fecha de anotación tributaria (YYYYMM) puede diferir del mes de contabilización. La suma se consulta por módulo, período, clasificación y moneda. No se suman importes en distintas monedas sin convertirlos a PEN según las reglas correspondientes.

El estado INTERNAL / PENDING_REVIEW indica registro interno pendiente de cotejo con SUNAT: no significa que el RCE o RVIE se haya enviado, aceptado ni declarado. Los asientos extornados se muestran en el detalle histórico pero se excluyen de los resúmenes tributarios activos.

## Alcance inicial y siguientes fases

Disponible: comprobantes 01 y 03, múltiples categorías por comprobante, moneda PEN o secundaria habilitada, IGV clasificado, cuentas por categoría, subdiarios independientes, resumen por período, validación de duplicados, trazabilidad al Libro Diario y aislamiento por empresa.

Pendiente antes de afirmar compatibilidad completa SIRE: importar y cotejar propuestas RCE/RVIE, generar archivos oficiales versionados, notas de crédito/débito y sus referencias, DUA/importaciones, operaciones no domiciliadas, retenciones/percepciones/detracciones, prorrata normativa automática, presentación vía API SUNAT, ajustes posteriores y pruebas tributarias de producción.

## Referencias SUNAT consultadas (noviembre 2026)

- https://cpe.sunat.gob.pe/estructura-de-archivos
- https://cpe.sunat.gob.pe/node/139
- https://cpe.sunat.gob.pe/node/160
- https://orientacion.sunat.gob.pe/3113-07-prorrata-del-credito-fiscal
- https://orientacion.sunat.gob.pe/preguntas-frecuentes-libros-y-registros
