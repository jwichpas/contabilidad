# Vencimientos SUNAT por empresa

En **Cierre y reportes → Vencimientos SUNAT**, elija el ejercicio y el periodo. El sistema usa el RUC de la empresa activa para consultar las tablas oficiales de **2025 y 2026**. Cada obligación muestra su periodo, su fecha y el sustento. Los vencimientos de diciembre pueden caer en enero del ejercicio siguiente.

El responsable contable confirma el tramo de ingresos aplicable a los libros, la modalidad de registros, PLAME, ITAN y la columna que corresponde en cada cronograma. El régimen de cada mes se toma del historial fiscal. Para periodos anteriores a la primera versión se usa el régimen de la configuración general de la empresa.

| Obligación | Criterio de fecha |
| --- | --- |
| Registro de Compras y Registro de Ventas electrónicos | Tabla de máximo atraso por periodo y grupo de RUC |
| Declaración y pago mensual y PLAME cuando corresponde | Tabla mensual por periodo y grupo de RUC |
| Diario, Mayor, Caja y Bancos y demás libros aplicables | Máximo atraso del anexo, por periodicidad y según la modalidad registrada |
| Renta anual | Tabla general o ampliada aplicable al ejercicio y columna del contribuyente |
| ITAN cuando corresponde | Cronograma ordinario del periodo de la declaración o cuota |
| Obligaciones por operación y prórrogas | Fecha particular con referencia del hecho o la norma que la sustenta |

El cómputo ordinario de días hábiles usa fines de semana y feriados nacionales. Registre individualmente las prórrogas sectoriales, territoriales y fechas especiales. Los libros sectoriales del anexo pueden incluirse o excluirse según la empresa. Las obligaciones ausentes del catálogo se pueden incorporar por ejercicio o por mes, con su sustento. Las fechas que dependen de un hecho pendiente o de un cronograma aún sin cargar se muestran como **Por confirmar**.

En octubre de 2026, los RUC terminados en 4 o 5 tienen el 18 de noviembre para los registros electrónicos y el 19 para la declaración mensual. Para RUC terminado en 0, corresponden el 13 y 16 de noviembre, respectivamente.

## Fuentes

- [Calendario mensual 2025](https://www.sunat.gob.pe/orientacion/cronogramas/2025/cObligacionMensual2025.html) y [2026](https://www.sunat.gob.pe/orientacion/cronogramas/2026/cObligacionMensual2026.html).
- [Máximo atraso RCE y RVIE 2025](https://www.sunat.gob.pe/orientacion/cronogramas/cronoRegistroC-V-2025.html) y [2026](https://www.sunat.gob.pe/orientacion/cronogramas/cronoRegistroC-V-2026.html).
- [Libros por régimen](https://emprender.sunat.gob.pe/comprobantes-libros/registros-libros-electronicos/libros-registros-contables-obligatorios), [R.S. 234-2006/SUNAT](https://www.sunat.gob.pe/legislacion/superin/2006/234.htm), [anexo de máximo atraso](https://www.sunat.gob.pe/legislacion/superin/2006/234_anexo1_2.xls) y [modificación R.S. 257-2025/SUNAT](https://www.sunat.gob.pe/legislacion/superin/2025/000257-2025.pdf).
- [R.S. 169-2015/SUNAT sobre inventario valorizado electrónico](https://www.sunat.gob.pe/legislacion/superin/2015/169-2015.pdf) y [cronogramas de Renta anual](https://renta.sunat.gob.pe/empresas).

## Actualización y despliegue

Las tablas base se versionan en `backend/database/reference_data/sunat_due_dates_*.json`. Compare cada ejercicio con las publicaciones oficiales y la fecha de diciembre del año siguiente. Los perfiles y excepciones quedan en revisiones separadas por empresa y ejercicio. El calendario y los auxiliares requieren las migraciones `2026_11_21_000000` y `2026_11_21_000001` con la conexión de migraciones del proyecto.

## Agenda contable mensual

1. En **Vencimientos SUNAT**, guarde el perfil del ejercicio y las fechas particulares sustentadas. Las fechas oficiales de compras, ventas y tributos se cargan por periodo y RUC.
2. En **Cierre y reportes → Agenda contable mensual**, un administrador o contador jefe confirma el periodo, la condición de PRICO, las designaciones de retención y percepción del IGV y las obligaciones laborales aplicables. La revisión guarda las fechas y el sustento. Si el calendario cambia, vuelva a confirmar ese periodo para aplicar las fechas corregidas a la agenda. Ante un ejercicio sin cronograma cargado, registre cada fecha necesaria con su fuente en el calendario.
3. Desde enero de 2025, el Diario, sus extornos y los registros de compras y ventas requieren confirmar los vencimientos del mes de la **fecha contable de la operación**. Los reportes, la navegación y la configuración siguen disponibles. Despliegue primero la migración `2026_11_22_000000` y confirme los meses activos de cada empresa.
4. Complete las tareas con sustento. La campana informa vencidas y próximas a siete días de los periodos confirmados de la empresa. La fecha interna se configura por cada cierre y se usa para conciliación bancaria, saldos de terceros, revisión de mayores, planilla y depreciación cuando corresponda. Las obligaciones legales siguen sus propias fechas.

PRICO se registra con su sustento de designación y crea una revisión del directorio y de las notificaciones. La columna de buenos contribuyentes y UESP se configura en el calendario. La retención y percepción del IGV generan las tareas 626 y 633 cuando se confirma su aplicabilidad. Las prórrogas específicas de una obligación se registran con su propio código, como `626`, `633`, `PLAME`, `AFP`, `CTS` o `GRATIFICATION`. Los vencimientos ordinarios de AFPnet, CTS y gratificaciones se muestran según la obligación marcada para el personal.

### Papeles de trabajo

- **Depreciación.** Genera una revisión desde las fichas vigentes de activos, con importe contable, valor en libros y estimación tributaria ordinaria en PEN. La parte fiscal requiere datos del mismo ejercicio y, cuando corresponde, la depreciación contabilizada como límite. La tarea se completa después de revisar los datos fiscales. **Preparar asiento en el Diario** abre el borrador con las referencias 68 y 39 para que el contador asigne subcuentas, dimensiones y subdiario.
- **Planilla.** Un administrador o contador jefe consolida por código de trabajador las remuneraciones y los importes validados de aportes, descuentos y retenciones. El cálculo suma ingresos, descuentos, neto y costo del empleador. La declaración PLAME se sigue como tarea separada y se presenta en su canal habilitado. Las revisiones conservan el papel de trabajo y el responsable.

Fuentes laborales: [PLAME](https://orientacion.sunat.gob.pe/pdt-plame), [AFPnet](https://www.sbs.gob.pe/boletin/detalleboletin/idbulletin/73), [CTS](https://www.sbs.gob.pe/usuarios/aprende-con-la-sbs/cuenta-cts) y [gratificaciones](https://cdn.www.gob.pe/uploads/document/file/3321103/guia_gratificaciones.pdf).
