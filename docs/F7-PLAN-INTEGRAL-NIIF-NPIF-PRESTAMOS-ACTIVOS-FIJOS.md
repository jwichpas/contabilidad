# F7 — Plan funcional integral: NIIF, NPIF, NIIF 9, estados financieros y activos fijos

**Estado: PLANIFICADO, no implementado ni certificado.** Revisión: 20/10/2026. Repositorio `jwichpas/contabilidad`, rama `main`. Complementa y desarrolla **Fase 7** del [plan maestro](../README-PLAN-MAESTRO.md), **sin reemplazar F0–F6 ni adelantar escrituras en Supabase**. El código actual de Laravel/Vue deberá evaluarse por funcionalidad; la existencia de este documento no acredita su implementación.

## 1. Regla fundamental: normas según empresa, periodo y jurisdicción

El ERP peruano debe asignar **un marco contable aplicable, su edición y fecha efectiva por empresa y ejercicio**, con historiales de transición; no aplicar automáticamente todas las NIIF a una misma entidad. Separar expresamente el **marco financiero** del **régimen tributario peruano** y del PCGE/catálogos SUNAT:

| Marco | Alcance propuesto | Control obligatorio |
|---|---|---|
| NIIF completas | Entidades a las que corresponda conforme a normas del CNC y su situación concreta | Catálogo versionado NIIF/NIC, vigencia local, políticas y revelaciones |
| NIIF para las PYMES | Entidades elegibles, según límites y reglas peruanas | Edición 2015 frente a tercera edición 2025, cambios de marco y políticas |
| NPIF para microempresas, Perú | Evaluar si la entidad cae en el segmento regulado (referencia de 150 UIT) y sus opciones | Norma peruana distinta de NIIF completas/NIIF para PYMES; vigencia desde 01/01/2025 y verificación legal |
| NIIF 18 | **Solo donde se apliquen NIIF completas** en el periodo correspondiente | Presentación y revelaciones desde ejercicios iniciados 01/01/2027 internacionalmente, sujeto a vigencia/adopción aplicable en Perú |
| NIIF 19 | Revelaciones reducidas para **subsidiarias elegibles** sin obligación pública de rendir cuentas | No sustituye el marco de reconocimiento/medición y no es automáticamente aplicable a PYMES |

**Edición 2025 de NIIF para las PYMES:** vigencia IASB para periodos desde 01/01/2027, con adopción anticipada permitida; confirmar la adopción y disposiciones del CNC para reportes peruanos. **NPIF:** Resolución CNC 001-2024-EF/30, vigencia 01/01/2025. **NIIF 18:** Resolución CNC 003-2024-EF/30 y vigencia internacional 01/01/2027. La foto comparativa adjunta por el usuario es didáctica; límites de UIT, elegibilidad y reglas opcionales deben resolverse **según año y texto normativo oficial**, nunca con una constante global.

**Modelo de reglas:** `accounting_frameworks`, `accounting_framework_editions`, `company_accounting_framework_history`, `accounting_policy_versions`, `regulatory_sources`, `company_standard_adoptions`; versiones por `effective_from/to`, autoridad, norma legal, excepción y evidencia de aprobación. Proteger con roles y auditoría. UI debe mostrar fundamento de cada recomendación y permitir revisión del contador, no inferir marco solo por ventas.

## 2. Estado de resultados y EEFF — NIIF 18 y marcos alternativos

Crear reportes por marco sin duplicar libros ni modificar el libro diario: Estado de Situación Financiera, Estado de Resultados/Resultado Integral, Cambios en Patrimonio, Flujos de Efectivo (NIC 7 donde corresponda) y notas; comparativos, ajustes y políticas.

Para **NIIF completas con NIIF 18**, generar categorías **operación, inversión, financiación, impuesto a las ganancias y operaciones discontinuadas**, con los subtotales definidos **resultado operativo** y **resultado antes de financiación e impuestos a las ganancias**, aplicando las **excepciones de entidades con ciertas actividades principales** (no clasificar todos los intereses o ingresos de inversión mecánicamente). Añadir agregación/desagregación y revelaciones de medidas de rendimiento definidas por la gerencia cuando corresponda. NIIF 18 sustituye NIC 1 para periodos efectivos; no asumir reemplazo completo del resto de normas. Actualizar mapeo con sus modificaciones consecuentes a NIC 7.

