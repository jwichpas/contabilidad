# F5/F7 — Caso reproducible: ciclo comercial y cuatro EEFF (enero 2026)

**Estado:** validación matemática **ejecutada sin base de datos**. Integración HTTP/Laravel, PostgreSQL/Neon, UI y emisión final NIIF **PENDIENTES**. No es una certificación NIIF ni un asiento de negocio real.

**Archivos:** `backend/tests/Fixtures/financial_lifecycle_2026.json`, `backend/scripts/verify-financial-lifecycle-fixture.php`. La fixture incorpora **14 asientos, 32 partidas y 36 indicadores esperados** en soles con **cuatro decimales**. Los nombres `10_EFECTIVO`, `20_MERCADERIAS`, etc. son **claves simbólicas por familia PCGE**: NO son códigos válidos de seis dígitos que deban enviarse tal cual al editor. Para ensayar HTTP se deben vincular a cuentas operativas reales y a sus requerimientos de anexos, áreas y centros de costo.

## 1. Marco y seguridad

- Periodo **01/01/2026–31/01/2026**; moneda funcional PEN; escenario de empresa comercial **sintética** bajo NIIF completas **sin adopción anticipada de NIIF 18** y PCGE 2019. No seleccionar NIIF completas en empresas que deban reportar bajo NIIF para PYMES o NPIF sin revisar primero su aplicabilidad.
- Resolución CNC 003-2026-EF/30: aprobación del set NIIF 2026 con vigencias propias de cada norma: https://busquedas.elperuano.pe/dispositivo/NL/2552903-1
- NIIF 18: comienza para ejercicios anuales que inician el **01/01/2027**, salvo aplicación anticipada. En 2026 **NIC 1** sigue siendo pertinente cuando no se adopta anticipadamente NIIF 18: https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/
- PCGE 2026 aprobado por Resolución CNC 002-2026-EF/30: obligatorio desde **01/01/2028**, posible uso anticipado según políticas; para esta prueba se usa PCGE 2019: https://busquedas.elperuano.pe/dispositivo/NL/2550786-1
- NIC 2: mercaderías al menor costo/valor neto realizable, promedio ponderado en el ejemplo; el costo de unidades vendidas se reconoce en el mismo periodo que el ingreso. https://www.ifrs.org/issued-standards/list-of-standards/ias-2-inventories/
- NIIF 15: ingresos por venta cuando se transfiere control de mercancía, no cuando se cobra. https://www.ifrs.org/issued-standards/list-of-standards/ifrs-15-revenue-from-contracts-with-customers/
- **Nunca** cargar la fixture en una empresa real ni ejecutar `migrate:fresh`, `seed` masivos, SQL con permisos de propietario o asientos directos por SQL en Neon. Solo probar integración contra empresa/BD de pruebas verificada, mediante las APIs y el servicio POST auditado, con transacción reversible cuando proceda.

## 2. Supuestos mensurables

1. Apertura: banco **S/ 40,000**, mercaderías **100 uds a S/ 100 = 10,000**, equipo al costo **S/ 10,000**, contra capital **S/ 60,000**.
2. Crédito bancario recibido en cuenta: **S/ 24,000**, **sin comisiones ni costos de transacción** en este primer escenario. Devengo de intereses **S/ 240** informado como supuesto, pagados durante enero; no se afirma que sea una TIE calculada según NIIF 9. Se requiere en segunda ronda otro caso con comisiones y cronograma a costo amortizado.
3. Compra a crédito: **100 uds a S/ 120** = S/ 12,000 e IGV **S/ 2,160**; contrapartida proveedor **S/ 14,160**. Registrar compra `60 / 40 / 42` y destino a existencias `20 / 61` sin duplicar gasto.
4. Venta a crédito: **80 uds a S/ 200** = ventas S/ 16,000, IGV S/ 2,880, por cobrar S/ 18,880. Costo promedio ponderado = **S/ 110 por unidad**. Costo de ventas = **S/ 8,800**, inventario final **120 uds / S/ 13,200**.
5. Cobranza cliente **S/ 10,000**, pago proveedor **S/ 6,000**, amortización del principal bancario **S/ 4,000**, pago de intereses devengados **S/ 240**, alquiler **S/ 1,200** y depreciación del mes **S/ 300**.
6. Impuesto a la renta **S/ 1,610.70** como simple supuesto del caso (**29.5% sobre utilidad contable previa S/ 5,460**). No se ha modelado base imponible real, diferencias temporarias, gasto no deducible ni conciliación tributaria NIC 12. No tratar este ejemplo como cálculo definitivo del IR de una empresa.
7. El crédito fiscal se asume **íntegramente utilizable** y el neto IGV exigible queda **S/ 720** dentro de una misma cuenta de liquidación tributaria. Verificar por separado el Registro de Compras/Ventas y la elegibilidad del crédito. Pago de intereses presentado en **operación** bajo política ilustrativa de NIC 7 para 2026; en otros marcos/periodos puede corresponder clasificación diferente.

