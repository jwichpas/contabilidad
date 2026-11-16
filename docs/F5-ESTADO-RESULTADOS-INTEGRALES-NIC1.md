# F5 — Estado de Resultados Integrales (ERI), presentación preliminar 2026

## Solicitud y objetivo

La referencia aportada por el usuario muestra, en formato vertical, nombre de
empresa, RUC y año, ventas netas, descuentos, costo de ventas, utilidad bruta,
otros ingresos, gastos de venta, de administración, utilidad operativa,
ingresos/gastos financieros, diferencias de cambio, utilidad antes de impuesto,
impuesto a las ganancias, utilidad neta, otros resultados integrales y total.

Se incorpora la pestaña **Estado de Resultados Integrales** dentro de
**Estados financieros**, compartiendo fecha de corte y empresa con el ESF
existente, y el botón **PDF Resultados Integrales** (A4 vertical).

La vista usa únicamente `GET /api/v1/accounting/reports/financial-snapshot`
y por tanto NO crea asientos, NO altera el plan de cuentas ni necesita migrar.

## Cálculos y controles

- La clasificación proviene de `accounts[].code`, `accounts[].rubric`,
  `accounts[].section`, `accounts[].account_type` y
  `period_amount_local` del endpoint contable del tenant.
- La cuenta 70 presenta ventas; 709 descuenta (si existe); 69 costo de
  ventas; 75 otros ingresos operativos; 77/67 ingreso/gasto financiero
  (776/676 diferencia de cambio); 88 impuesto a las ganancias.
- Solo se presenta como **administración/ventas** cuando la cuenta de
  resultados tiene asignado explícitamente
  `ERF_GASTOS_ADMIN_94` o `ERF_GASTOS_VENTAS_95`. No se deduce la
  función de una glosa, de una cuenta 63/68 o de la mera existencia de
  asientos 94/95 (podrían duplicar gastos por naturaleza).
- 60 compras y 61 variación de existencias se **netean como parte de los
  gastos operativos sin distribución**, para no sumar otra vez la
  compra cuando ya se registró 69 costo de ventas. Si queda un saldo
  neto real, no se oculta.
- Otras partidas del resultado no identificadas se presentan
  explícitamente en «pendientes de clasificación». La reconciliación
  usa la utilidad neta reportada por el backend; cualquier diferencia
  se muestra con una advertencia.
- El impuesto 88 se presenta como gasto y NO como patrimonio, aun
  si el catálogo histórico de esa cuenta conserva `EQUITY`.
- El total de resultados integrales es **pendiente**: el sistema aún
  no dispone de un flujo verificable para clasificar partidas
  de **otros resultados integrales (ORI)** conforme a NIIF.
  No se inventa 0.00 ni se declara falsamente un total completo.
- Porcentajes respecto de las ventas netas; cuando no hay ventas netas
  se muestra «—». Importes originales a 4 decimales, presentación
  redondeada a 2 sin aritmética IEEE-754. Gastos entre paréntesis
  y resaltados en rojo.
- La columna Nota lleva «—» mientras no haya notas reales vinculadas.
  Los datos de la empresa proceden de la sesión activa (sin los de
  la captura de ejemplo).
- La navegación a los movimientos desde una línea se mantiene mediante
  la vista del Libro Mayor.

## Prueba de 14 asientos de enero 2026, empresa Docker PCGE

| Concepto | Resultado esperado |
| --- | ---: |
| Ventas netas | S/ 16,000.00 |
| Costo de ventas 69 | (S/ 8,800.00) |
| **Utilidad bruta** | **S/ 7,200.00** |
| Alquiler 63 + depreciación 68 (sin función asignada) | (S/ 1,500.00) |
| **Utilidad operativa** | **S/ 5,700.00** |
| Gasto financiero 67 | (S/ 240.00) |
| **Utilidad antes de impuesto** | **S/ 5,460.00** |
| Impuesto a las ganancias 88 | (S/ 1,610.70) |
| **Utilidad neta** | **S/ 3,849.30** |
| ORI y total integral | Pendientes de validación |

El impuesto del laboratorio es una hipótesis para las pruebas; no
equivale a una determinación tributaria.

## Norma y alcance

Para períodos de 2026, la referencia de estructura de gastos por función
y de otros resultados integrales es NIC 1. **NIIF 18** sustituye NIC 1
para ejercicios anuales iniciados a partir del **1 de enero de 2027**,
salvo aplicación anticipada; la transición y detalles de presentación
2027 se implementarán separadamente. Tampoco se han habilitado
comparativos ni las notas detalladas a los estados financieros.

Fuentes:
- [IFRS Foundation — IFRS 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/)
- [IFRS Foundation — IAS 1](https://www.ifrs.org/content/dam/ifrs/publications/pdf-standards/english/2022/issued/part-a/ias-1-presentation-of-financial-statements.pdf?bypass=on)

## Verificación

```powershell
cd C:\Proyectos\contabilidad
git switch test/ciclo-contable-eeff-niif-2026
git pull --ff-only
cd frontend
pnpm test:unit
pnpm build
```

Reiniciar Vue/Vite si el proceso está ejecutándose y consultar
**Estados financieros → Estado de Resultados Integrales**,
seleccionar **31/01/2026** y pulsar **Consultar**.
Usar **PDF Resultados Integrales** para obtener A4 vertical.

No ejecutar migraciones, no aplicar fixtures y no volver a cargar
asientos: el cambio es solamente de lectura y presentación.