**Motor:** `financial_statement_templates`, `statement_line_rules`, `account_statement_mappings`, `statement_runs`, `statement_run_lines` (conceptual), reglas por versión y empresa. Trazabilidad `rubric -> cuentas PCGE -> saldos ledger -> ajuste -> subtotal -> PDF/XLSX`; comparativos y conciliaciones que cierren con Balance de Comprobación. Para NIIF PYMES/NPIF usar reportes propios y revelaciones proporcionadas; **no imponer categorías NIIF 18** a estos marcos.

**Caso de aceptación:** mismos importes netos pero desagregaciones y subtotales diferentes entre versión tradicional y NIIF 18; mantener resultado del ejercicio y conciliación aritmética sin copiar la infografía como plantilla normativa universal.

## 3. Préstamos e instrumentos financieros — NIIF 9

Módulo de obligaciones financieras bancarias y préstamos de terceros, integrable con tesorería, deuda, bancos, contabilidad y activos en construcción:

- Contrato adjunto, moneda, principal contractual, **importe efectivamente recibido**, TEA/TNA/TEM y base de días, fecha de desembolso, comisiones (por ejemplo 3%), costos de transacción atribuibles, seguros y otros pagos, periodo de gracia total/parcial, intereses devengados/capitalizados, cronograma bancario, pagos extraordinarios y refinanciaciones.
- **Dos cronogramas diferenciados:** contractual/caja y **contable a costo amortizado con tasa de interés efectiva (TIE/TIR)**, usando desembolsos netos y flujos estimados según NIIF 9. El 12% TEA contractual **no se presume idéntico** a la TIR contable si hay comisión y otros costos.
- Intereses por devengar, amortización del pasivo, conciliación del saldo, cambio de estimaciones, modificación/reestructuración, bajas, tasas variables, créditos en moneda extranjera y NIC 21, compensaciones/revelaciones NIIF 7 y NIC 32 según caso.
- Generar asientos de desembolso, devengo, pago de cuota, ajustes de valor, diferencias de cambio y cancelación mediante servicio POST idempotente, sin editar asientos contabilizados. Adaptar al marco: NIIF para las PYMES edición 2025 **Sección 11**, no asumir aplicación literal NIIF 9; reglas NPIF específicas.
- **Intereses vinculados con activo apto:** NIC 23 para NIIF completas: capitalizar costos por préstamos **directamente atribuibles** y solo durante periodo habilitado para el activo; otros intereses a gasto. **NIIF para PYMES** posee un tratamiento diferente para costos por préstamos (verificar sección 25 de edición aplicable); jamás extrapolar la regla NIC 23 sin seleccionar marco.
- Tratamiento fiscal de intereses/comisiones: parametrizar devengo, límites de deducción, vinculación, documentación, intereses no deducibles, intereses capitalizados y temporarias **según Ley del Impuesto a la Renta y año**; no inventar porcentajes universales.

**Caso de prueba basado en la imagen:** principal 2,400,000, comisión 3%=72,000, flujo neto 2,328,000; 12% TEA, 12 meses, 3 de gracia. Comprobar TIE con fechas reales y reglas de gracia; validar cuotas y saldo final sin residuos > tolerancia monetaria aprobada. No incorporar directamente la TIR o las cuotas ilustrativas sin recalcularlas independientemente.

## 4. Activos fijos — submódulo integral de F7

**Alcance:** bienes tangibles de PPE, activos por derecho de uso, propiedades de inversión, intangibles, mejoras en locales arrendados, construcciones en curso y activos mantenidos para la venta, **clasificados por su norma correspondiente**; un activo no debe computarse doblemente entre submódulos.

### 4.1 Ciclo de vida y formularios