## 3. Secuencia mínima de asientos de prueba

| ID | Subdiario | Debe (familia PCGE / importe S/) | Haber (familia PCGE / importe S/) | Prueba principal |
|---|---|---|---|---|
| J01 | Apertura | 10: 40,000; 20: 10,000; 33: 10,000 | 50: 60,000 | Saldos iniciales y patrimonio |
| J02 | Bancos | 10: 24,000 | 45: 24,000 | Financiación no es ingreso |
| J03 | Compras | 60: 12,000; 40 IGV: 2,160 | 42: 14,160 | Documento, crédito fiscal y deuda |
| J04 | Compras | 20: 12,000 | 61: 12,000 | Destino a existencias, 60/61 neto cero |
| J05 | Ventas | 12: 18,880 | 70: 16,000; 40 IGV: 2,880 | Ingreso devengado, no cobrado |
| J06 | Ventas | 69: 8,800 | 20: 8,800 | Costo de 80 uds al promedio |
| J07 | Bancos | 10: 10,000 | 12: 10,000 | Cobranza sin ingreso duplicado |
| J08 | Bancos | 42: 6,000 | 10: 6,000 | Pago sin gasto duplicado |
| J09 | Bancos | 45 principal: 4,000 | 10: 4,000 | Baja de deuda, no gasto |
| J10 | General | 67: 240 | 45 intereses: 240 | Devengo de interés |
| J11 | Bancos | 45 intereses: 240 | 10: 240 | Pago sin segundo gasto |
| J12 | Caja/Bancos | 63: 1,200 | 10: 1,200 | Gasto y flujo operativo |
| J13 | Ajustes | 68: 300 | 39: 300 | Depreciación sin salida de efectivo |
| J14 | Ajustes | 88: 1,610.70 | 40 IR: 1,610.70 | Gasto impuesto y pasivo fiscal |

**Precisión:** importes de cuatro decimales, sin `float`. En integración los asientos `J03/J04` y `J05/J06` deben ejecutarse con el flujo PCGE y la clasificación financiera adecuada; el módulo de documentos por sí solo no acredita la salida de inventario ni su costo. La fecha, empresa, subdiario, anexo, cuenta y periodo deben validarse antes de POST.

## 4. Resultado independiente esperado (S/)

### 4.1 Estado de situación financiera — 31/01/2026

| Activo | S/ | Pasivo y patrimonio | S/ |
|---|---:|---|---:|
| Efectivo y equivalentes | 62,560.00 | Obligaciones financieras (principal) | 20,000.00 |
| Cuentas por cobrar comerciales | 8,880.00 | Proveedores | 8,160.00 |
| Inventarios (120 uds) | 13,200.00 | IGV por pagar neto | 720.00 |
| PPE neto (10,000 − 300) | 9,700.00 | Impuesto a la renta por pagar | 1,610.70 |
| **Total activo** | **94,340.00** | **Total pasivo** | **30,490.70** |
| | | Capital inicial | 60,000.00 |
| | | Resultado del periodo | 3,849.30 |
| | | **Total patrimonio** | **63,849.30** |
| | | **Pasivo + patrimonio** | **94,340.00** |

**Conciliación obligatoria: diferencia S/ 0.00**. Controlar corrientes/no corrientes según vencimientos documentados (no inferir que toda obligación bancaria es corriente).

### 4.2 Estado de resultados y otro resultado integral — enero 2026

| Concepto | S/ |
|---|---:|
| Ventas netas | 16,000.00 |
| Costo de ventas | (8,800.00) |
| **Utilidad bruta** | **7,200.00** |
| Alquiler y depreciación | (1,500.00) |
| **Resultado operativo** | **5,700.00** |
| Gastos por intereses | (240.00) |
| Resultado antes de impuesto | 5,460.00 |
| Impuesto a la renta (supuesto) | (1,610.70) |
| **Utilidad neta** | **3,849.30** |
| Otro resultado integral (supuesto: sin operaciones OCI) | 0.00 |
| **Resultado integral total** | **3,849.30** |

Las compras de S/ 12,000 y su destino a existencias `60/61` se neutralizan; **no** registrar otro gasto de S/ 12,000 ni deducir S/ 8,800 dos veces. La conciliación debe provenir del mayor y de los rubros de presentación.

### 4.3 Estado de cambios en patrimonio — enero 2026

| Movimiento | Capital | Resultados | Total |
|---|---:|---:|---:|
| Saldo inicial | 60,000.00 | 0.00 | 60,000.00 |
| Utilidad del periodo | 0.00 | 3,849.30 | 3,849.30 |
| Otros movimientos y OCI | 0.00 | 0.00 | 0.00 |
| **Saldo final** | **60,000.00** | **3,849.30** | **63,849.30** |

