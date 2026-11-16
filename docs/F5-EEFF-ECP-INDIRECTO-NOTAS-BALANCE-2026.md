# F5 — Continuación de los cuatro informes financieros, 16/11/2026

## Cobertura implementada en la rama de pruebas

- **Balance de comprobación formal** `GET /api/v1/accounting/reports/trial-balance?from=2026-01-01&to=2026-01-31`.
  Presenta por cuenta PCGE saldo inicial deudor/acreedor, movimiento Debe/Haber del
  período, saldo final deudor/acreedor, totales y tres diferencias. Incluye
  cuentas analíticas 9 y puente 79 para que el Libro Diario cierre. Permite
  abrir el Mayor desde la cuenta y PDF horizontal. **No** es un archivo TXT
  PLE 3.17 autorizado.
- **Estado de Cambios en el Patrimonio (ECP)** `GET /api/v1/accounting/reports/equity-changes?as_of=2026-01-31`.
  Compara saldos anteriores, asientos de tipo `OPENING`, movimientos netos de
  cuentas patrimoniales y utilidad del ejercicio **sin cierre**. Se muestran
  movimientos cuya naturaleza legal (aporte/dividendo/reserva) sigue
  indeterminada. No se atribuye un importe OCI (ORI) inventado. PDF horizontal.
- **EFE por método indirecto NIC 7** `GET /api/v1/accounting/reports/cash-flow/indirect?from=2026-01-01&to=2026-01-31`.
  Parte de utilidad neta contable, adiciona depreciación 684, ajusta cuentas
  por cobrar, inventario, proveedores y obligaciones operativas e impuestos,
  excluyendo asientos explícitos de apertura. Compara el flujo operativo
  calculado con el método directo del sistema, y el saldo final con el libro.
  **Si hay diferencia, se señala; NO se agrega un ajuste artificial.**
  Invierte/financia desde la conciliación del método directo, no por glosas.
  PDF preliminar vertical, no TXT oficial SUNAT 3.25.
- **Notas NIIF**: índice de 16 temas con borradores de texto por empresa y
  ejercicio. `GET /api/v1/company/niif-notes?year=2026`,
  `PUT /api/v1/company/niif-notes/N04` con `expected_revision`.
  Sólo `ADMIN` o `CHIEF_ACCOUNTANT` pueden guardar. Todas las notas
  permanecen **DRAFT**, sin aprobación automática; el PDF incluye
  evidencia derivada del Diario cuando existe, pero no inventa políticas,
  contingencias, riesgos, NIIF elegida ni hechos posteriores.
- Cada lectura está autenticada y aislada por la empresa activa (RLS);
  las revisiones de notas son inmutables y protegidas (sólo INSERT/SELECT).
- Los asientos `POSTED` y `REVERSED` se incluyen de forma que los
  extornos se compensen sin borrar los originales; sin filtros indebidos de
  cuentas antiguas inactivas.
- Todos los cálculos del servidor utilizan `NUMERIC` PostgreSQL +
  `BCMath` de cuatro decimales; el frontend sólo redondea al imprimir.
  No se modifica el Libro Diario ni el PCGE.
- Sin aprobación ni comparativos, estos reportes NO constituyen un juego de
  estados NIIF completo ni una constancia PLE/SIRE. Para NIIF 18 (a partir
  del 2027, salvo aplicación anticipada) habrá que revisar el método
  indirecto y la clasificación de intereses/dividendos.

## Caso controlado Docker: 14 asientos enero 2026

Referencias matemáticas independientes del fixture:

| Control | Resultado esperado |
|---|---:|
| Balance: movimientos Debe | S/ 161,430.70 |
| Balance: movimientos Haber | S/ 161,430.70 |
| Diferencia de movimientos y saldos | S/ 0.00 |
| ECP: asiento de apertura de capital | S/ 60,000.00 |
| ECP: utilidad del ejercicio | S/ 3,849.30 |
| ECP: patrimonio final incl. utilidad | S/ 63,849.30 |
| EFE indirecto: utilidad neta | S/ 3,849.30 |
| Ajuste de depreciación | + S/ 300.00 |
| Aumento cuentas por cobrar | − S/ 8,880.00 |
| Aumento de inventarios **sin apertura** | − S/ 3,200.00 |
| Aumento proveedores | + S/ 8,160.00 |
| Variación neta de impuestos por pagar | + S/ 2,330.70 |
| **Efectivo neto de operación (indirecto)** | **S/ 2,560.00** |
| Flujo neto de financiación (directo) | + S/ 20,000.00 |
| Saldo inicial efectivo (incl. apertura) | S/ 40,000.00 |
| Saldo final de efectivo, ambos métodos | S/ 62,560.00 |

