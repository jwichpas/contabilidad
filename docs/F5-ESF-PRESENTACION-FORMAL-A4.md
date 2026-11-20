# F5 — Estado de Situación Financiera formal (referencia visual del usuario)

## Motivo

La ruta `Estados financieros` venía presentando un **snapshot técnico**
en cuatro tarjetas, sin estructura de clasificación corriente/no corriente,
ni subtotales visibles en dos columnas. Su botón PDF imprimía un resumen
de totales y de resultado en una única columna. El usuario compartió un
balance peruano con encabezado de empresa, fecha de corte, activo en la
columna izquierda y pasivo/patrimonio en la derecha, notas y subtotales.

## Implementación

- Se conserva la consulta tenant-scoped de Laravel
  `GET /api/v1/accounting/reports/financial-snapshot?as_of=YYYY-MM-DD`.
- El backend añade `data.esf_catalog`: referencia **solo de lectura**
  de la tabla existente `financial_statement_rubrics`, filtrada por
  `statement_type = ESF`, ordenada; incluye **rubros estándar sin saldo**.
  Nunca toma montos de otra empresa ni escribe al Libro Diario.
- El módulo de presentación calcula exclusivamente a partir de
  `snapshot.rubrics`, `snapshot.income_statement` y `snapshot.totals`,
  respetando `group_name`:
  - ACTIVO_CORRIENTE, ACTIVO_NO_CORRIENTE.
  - PASIVO_CORRIENTE, PASIVO_NO_CORRIENTE.
  - PATRIMONIO y resultado del ejercicio no cerrado.
- Los rubros faltantes/erróneos se separan como pendientes de clasificación,
  sin atribuirlos ficticiamente a corriente/no corriente.
- El resultado no cerrado de períodos anteriores se expone por separado
  si es distinto del resultado del período; no se reetiqueta.
- El nuevo `FinancialPositionSheet.vue` es la **primera vista** de ESF:
  razón social, RUC, dirección (si existe en la sesión), fecha, moneda,
  dos columnas, notas sin inventar, subtotales, conciliación exacta.
- El antiguo desglose de rubros y cuentas sigue dentro de
  «Ver desglose contable adicional por cuentas y Libro Mayor»; las
  cuentas del ESF formal también son expandibles para abrir el Mayor.
- El botón **PDF ESF formal** imprime en A4 apaisado con las dos columnas,
  mientras **PDF resumen y resultados** conserva la exportación anterior.
- Cifras subyacentes con **4 decimales exactos**, visualización a 2
  decimales (round half-up) sin conversión a tipo flotante.
- No se incluyen números ficticios en la columna «Notas»: «—» indica que
  todavía no hay notas explicativas aprobadas y enlazadas.

## Controles de uso

**Preliminar**: no es un ESF auditado ni un juego completo de estados NIIF.
Faltan asientos de cierre y reclasificación formal de vencimientos,
comparativos y notas explicativas vinculadas. El grupo corriente/no
corriente proviene del **rubric code** existente, no de analizar contratos
ni fechas de vencimiento. Es necesario que el contador valide las cuentas
45 y otros pasivos antes de utilizar el PDF fuera del laboratorio.

Nunca se reutilizan razón social, RUC, dirección ni importes del ejemplo
visual subido por el usuario. Se muestran los datos de la empresa activa.

## Prueba con los 14 vouchers F5 de enero 2026

Al 31/01/2026, en DEMO PCGE Docker, el ESF debe conservar:

- Activo: S/ 94,340.00.
- Pasivo: S/ 30,490.70.
- Patrimonio incl. resultado: S/ 63,849.30.
- Utilidad neta del ejercicio sin cierre: S/ 3,849.30.
- Diferencia exacta de presentación: S/ 0.0000.

Se incluyen pruebas frontend para formato, conciliación, no fabricación
de notas, XSS escapado, subtotal, moneda, período y navegación al Mayor.
Las pruebas Laravel/PG18 sobre la empresa DEMO se ejecutan únicamente
en el equipo del usuario, sin alterar los 14 registros.

## Actualización Windows

```powershell
cd C:\Proyectos\contabilidad
git switch test/ciclo-contable-eeff-niif-2026
git pull --ff-only

cd frontend
pnpm test:unit
pnpm build
```

Reiniciar los procesos locales de backend y Vite después de actualizar.
Entrar en «Estados financieros», elegir **31/01/2026** y «Consultar».
Verificar importes con Libro Diario y abrir «PDF ESF formal».

**No ejecutar migraciones ni recargar vouchers:** este cambio consulta
metadatos existentes y modifica solo la vista y la impresión.
