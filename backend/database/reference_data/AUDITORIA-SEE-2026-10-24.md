# Cotejo de códigos SEE — 24/10/2026

Alcance: 33 catálogos y 1,792 filas de `see_catalogs_2017_snapshot.json`. Se cotejaron **claves** de los catálogos con filas propias contra el [Anexo E del Anexo N.º 8 publicado por SUNAT en 2017](https://www.sunat.gob.pe/legislacion/superin/2017/anexoE-245-2017.pdf). La coincidencia histórica no demuestra vigencia en 2026 ni igualdad literal de descripciones. Los catálogos externos requieren cotejo con sus respectivos organismos. Los 1,874 ubigeos pertenecen al archivo suministrado, no a un padrón actualizado certificado por INEI.

También se cotejó el [Anexo I SUNAT de 2024](https://www.sunat.gob.pe/legislacion/superin/2024/anexo1-000240-2024.pdf) para 20 y 55, y el [Anexo I SUNAT de 2026](https://www.sunat.gob.pe/legislacion/superin/2026/anexo-000108-2026.pdf) para 55. Para código de producto, [SUNAT remite a UNSPSC](https://cpe.sunat.gob.pe/informacion_general/codigoproducto); para ubigeo, consultar el [servicio de códigos estandarizados del INEI](https://www.gob.pe/15028-consultar-codigos-estandarizados-del-inei).

| Catálogo | Filas cargadas | Resultado de cotejo |
| --- | ---: | --- |
| 01 Tipo de documento | 17 | Claves coinciden con Anexo 2017. |
| 02 Monedas | 178 | Referencia externa ISO 4217; no hay lista de monedas en el Anexo 2017. Conjunto recibido no conciliado con ISO vigente. |
| 03 Unidades comerciales | 1,091 | Referencia externa UNECE Rec. 20; duplicado `A11` con dos descripciones. No conciliado con versión vigente. |
| 04 Países | 239 | Referencia externa ISO 3166-1; contiene denominaciones/códigos históricos. No conciliado con versión vigente. |
| 05 Tributos | 7 | Claves coinciden con Anexo 2017. |
| 06 Identidad | 9 | Claves coinciden con Anexo 2017. |
| 07 Afectación IGV | 18 | Claves coinciden con Anexo 2017. |
| 08 Cálculo ISC | 3 | Claves coinciden con Anexo 2017. |
| 09 Motivos nota de crédito | 10 | Claves coinciden con Anexo 2017. |
| 10 Motivos nota de débito | 3 | Claves coinciden con Anexo 2017. |
| 11 Resumen valor de venta | 5 | Claves coinciden con Anexo 2017. |
| 12 Documentos relacionados tributarios | 6 | Claves coinciden con Anexo 2017. |
| 13 Ubigeo | 0 | El anexo remite al INEI. Se cargaron 1,874 distritos en tabla SEE separada; pendiente cotejo contra padrón INEI vigente. |
| 14 Otros conceptos tributarios | 12 | Claves coinciden con Anexo 2017. |
| 15 Elementos adicionales | 40 | **Falta `2008`** respecto del Anexo 2017. |
| 16 Tipo de precio | 2 | Claves coinciden con Anexo 2017. |
| 17 Tipo de operación | 12 | Claves coinciden con Anexo 2017. |
| 18 Modalidad de traslado | 2 | Claves coinciden con Anexo 2017. |
| 19 Estado de ítem | 3 | **Falta `4`** («Anulado en el día») respecto del Anexo 2017. |
| 20 Motivos de traslado | 9 | El archivo histórico coincide en claves con 2017; el anexo 2024 registra 14 y cambió la descripción de `19`. Se agregó una versión independiente `2024-anexo-i` de 14 filas con fuente SUNAT; el archivo de 9 filas permanece intacto. |
| 21 Documentos relacionados guía | 6 | Claves coinciden con Anexo 2017. |
| 22 Percepción | 3 | Claves coinciden con Anexo 2017. |
| 23 Retención | 1 | Clave coincide con Anexo 2017. |
| 24 Tarifas servicios públicos | 12 | Claves coinciden con Anexo 2017. |
| 25 Producto SUNAT | 0 | El anexo remite a UNSPSC; no hay lista de productos cargada. |
| 51 Tipo de factura | 10 | Claves coinciden con Anexo 2017. |
| 52 Leyendas | 10 | Claves coinciden con Anexo 2017. |
| 53 Cargos y descuentos | 7 | Claves coinciden con Anexo 2017. |
| 54 Bienes y servicios sujetos a detracción | 36 | Claves coinciden con Anexo 2017; vigencia actual no determinada. |
| 55 Identificación de concepto tributario | 30 | **Difiere** del anexo 2017. El anexo 2026 incluye 119 códigos; el archivo conserva 10 claves ausentes de ese anexo y le faltan 99. Requiere versión correctiva con reglas de vigencia. |
| 56 Tipo servicio público | 5 | Claves coinciden con Anexo 2017. |
| 57 Telecomunicaciones | 4 | Claves coinciden con Anexo 2017. |
| 58 Tipo medidor luz | 2 | Claves coinciden con Anexo 2017. |

**Cobertura adicional:** el Anexo 2017 también incluye catálogos 26 (tipo de préstamo) y 27 (indicador de primera vivienda), ausentes del archivo suministrado. El conjunto SEE actual se conserva como instantánea histórica de solo lectura y separado de PLE/SIRE; no habilitarlo como validador normativo ni sustituir códigos en documentos históricos sin una migración versionada y contrastada con la normativa aplicable a la fecha del documento.

**Versiones adicionales:** `see_catalog_20_anexo_i_2024.json` transcribe las 14 filas del Anexo I de la R.S. 000240-2024/SUNAT; los anexos `see_catalog_15_2017_anexo_e.json` (41), `see_catalog_19_2017_anexo_e.json` (4) y `see_catalog_55_2026_anexo_i.json` (119) se cargan en migraciones posteriores con SHA-256 fijado. Cada edición conserva su procedencia y la instantánea recibida permanece intacta. La API sin parámetro usa la edición más reciente registrada, y `?version=historical` recupera el archivo original. La edición verificada más reciente en el sistema no presupone vigencia normativa ni reemplaza las reglas por fecha y modalidad de los comprobantes.

**Conciliación de monedas ISO 4217:** la Lista Uno publicada por SIX el 17/09/2026 se conserva como `see_catalog_02_iso_4217_2026_09_17.json` (178 códigos únicos). La instantánea recibida también tiene 178, pero 16 códigos quedan solo en el histórico (`ANG`, `BGN`, `BYR`, `CUC`, `EEK`, `HRK`, `LTL`, `LVL`, `MRO`, `SLL`, `STD`, `USS`, `VEF`, `XFU`, `ZMK`, `ZWL`) y 16 figuran solo en la nueva edición (`BYN`, `MRU`, `SLE`, `SSP`, `STN`, `UYW`, `VED`, `VES`, `XAD`, `XCG`, `XSU`, `XTS`, `XUA`, `XXX`, `ZMW`, `ZWG`). `PEN` cambia su denominación de “Nuevo Sol” a “Sol”. La migración conserva ambos conjuntos y guarda número ISO y unidad menor de la nueva edición. La fuente incluye códigos para pruebas y ausencia de moneda; no se deben aceptar automáticamente para comprobantes ni inferir vigencia retrospectiva de la fecha de publicación.

**Conciliación de ubigeo INEI:** la descarga Excel de la edición identificada como 2026 (`9-1`) en el servicio oficial contiene 1,893 distritos únicos. Los 1,874 códigos recibidos permanecen en esa edición y hay 19 códigos adicionales; también hay 59 diferencias de denominación si se ignoran mayúsculas y tildes. Se descartaron encabezados repetidos por página y filas agregadas de departamentos/provincias. `see_ubigeos_inei_2026.json` conserva código y denominaciones, más SHA-256 del XLS original; no atribuye al INEI la población, área ni coordenadas del archivo histórico. El endpoint sin versión muestra INEI 2026; `?version=historical` conserva la vista recibida. El nombre 2026 identifica la edición del portal y no afirma que cada distrito se creara ese año.

**Conciliación de países:** la lista `country-id` de UN/CEFACT consultada el 24/10/2026 presenta 249 códigos únicos. Se conserva en `see_catalog_04_uncefact_2026_10_24.json`, con hash del CSV de origen. Frente al archivo de 239 códigos, 5 aparecen solo en el histórico (`AN`, `FX`, `TP`, `YU`, `ZR`) y 15 solo en la lista UN/CEFACT (`AX`, `BL`, `BQ`, `CD`, `CW`, `GG`, `IM`, `JE`, `ME`, `MF`, `PS`, `RS`, `SS`, `SX`, `TL`). La fecha de captura no es una fecha de vigencia ISO 3166. Se debe cotejar la aplicación normativa por comprobante antes de usar la tabla como validador.

**Unidades comerciales 03:** el archivo recibido tiene 1,091 filas y 1,090 códigos únicos; `A11` tiene dos descripciones. Se obtuvo el XLSX completo de Rec. 20 Rev. 17 (2021), [publicado por UNECE](https://unece.org/trade/documents/revision-17-annexes-i-iii) y [rastreado en el repositorio de referencias de UN/CEFACT](https://opensource.unicc.org/jmcanterafonseca/vocab-bsp/-/tree/main/references/rec20). Su Anexo I tiene 1,383 códigos y la hoja «Annex II & Annex III» tiene 2,136 códigos únicos, incluidos todos los del Anexo I. De las 1,090 claves recibidas, 1,087 figuran en la revisión 17; `04`, `CRC` y `SI` quedan solo en el histórico. La edición nueva de `A11` es `angstrom`, mientras el histórico conserva sus dos filas. En la hoja completa hay 309 códigos `X` (marcados para eliminación), 71 `D` (desaconsejados) y un estado `¦` que se conserva sin interpretar. El hash del XLSX es `994a85c3682e67604d42dbda3b35d672338a45b400f5397a96a4c5467ee8056d`; el JSON `see_catalog_03_unece_rec20_rev17_2021.json` y su migración fijan esa procedencia.

La lista separada `package-type-code-list` de 405 tipos de envase no se fusionó: 22 claves coinciden con unidades pero tienen otro significado (por ejemplo `2C`: roentgen frente a barril de madera). La introducción del XLSX de Rec. 20 indica que los envases de Rec. 21 empleados como unidades deben llevar prefijo `X` para evitar esa colisión. El [Anexo E SUNAT 2017](https://www.sunat.gob.pe/legislacion/superin/2017/anexoE-245-2017.pdf) remite a Rec. 20 Revisión 4; mostrar la Rev. 17 como referencia más reciente incorporada no certifica la validez normativa de una unidad para un comprobante concreto.