No hay asiento de cierre ni asiento de distribución de utilidad en la fixture: el resultado acumulado se calcula para presentación **sin duplicarlo en patrimonio**. Las pruebas posteriores deberán verificar traslado/cierre entre ejercicios, comparativos y reapertura.

### 4.4 Estado de flujos de efectivo directo — enero 2026

| Concepto | S/ |
|---|---:|
| Efectivo inicial | 40,000.00 |
| Cobranza a clientes | +10,000.00 |
| Pago a proveedores | −6,000.00 |
| Pago de alquiler | −1,200.00 |
| Pago de intereses (política 2026 ilustrativa) | −240.00 |
| **Flujo neto de operación** | **+2,560.00** |
| Flujo neto de inversión | 0.00 |
| Desembolso préstamo | +24,000.00 |
| Amortización principal | −4,000.00 |
| **Flujo neto de financiación** | **+20,000.00** |
| Variación neta | +22,560.00 |
| **Efectivo final** | **62,560.00** |

**Conciliación obligatoria:** efectivo final del EFE = saldo de efectivo en ESF = S/ 62,560.00. Las compras a crédito, ventas a crédito, COGS, depreciación y devengo de IR no generan por sí mismos flujos de efectivo.

## 5. Notas mínimas de aceptación

Las notas son **explicaciones del escenario, no un estado NIIF definitivo**. Deben enlazarse a los cuatro informes, a la política adoptada y a cifras del mayor, sin inventar vencimientos ni riesgo bancario:

| Nota | Revelación mínima | Control contra saldos |
|---|---|---|
| 1. Entidad/bases | Moneda PEN, periodo, marco aplicable, empresa ficticia, no comparable real disponible | Datos de empresa y ejercicio |
| 2. Políticas contables | Devengo, reconocimiento ingresos NIIF 15, promedio ponderado NIC 2, PPE NIC 16, préstamos NIIF 9, cash flow NIC 7 | Versión y fecha de política |
| 3. Efectivo | Cuenta bancos S/ 62,560 y conciliación EFE | Mayor 10 = EFE final |
| 4. Clientes | Saldo S/ 8,880; antigüedad y pérdidas crediticias esperadas **pendientes de modelar** | Mayor 12 y cartera |
| 5. Existencias | Inicio 100 uds/S/ 10,000; ingreso 100 uds/S/ 12,000; egreso 80 uds/S/ 8,800; cierre 120 uds/S/ 13,200; VNR pendiente | Kardex = mayor 20; gasto 69 |
| 6. Propiedad/planta/equipo | Costo S/ 10,000, depreciación S/ 300, neto S/ 9,700; política y vida útil por documentar | Mayor 33/39/68 |
| 7. Deuda financiera | Recibido S/ 24,000; capital pagado S/ 4,000; saldo S/ 20,000; intereses del periodo S/ 240 (pagados) | Mayor 45/67/10 y contrato |
| 8. Proveedores e impuestos | Proveedores S/ 8,160; IGV neto S/ 720; renta por pagar S/ 1,610.70 ilustrativo | Mayores 40/42 y registros tributarios |
| 9. Ingresos/costos | Ingreso S/ 16,000, COGS S/ 8,800; utilidad bruta S/ 7,200 | 70/69, 60/61 |
| 10. Juicios/riesgos | TIE y cronograma real, VNR, ECL, clasificación corriente, bases fiscales y comparativos **pendientes** | No etiquetar cumplimiento completo hasta resolver |

## 6. Cómo ejecutar la comprobación hoy

```powershell
cd C:\Proyectos\contabilidad
# Primero incorporar la rama/PR de prueba al directorio local (no necesariamente a main).
php backend/scripts/verify-financial-lifecycle-fixture.php
```

**Evidencia matemática de la fixture ejecutada localmente (sin BD):**

```text
[PASS] 14 asientos, 32 partidas y 36 verificaciones.
[PASS] Activo 94340.0000 = Pasivo 30490.7000 + Patrimonio 63849.3000
[PASS] Resultado neto 3849.3000, costo de ventas 8800.0000
[PASS] Flujo: inicial 40000.0000 + operacion 2560.0000 + inversion 0.0000 + financiamiento 20000.0000 = final 62560.0000
[AVISO] No se han registrado asientos en el ERP ni probado sus endpoints.
```

Este validador **no requiere dependencias Composer ni acceso a Neon**. Al trasladarlo a pruebas de integración, los valores esperados **deben compararse con el resultado real del POST y de los GET**, no con otro calculador de la propia fixture; en caso contrario existiría una prueba circular.

## 7. Plan de prueba del sistema real (pendiente)

