# Fases 3/5/6 — IGV, Saldo a Favor del Exportador (SFE) y SFMB

**Estado: ESPECIFICACIÓN DE IMPLEMENTACIÓN / NO HABILITADO.** Actualizado: 2026-10-20.
Proyecto: `jwichpas/contabilidad` · Rama `main` · BD existente `contabilidad-database`.
Rector: [Plan Maestro](../README-PLAN-MAESTRO.md).

## 1. Regla jurídica que evita clasificaciones erróneas

- **Exportación no es una venta gravada al IGV 0 %**: según el artículo 33 del TUO del IGV, las exportaciones calificadas de bienes/servicios **no están afectas al IGV**. No mezclar `EXPORTACION_INAFECTA` con `EXONERADA`, `INAFECTA_LOCAL` o `GRAVADA`.
- El **SFE** del artículo 34 nace del IGV que cumple requisitos de crédito fiscal en las adquisiciones/importaciones del exportador, no de multiplicar sus ventas de exportación por 18 %.
- Antes de hablar de SFMB, **el SFE se aplica contra el impuesto bruto del IGV** de las ventas locales gravadas del mismo sujeto. El remanente es SFMB **potencial**.
- Para contribuyentes con **ventas únicamente locales**, el crédito fiscal no absorbido por el IGV del mes se arrastra normalmente a periodos siguientes: **no convertir automáticamente ese exceso en SFMB ni en devolución**. Mantener separados procedimientos especiales de devolución del IGV (p. ej., recuperación anticipada, retenciones/percepciones).
- **Empresa mixta** (nacional + exportadora): sí puede generar SFMB y tener IGV bruto de ventas locales en el mismo mes. No exigir sector industrial, régimen General ni un `is_exporter=true` estático para determinar el tratamiento.
- **La devolución/compensación no es automática**. El SFMB disponible está sujeto al límite del reglamento de Notas de Crédito Negociables, validación documentaria, solicitudes y decisión administrativa.
- Servicio al exterior **no equivale automáticamente a exportación**. Evaluar onerosidad, proveedor domiciliado, destinatario no domiciliado, aprovechamiento en el exterior e inscripción previa en el Registro de Exportadores de Servicios **cuando corresponda**. Distinguir casos especiales del artículo 33.

## 2. Conceptos independientes en la aplicación

| Código interno propuesto | Significado | Tratamiento |
|---|---|---|
| `IGV_CREDITO_ARRASTRABLE` | Crédito fiscal elegible no aplicado en operaciones locales | Se arrastra; no se muestra como SFMB |
| `SFE` | Saldo a favor por exportación basado en compras/importaciones válidas | Se deduce del IGV bruto local, sujeto a reglas |
| `SFMB_DETERMINADO` | Remanente después de deducir el impuesto bruto del IGV | Potencialmente compensable/devolvible, **no** monto autorizado |
| `SFMB_DENTRO_LIMITE` | Porción de SFMB que cumple el límite normativo de exportaciones computables | Candidata a compensación/devolución; verificar usos previos |
| `SFE_ARRASTRADO` | Saldo no utilizado/exceso sobre límite | Carga al siguiente periodo con reglas de control de límite |
| `SFMB_SOLICITADO` | Solicitud de devolución a SUNAT | Estado administrativo; **no** representa efectivo |
| `SFMB_APROBADO` / `SFMB_OBSERVADO` | Resultado validado de solicitud | Contabilidad según derecho exigible, pagos y documentación |
| `SFMB_COMPENSADO` / `SFMB_COBRADO` | Aplicación a otro tributo o recepción real | Movimiento que reduce saldo disponible sin duplicarlo |

No mezclar términos de IGV con **NIIF**: fiscalidad y derecho a devolución deben conciliarse con partidas del Ledger; no registrar ingresos ni caja por el solo hecho de calcular un saldo.

## 3. Determinación preliminar por ejercicio y mes (NO declaración)