1. **Solicitud y compra/alta:** importar desde factura o documento estructurado SUNAT (código tipo documento, serie, correlativo, fecha, proveedor/RUC, IGV crédito/no crédito, costo y moneda); adquisiciones, aportes, permutas, fabricación interna, donación y leasing con tratamientos distintos. Clasificar costo vs gasto con política y evidencia, sin imponer un umbral NIIF universal.
2. **Ficha única de activo:** código/QR, componente, serie, bien padre, ubicación y responsable, empresa, sede/área/centro de costo, estado, fotos, póliza, garantía, proveedor, expediente, historial, valor residual, vida útil, método, fecha de disponibilidad y fechas fiscales. Documentos en Storage con hashes, acceso por tenant y trazabilidad.
3. **Costo inicial y componentes:** precio, descuentos, instalación, transporte, pruebas, costos atribuibles, impuestos no recuperables, desmantelamiento/restauración (NIC 37/CINIIF 1) y costos elegibles de financiación (NIC 23 cuando aplique). Separar componentes significativos con vidas útiles distintas, inspecciones mayores y reemplazos.
4. **Obra/construcción en curso:** proyecto padre, etapas, presupuestos, contratos/adendas, valorizaciones, órdenes de compra, materiales, equipos, **horas de mano de obra y cargas atribuibles**, subcontratas, supervisión, intereses elegibles, anticipos, facturas, fotos, hitos y recepción. Registro cronológico y aprobaciones; distinguir costo capitalizable vs gasto/mantenimiento; **traslado de obra en curso a activo operativo cuando esté disponible para el uso previsto**, no simplemente al pagar la última factura.
5. **Depreciación financiera:** desde disponibilidad para uso conforme NIC 16; línea recta, unidades producidas u otro método sustentado; componentes, vida/residual, prorrateos y revisiones prospectivas de estimaciones; suspensión/baja según norma; conciliación al mayor.
6. **Depreciación fiscal peruana INDEPENDIENTE:** desde uso en generación de renta gravada y según reglas fiscales (art. 22 RLIR y normas especiales vigentes); máximos/tasas, requisitos de contabilización cuando correspondan, activos bajo contratos especiales, soporte; libros y conciliación. **No utilizar automáticamente la fecha financiera como fecha tributaria.**
7. **Operaciones posteriores:** mantenimiento vs mejoras, inspección/reemplazo con baja de componente anterior, traslados y responsables, siniestros, deterioro NIC 36 y reversión permitida, revaluaciones y OCI donde aplicable, cambios de vida/residual, suspensión, venta/baja/donación y clasificación NIIF 5.
8. **Arrendamientos:** NIIF 16 activos por derecho de uso y pasivo arrendamiento, cambios contractuales y pagos; distinguir leasing financiero con régimen fiscal peruano propio.
9. **Inversiones inmobiliarias/intangibles/agro:** NIC 40, NIC 38 y NIC 41/CINIIF relacionadas cuando proceda; no depreciar terrenos ordinarios como edificios y no mezclar variaciones de valor razonable con depreciación de PPE.
10. **Cierre y auditoría:** periodos bloqueados, propuestas de devengo/POST, asientos reversibles mediante ajustes, comprobantes, cambios aprobados y bitácora inmutable; reportes por bien, clase, ubicación, responsable, moneda, centro de costos y ejercicio.

### 4.2 Matriz de normas contables (aplicar según transacción)

| Norma | Impacto en el módulo |
|---|---|
| **NIC 16** | Reconocimiento, componentes, medición, depreciación, revaluación, bajas de propiedades, planta y equipo |
| **NIC 23** | Capitalización de costos de préstamos atribuibles a activos aptos |
| **NIC 36** | Indicios, pruebas, pérdidas por deterioro y reversiones permitidas |
| **NIC 12** | Base contable vs base fiscal, diferencias temporarias e impuesto diferido |
| **NIC 21** | Conversión, diferencias de cambio en compra/deuda y saldos monetarios |
| **NIC 37 y CINIIF 1** | Obligaciones de retiro, desmantelamiento/restauración y cambios de estimaciones |
| **NIIF 16** | Derecho de uso, pasivo por arrendamiento y remedición |
| **NIC 40** | Propiedades de inversión y política de medición |
| **NIC 38** | Intangibles, vida útil, amortización y deterioro |
| **NIIF 5** | Activos no corrientes mantenidos para venta y operaciones discontinuadas |
| **NIIF 13** | Técnicas/jerarquía de valor razonable donde otra norma lo requiera |
| **NIIF 9 + NIIF 7 + NIC 32** | Financiamiento, costo amortizado, riesgos, baja y revelaciones |
| **NIC 20** | Subvenciones gubernamentales vinculadas a activos |
| **NIC 8, NIIF 1, NIC 10, NIC 24** | Políticas/estimaciones, primera adopción, hechos posteriores, vinculadas cuando aplique |
| **NIIF 3, NIIF 15, NIC 2, NIC 41** | Altas por combinación de negocios, venta, repuestos/inventario, activos biológicos / plantas productoras donde aplique |
| **NIIF para las PYMES** | Secciones 17 (PPE), 18 (intangibles), 20 (arrendamientos), 21 (provisiones), 25 (costos por préstamos), 27 (deterioro), 29 (impuesto), 30 (moneda extranjera), más Sección 11 de edición 2025 para instrumentos; contrastar textos por edición |
| **NPIF Perú** | Aplicar capítulos simplificados del texto oficial NPIF, NO suponer paridad con NIC/NIIF plenas |