1. **Preflight de entorno:** verificar rama de Neon realmente `contabilidad-dev`, rol `app_backend`, permisos RLS, backup, migraciones presentes (en especial las compras/ventas del 12/11), subdiarios y periodos abiertos. Nunca usar datos reales ni escribir con rol propietario. Separar dos empresas para prueba de aislamiento. Consultar `docs/MIGRACIONES-COMPRAS-VENTAS-NEON.md`.
2. **Maestros y apertura:** definir periodo enero, cuentas operativas válidas, rubros para ESF/ERN, entidades demo y dimensiones obligatorias; enviar J01 como tipo `OPENING`; comprobar diario, mayor y balance de comprobación.
3. **Financiación:** J02 y J09/J10/J11. Comprobar principal vs interés, cuentas por pagar e identificación de actividades en EFE; registrar otro escenario con comisiones, TIE/costo amortizado y conciliación NIIF 9, **no disponible aún como motor automático**.
4. **Compra y costo:** J03 con módulo Compras y asiento espejo J04; J05 con módulo Ventas y asiento espejo J06. Comprobar documento, IGV, stock/kardex y costo de ventas. Si falta motor de inventarios, documentar como **GAP** y, en test aislado, usar asiento manual autorizado sin llamarlo automatización.
5. **Tesorería:** J07/J08/J09/J11/J12 con área de flujo especificada en la **partida de efectivo**, no solo en la contrapartida. Correlacionar terceros, documentación, trazabilidad y saldos; comprobar cobros/pagos parciales.
6. **Ajustes:** J13/J14; no permitir posteos duplicados del devengo, ni reclasificar compras como gasto adicional. Revisión de estimaciones e IR por responsable contable.
7. **Reportes:** GET `/api/v1/accounting/reports/financial-snapshot?as_of=2026-01-31`, `/api/v1/accounting/reports/cash-flow?from=2026-01-01&to=2026-01-31`, libro mayor; contrastar **cada indicador** con fixture e identificar falta de rubros. El endpoint de flujo declara siempre `is_preliminary=true`; no promocionar a definitivo por diferencia cero si queda clasificación incompleta.
8. **Informes faltantes:** implementar/calcular ECP (apertura + resultado + aportes/distribuciones + OCI), ERI cuando proceda y notas referenciadas a rubro/cuenta/transacción. Incorporar estado, política, comparativo y control de integridad de revelaciones, no texto NIIF genérico.
9. **Regresiones:** extorno, duplicado, periodo cerrado, compra/venta exonerada y gravada, nota de crédito, devoluciones, deterioro de inventario, diferencia de cambio, intereses no devengados, clasificación de préstamos corrientes/no corrientes, monedas funcionales distintas, liquidación IGV, valoración NIIF vs impuesto, múltiples empresas y cierre/reapertura anual.
10. **Aceptación de cada estado:** asiento cuadrado y autorizado; balance de comprobación sin diferencia; **sin cuentas con movimiento sin rubro**; `Activo = Pasivo + Patrimonio`; resultado = variación explicada en ECP; efectivo final EFE = mayor 10 configurado = ESF; notas enlazadas a saldos y políticas con evidencia; comparativos y revisión de profesional contable.

## 8. Hallazgos de código y límites actuales

- `GetFinancialSnapshotController` genera ESF/ERN preliminares y rubros, sin comparativos formales ni ECP completo. No convierte automáticamente el 60/61/69 en un flujo de inventario correcto por producto.
- `CashFlowReportService` y calculador EFE directo son preliminares; exigen áreas y cuentas de efectivo configuradas, con transacciones fuera de clasificación identificadas.
- `DocumentJournalController` registra documentos con configuraciones de cuentas y tributación; **no certifica** por sí mismo kardex, NIC 2, costo de ventas o conciliación contable-fiscal.
- `docs/F7-PLAN-INTEGRAL-NIIF-NPIF-PRESTAMOS-ACTIVOS-FIJOS.md` identifica módulos de NIIF 9, PPE y estados con notas **planificados, no implementados**.
- **Defecto normativo a corregir antes de certificar catálogo:** `AccountingStandardsAndRubricsSeeder.php` describe NIIF 18 como reemplazo de NIC 1 desde el **1 de enero de 2026** y marca NIC 1 retirada en 2026; los textos del IASB establecen **2027** (adopción anticipada permitida). El seeder también debe representar vigencia temporal por ejercicio y marco, no depender solo de un texto/status global. Evitar que la UI anuncie que se aplicó NIIF 18 obligatoriamente en 2026.

**Condición de salida:** este documento es una **especificación verificable**. Solamente se podrán marcar como `PASS ERP` los casos ejecutados contra el motor y los reportes reales, con evidencias de respuesta y conciliación, sin depender de suposiciones del ejemplo.