IMPORTANTE: el ejercicio fue construido con supuestos contables, entre
ellos tasa de renta 29.5%, y no constituye determinación tributaria.

## Restricciones del EFE indirecto y el ECP

El motor indirecto muestra advertencias cuando hay:
- gasto 68 distinto a 684, ajuste 684 que no coincide con 39, ingresos
  financieros, movimientos o categorías ambiguos o conciliación del
  efectivo directo incompleta.
- financiamientos, impuestos o pagos no sustentados cuya clasificación
  no se pueda derivar de las cuentas y las áreas.
- ausencia de historial al inicio del ejercicio o información no
  contabilizada; el Libro Diario nunca sustituye contratos o notas.

El ECP no deduce aportes o dividendos sólo de un movimiento en 50/59:
esa naturaleza legal debe obtenerse de documentos respaldatorios
y registrarse con reglas independientes de la estructura visual.
Otros resultados integrales y la reclasificación de ejercicios anteriores
siguen pendientes de análisis NIIF.

## Instalación en laboratorio Windows PostgreSQL 18, sin Neon

El usuario confirmó **30 migraciones** después de instalar configuración
de EFE. La migración **31** para el editor de estructuras financieras fue
creada en la etapa anterior, pero **no hay confirmación de que se haya
instalado**. La presente ampliación incluye la **migración 32** de borradores
de notas NIIF.

Actualizar la rama:

```powershell
cd C:\Proyectos\contabilidad
git switch test/ciclo-contable-eeff-niif-2026
git pull --ff-only

cd backend
.\scripts\configurar-estructuras-eeff-pg18-local.ps1 -Accion Plan
```

Si no está instalada la 31, y **solo si PLAN termina sin [BLOQUEO]**:

```powershell
.\scripts\configurar-estructuras-eeff-pg18-local.ps1 -Accion Aplicar
.\scripts\configurar-estructuras-eeff-pg18-local.ps1 -Accion Verificar
```

Una vez que existan **31 migraciones**:

```powershell
.\scripts\configurar-notas-niif-pg18-local.ps1 -Accion Plan
```

Si el Plan confirma base autorizada:

```powershell
.\scripts\configurar-notas-niif-pg18-local.ps1 -Accion Aplicar
.\scripts\configurar-notas-niif-pg18-local.ps1 -Accion Verificar

cd ..\frontend
pnpm test:unit
pnpm build
```

Los scripts limitan escrituras a Docker local conocido (etiqueta,
volumen, puerto, PG18 y migraciones permitidas), ejecutan respaldo antes
de aplicar UNA migración, validan RLS, permisos y conteos del Diario.
**No ejecutar `migrate` general, `migrate:fresh`, borrar volumen,
reimportar asientos ni apuntar a Neon.**

Reportes 3.17/ECP/indirecto sólo requieren actualización de código.
El guardado de notas exige la migración 32.

## Rutas UI

1. **Estados financieros → Balance de comprobación**: corte y PDF
   referencial, acceso al Mayor por cuenta.
2. **Estados financieros → Cambios en el Patrimonio**: movimientos y
   utilidad sin cierre.
3. **Flujo de efectivo → Método indirecto**: período, diferencia
   contra método directo y PDF.
4. **Estados financieros → Notas NIIF**: elegir nota, redactar borrador
   con evidencia verificada, guardar revisión; PDF preliminar.

## Fuentes y alcance normativo

- IFRS Foundation: [IAS 1](https://www.ifrs.org/issued-standards/list-of-standards/ias-1-presentation-of-financial-statements/)
- IFRS Foundation: [IAS 7](https://www.ifrs.org/issued-standards/list-of-standards/ias-7-statement-of-cash-flows.html/)
- IFRS Foundation: [IFRS 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/)
- Catálogos/archivos SUNAT deben validarse contra el Anexo 2 y la
  obligación aplicable al contribuyente y período: PDF referencial
  NO equivale a archivo PLE validado.

Este diseño no acredita todavía cumplimiento integral NIIF, SUNAT ni
informes revisados por auditor externo.