**Importante:** "todas las NIC y NIIF" se interpreta como todas las **aplicables** al hecho económico y marco adoptado, no ejecutar todo el conjunto normativo a un mismo asiento.

### 4.3 Tratamiento tributario peruano y conciliación NIC 12

- Fuente de reglas: **LIR arts. 20, 37 y 38–43; Reglamento art. 22**, normas especiales de arrendamiento y depreciación, resoluciones SUNAT de libros y sus modificaciones por ejercicio. **No fijar reglas permanentes con valores de una sola imagen o año**.
- Registrar costo computable/tributario, mejoras capitalizables, IGV recuperable vs no recuperable, fecha fiscal de uso, depreciación admitida por clase/régimen, ventas/retiros, reparos permanentes y temporarios, depreciación acelerada aplicable previa evaluación de elegibilidad, conciliación contable-fiscal y evidencia.
- Parámetros de referencia que deben versionarse por ejercicio y clasificación: **edificios y construcciones 5% anual por regla general**; equipos de procesamiento de datos **25% máximo**; otros bienes con límites diferentes, según normativa aplicable. **No convertir estos ejemplos en configuración universal o tasas contables**.
- Matriz de diferencias temporarias por activo y componente: `carrying_amount`, `tax_base`, `temporary_difference`, `expected_reversal_period`, tasa fiscal aprobada, `deferred_tax_asset/liability`, y seguimiento de recuperabilidad cuando corresponda.
- Generar propuesta de asientos **activo/pasivo por impuesto diferido** según NIC 12 y revelaciones; diferenciar diferencias permanentes (no generan impuesto diferido) de temporarias; conciliar declaración anual y cierre.
- **Registro de Activos Fijos SUNAT**: formatos y estructuras electrónicos aplicables (p. ej. **7.1**) por periodo, campos oficiales, CUO y validaciones; comprobar obligatoriedad, plazos y versión de estructuras antes de emitir PLE u otro medio.
- Control de documentos SUNAT y asientos PCGE (33/39/68/37/45/etc., según plantilla y transacción); **cuentas configurables por empresa**, nunca códigos rígidos en reglas contables.

### 4.4 Modelo conceptual de datos, Laravel y frontend

Propuesta de entidades; **ninguna tabla se crea con este documento**:

- `fixed_assets`, `asset_components`, `asset_classes`, `asset_locations`, `asset_custodians`, `asset_events`, `asset_documents`.
- `capital_projects`, `capital_project_contracts`, `capital_project_lines`, `capital_project_labor`, `capital_project_milestones`, `capital_project_capitalizations`.
- `asset_accounting_books`, `asset_depreciation_schedules`, `asset_tax_books`, `asset_tax_depreciation`, `asset_impairment_tests`, `asset_revaluations`, `asset_disposals`.
- `asset_temporary_differences`, `deferred_tax_rollforwards`, `asset_journal_links`, `fixed_asset_regulatory_exports`.
- `loans`, `loan_contract_terms`, `loan_contractual_flows`, `loan_effective_interest_schedules`, `loan_payments`, `loan_journal_links`.

Todas las tablas por empresa tendrán `company_id`, integridad entre tenant/registro, `ENABLE + FORCE RLS`, trazabilidad de creador/aprobador, constraints/índices, prohibición de pérdida de historiales y pruebas multiempresa. **Migraciones nuevas solo por Laravel**, contratos REST versionados y servicios de dominio transaccionales. Importes decimales exactos (BCMath/NUMERIC); tablas de calendario deterministas e idempotentes; sin `float` para dinero.