**Insumos validados y conciliados:**
1. `periodo`, `company_id`, moneda de cálculo `PEN`, tasa y régimen normativos vigentes.
2. `igv_bruto_ventas_locales` de operaciones gravadas (después de notas de crédito/débito).
3. `sfe_disponible`: crédito fiscal elegible sustentado, ajustes permitidos y saldos debidamente arrastrados; requiere control separado de prorrata y compras no elegibles.
4. `exportaciones_computables_bienes`: FOB de DAM/DUA numerada, asociada a embarque y facturación según periodo legal, más notas válidas; **no** únicamente fecha de factura.
5. `exportaciones_computables_servicios`: valor de comprobantes que cumplen requisitos y fecha de cómputo aplicable; registro previo cuando se exija.
6. `utilizaciones_previas`, `solicitudes_pendientes`, `ajustes`, componentes de arrastre que incrementen límite cuando corresponda.

**Secuencia conceptual**:

`SFE contra IGV local = min(SFE disponible, IGV bruto)`.

`SFMB preliminar = max(0, SFE disponible - IGV bruto)`.

`Límite base del período = exportaciones computables × tasa IGV + IPM aplicable`, con ajustes normativos por arrastre de SFMB no utilizado dentro del límite anterior cuando corresponda.

`Máximo candidato a compensar/devolver = min(SFMB preliminar, límite ajustado - usos de dicho límite)`.

Los movimientos de devolución, compensación y arrastre **se calculan separadamente**; una solicitud no rebaja el saldo definitivo como si hubiese sido cobrado, pero sí reserva importe para evitar solicitar/compensar dos veces.

Esta secuencia **es una simplificación de diseño**; la implementación debe incorporar las reglas históricas del art. 4 del DS 126-94-EF, reglas de meses sin solicitudes, diferencias temporales de facturación/embarque y reconciliación de periodos. No usar una simple función de tres cifras como cálculo oficial de devolución.

**Casos ilustrativos (sin arrastres especiales ni solicitudes anteriores; tasa combinada 18 %):**

| Caso | Crédito elegible / SFE | IGV ventas locales | Exportaciones computables | Resultado ilustrativo |
|---|---:|---:|---:|---|
| Solo venta local | S/ 5,400 | S/ 1,800 | S/ 0 | **S/ 3,600 de crédito fiscal arrastrable**; **SFMB no aplica** |
| Exportadora mixta | S/ 5,400 | S/ 1,800 | S/ 20,000 | SFE aplicado a IGV: S/ 1,800; SFMB: S/ 3,600; límite base: S/ 3,600 |
| Exportadora mixta (límite bajo) | S/ 5,400 | S/ 1,800 | S/ 10,000 | SFMB: S/ 3,600; máximo base de uso: S/ 1,800; restante sujeto a arrastre |
| Solo exportadora | S/ 5,400 | S/ 0 | S/ 10,000 | SFMB: S/ 5,400; límite base: S/ 1,800; excedente sujeto a arrastre |

**Advertencia:** el primer renglón denomina al importe `crédito fiscal elegible`, no SFE (este no se configura por mero exceso de compras de una entidad sin exportaciones). Los ejemplos excluyen compras no elegibles y operaciones exoneradas que exijan prorrata.

## 4. Modelo funcional multiempresa: sin confundir sector con exportación

**F3 — Asistente empresa y configuración:**
- En «Actividad económica» mantener `sector`, `ciiu` e identificador de actividad principal/secundaria **independientes** de exportar.
- En «Tributación/Operaciones» agregar el indicador informativo `operaciones_previstas = LOCALES | EXPORTACIONES | MIXTAS` y tipos `BIENES | SERVICIOS | AMBAS`, **no** como único interruptor de SFMB.
- En servicios, exigir constancia/fecha de inscripción registral **solo para los supuestos que la requieren**, y almacenar caso/norma.
- Puede haber exportaciones esporádicas y cambio de actividad: historial por fechas, nunca borrado retroactivo.
- **Activación efectiva** del módulo SFMB por **operaciones validadas del periodo**, no solo por opciones del asistente. Las empresas que nunca exportan acceden a IGV ordinario sin ver importes ficticios SFMB.

**F5 — Captura contable y conciliación:**
- `Ledger` permanece fuente única del movimiento financiero: asientos de IGV, solicitudes, compensación y cobros, con referencia al expediente fiscal.
- Documentos origen: compras y NC/ND, importaciones/pólizas, facturas de ventas locales y exportación, DAM/DUA, fechas FOB/embarque, comprobantes de servicios, bancos, tasas de cambio con origen y fecha.
- Integrar RCE/RVIE/SIRE/PLE con **catálogos de libros electrónicos** y trazabilidad CUO; no duplicar registro de gastos/ventas.
- Separar IGV crédito fiscal disponible de IGV no recuperable y de SFE; registrar tipo de afectación IGV **sin asumir que se vende con tasa 0 %**.
- Validar prorrata cuando coexisten actividades gravadas, exoneradas/no gravadas sujetas a régimen de crédito.

**F6 — Motor fiscal SFMB por periodo:**
- `company_export_tax_profiles`: vigencia y datos informativos de actividad y registro de servicios; no determina por sí solo elegibilidad.
- `export_supporting_documents`: documentos/evidencia y fechas de cómputo por operación, FKs a entidad/documento y tenant.
- `igv_period_determinations`: ejercicio/mes, tasa/versionado normativo, bases, impuesto bruto, crédito validado, SFE aplicado, SFMB, límite ajustado, arrastres; claves `(company_id,year,month,version)`.
- `sfmb_applications`: tipo `COMPENSACION_IR`, `COMPENSACION_OTROS`, `DEVOLUCION`, importes, estado `BORRADOR/ENVIADO/OBSERVADO/APROBADO/PARCIAL/COBRADO/ANULADO`, referencias PDB, solicitud/formulario, constancia, fecha SUNAT y sustento; **impedir aplicación doble**.
- `sfmb_movements`: ledger fiscal de saldos por periodo y origen, ajustes, reversos y bloqueo contra solicitudes concurrentes. No duplicar asiento general de contabilidad.
- Todas las tablas con `company_id`, RLS, FKs tenant, autoría y fecha, integridad transaccional, estados históricos inmutables y permisos mínimos de `app_backend`.

**La creación de todas las tablas se realizará EXCLUSIVAMENTE por migraciones Laravel, NO por SQL directo en Supabase**. No se crea ninguna migración aún en esta especificación: primero definir entradas y pruebas del motor fiscal.

## 5. Interfaz propuesta

1. **Tributos → IGV mensual**: Débito IGV local; compras admitidas/no admitidas; saldo local arrastrable; SFE si aplica; controles de prorrata.
2. **Exportaciones**: lista de comprobantes y documentos aduaneros o contratos para exportación de servicios; estado de sustento; capacidad de filtrar por ejercicio/mes y sector.
3. **SFMB**: conciliación, línea de tiempo por periodo y cálculo explicable, con desglose «SFE → IGV local → SFMB → límite → compensación/devolución/arrastre».
4. **Trámites SUNAT**: solicitudes de devolución (p. ej., FV 1649 o formulario 4949 según modalidad), PDB Exportadores, compensaciones con Renta y otros tributos admisibles, observaciones y pago efectivo. **No afirmar presentación electrónica automática sin conector oficial**.
5. **Auditoría y reportes**: conciliación por periodo con compras/ventas/SIRE/Libro Diario, descarga Excel/PDF, respaldo de expedientes.

## 6. Pruebas de aceptación obligatorias