**UX esperada:** ficha maestra y timeline por activo; wizard de alta; proyectos de construcción con expediente completo; simulador de depreciación contable vs fiscal; hoja de conciliación NIC 12; pantalla de préstamo (contrato/cronograma/TIE/asientos) y reportes; exportación SUNAT/EEFF. Un solo formulario de cabecera con líneas relacionadas cuando corresponda, no formularios repetidos por asiento.

## 5. Orden de entrega y criterios de aceptación

Dependencias: resolver F1 tenant/autorización -> F2 motor POST y decimal -> F3 alta de empresa/PCGE -> F5 libros/periodos -> F7. Se permiten diseños y pruebas de cálculo antes, pero **no operaciones POST** de módulos nuevos hasta verificar el motor.

| Incremento | Entregable comprobable | Puerta de aceptación |
|---|---|---|
| F7.0 Marcos normativos | Matriz de aplicabilidad y ediciones por empresa/año; NPIF / PYMES / completas | Casos frontera de 150 UIT y cambios de edición, aprobación del contador, fuentes vigentes |
| F7.1 Activo básico | Alta con SUNAT, ficha y componentes, cronograma contable y fiscal | NIC 16 vs fiscal sin mezclar fechas ni tasas; post idempotente y RLS A/B |
| F7.2 Construcción en curso | Contratos, mano de obra, materiales, valorizaciones, intereses y puesta en uso | Costo auditable; sin depreciación financiera antes de disponibilidad; NIC 23 según marco |
| F7.3 Operaciones posteriores | Mejoras, reemplazos, bajas, venta, deterioro y revaluación | Vida/residual prospectivo, rastreo de costo y efecto tributario/deferred tax |
| F7.4 NIC 12 + registro SUNAT | Conciliación activo por activo, diferido y exportación 7.1 / formatos aplicables | Tie-out a mayor/Registro AF/declaración según ejercicio; soporte de auditoría |
| F7.5 Préstamos NIIF 9 | TIE, gracia, comisiones, devengo, amortización, pago y cambio de condiciones | Cronograma reproducible sin descuadres; diferencia TEA/TIE correcta; asientos balanceados |
| F7.6 EEFF NIIF 18 y alternativas | Resultados por categorías, comparativos, notas y marcos PYME/NPIF | Totales cuadran con ledger y se respetan vigencias/categorías/excepciones |

**Suite mínima:** compra de tres teléfonos (evaluación activo/gasto sin umbral NIIF automático), equipo de cómputo, local construido con costos de personal, mejora vs mantenimiento, siniestro/baja, revaluación, venta con IGV si corresponde, leasing, activo parcialmente financiado, préstamo con 3 meses de gracia y comisión 3%, dos empresas con diferentes marcos y monedas, diferencia temporal NIC 12 y cierre fiscal. Todas las pruebas con fixtures aislados/rollback y revisión por contador de los asientos y el tratamiento tributario.

## 6. Fuentes primarias normativas para la implementación (revalidar al ejecutar)

- IASB [NIIF 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/) y [NIC 16](https://www.ifrs.org/issued-standards/list-of-standards/ias-16-property-plant-and-equipment/).
- IASB [NIIF para las PYMES, edición 2025 y materiales](https://www.ifrs.org/supporting-implementation/2025-ifrs-for-smes-supporting-materials/); [NIC 23](https://www.ifrs.org/issued-standards/list-of-standards/ias-23-borrowing-costs/).
- [MEF/CNC — resoluciones (NIIF 18, NPIF y otras)](https://www.mef.gob.pe/es/consejo-normativo-de-contabilidad/resoluciones-2/resoluciones-cnc), [R. CNC 001-2024-EF/30 NPIF](https://mef.gob.pe/es/consejo-normativo-de-contabilidad/resoluciones-2/resoluciones-cnc/32540-resolucion-de-consejo-normativo-de-contabilidad-n-001-2024-ef-30/file).
- [SUNAT — Reglamento LIR, Capítulo VI, art. 22](https://www.sunat.gob.pe/legislacion/renta/regla/cap6.pdf), [SUNAT — estructura Registro AF, formato 7.1](https://www.sunat.gob.pe/legislacion/superin/2018/anexo-042-2018.pdf).

**No dar por validado** un tratamiento fiscal, fecha, cuenta, tasa o obligatoriedad sin contrastar norma vigente en el periodo, fuente oficial, hechos y documentación. Este plan no es asesoría tributaria automática.