- Empresa comercial con ventas exclusivamente locales: saldo a favor de IGV se **arrastra**; SFMB nunca ofrecido.
- Empresa mixta local/exportación (bienes): deduce el SFE contra IGV bruto local **antes** de determinar SFMB.
- Empresa ganadera exportadora: mismo derecho potencial si hay operaciones/evidencia y crédito elegible; su clasificación ganadera **no** la excluye.
- Empresa de servicios exportadora: evaluar requisitos específicos y, cuando aplique, registro previo; marcar `PENDIENTE_REVISION` si no hay evidencia.
- Distintas fechas de factura y DAM/embarque: el valor de exportación computable cae en el periodo normativamente correcto.
- NC/ND y comprobantes anulados: corregir importes y guardar revisión/auditoría.
- SFMB que supera el límite del mes: **arrastrar** exceso, no reportar devolución superior al límite.
- SFE arrastrado, límite disponible no utilizado, solicitudes pendientes y compensaciones cruzando ejercicios: reconciliar saldo, límite y evitar doble utilización.
- Operaciones mixtas con compras parcialmente admitidas y prorrata: no reconocer automáticamente todo el IGV como SFE.
- Contador autorizado edita/busca datos solo en su empresa; escenarios concurrentes impiden solicitudes duplicadas.
- Cierre del periodo contable: reapertura/rectificación explícita con auditoría, sin modificar declaraciones históricas silenciosamente.
- Una solicitud SUNAT **no** contabiliza automáticamente dinero en caja ni convierte el trámite en aceptación.

## 7. Dependencias y orden de implementación

1. **F3:** incorporar campos descriptivos en Vue al wizard de empresas sin usarlos para reconocer un derecho al SFMB.
2. **F5:** cerrar modelo de documentos tributarios y contabilidad de IGV; SIRE RCE/RVIE y trazabilidad con Ledger.
3. **F6.a:** migraciones Laravel y validaciones de documentos exportadores por tipo de operación.
4. **F6.b:** motor de determinación mensual/arrastre/límite/compensación/devolución con `Decimal` (BCMath, **nunca floats**), lógica pura probada antes del acceso a BD.
5. **F6.c:** conciliación, PDB/solicitudes y reportes; QA con casos reales anonimizados y asesoría tributaria.
6. **Hito de aceptación:** reproducir de forma comprobable casos de SUNAT para periodos consecutivos, nacional/mixto/exportador con declaratorias y documentos, antes de permitir solicitudes.

## 8. Fuentes oficiales verificadas (consulta 2026-10-20)

- SUNAT concepto SFE y SFMB: https://orientacion.sunat.gob.pe/01-concepto-de-saldo-favor-materia-de-beneficio-0
- SUNAT determinación: https://orientacion.sunat.gob.pe/3566-02-determinacion-del-sfmb
- SUNAT aplicación/compensación: https://orientacion.sunat.gob.pe/03-aplicacion-del-saldo-favor-materia-del-beneficio-sfmb
- SUNAT límite y FOB/servicios: https://orientacion.sunat.gob.pe/3570-06-determinacion-del-limite-del-monto-a-compensar-o-solicitar-en-devolucion
- SUNAT devolución y reglas especiales de arrastre: https://orientacion.sunat.gob.pe/3568-04-devolucion-del-saldo-a-favor-materia-del-beneficio-sfmb
- SUNAT exportación de bienes/servicios (inafectación): https://orientacion.sunat.gob.pe/11-exportacion-de-bienes-y-servicios
- SUNAT exportación de servicios: https://emprender.sunat.gob.pe/principales-impuestos/impuesto-general-las-ventas-igv/exportacion-servicios
- SUNAT PDB Exportadores: https://orientacion.sunat.gob.pe/3571-07-pdb-exportadores
- SUNAT solicitud de devolución: https://orientacion.sunat.gob.pe/08-solicitud-de-devolucion-del-saldo-favor-materia-del-beneficio

Este documento no sustituye el análisis del contador respecto de condiciones particulares, sectores ni cambios normativos posteriores; **no se ha implementado un cálculo automático fiscal**, ni realizado DDL en Supabase.
