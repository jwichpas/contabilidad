# Gestión de planillas

**Auxiliares → Gestión de planillas** está disponible para administradores y contadores jefes de la empresa activa. El Diario mantiene su propio flujo. La migración laboral `2026_11_24_000000` agrega revisiones por empresa. Las cargas laborales contienen información reservada.

## Flujo del primer alcance

1. Registre la ficha por código interno con DNI, identificación, cargo, fecha de ingreso y cese, remuneración fija mensual, jornada y régimen pensionario. Las condiciones entran en vigor por mes. En AFP se requiere AFP y CUSPP. Se admite el régimen general privado en soles, con jornada diaria de cuatro a ocho horas.
2. Registre asistencia diaria por trabajador y traslade sus minutos al cierre, o registre directamente un cierre sustentado. Los días equivalentes remunerados se valorizan sobre 30 y los minutos extra se clasifican según el control diario. Anote los conceptos validados, la retención de quinta y el sustento del periodo. Para AFP, indique la prima y el tope del seguro y la comisión sobre flujo que corresponda, conforme a las tasas oficiales del mes. Para comisión sobre saldo, consigne la comisión sobre flujo aplicable. El cálculo mensual usa base ordinaria gravada para pensión y EsSalud. Registre la base mínima validada para el mes.
3. Genere y revise la planilla. La aprobación conserva las fichas y asistencia usadas en el cálculo. El borrador contable separa las vacaciones remuneradas y permite seleccionar subcuentas y dimensiones en el Diario antes de registrar. Los cálculos de CTS y gratificaciones también producen propuestas.
4. En vacaciones, registre el saldo acumulado de derechos previamente reconocidos y las fechas acordadas. El sistema controla saldos y superposiciones. Declare en asistencia los días equivalentes de vacaciones incluidos en el pago mensual. La aplicación los separa contablemente dentro de la misma remuneración.
5. En julio o diciembre, ingrese la remuneración computable y el tiempo validado para gratificación. En mayo o noviembre, ingrese la base, tiempo computable y gratificación previa que corresponda para CTS. El sustento debe reflejar las inclusiones, exclusiones y promedios aplicables al trabajador.
6. Desde la planilla aprobada descargue `.jor` de jornada laboral para el PDT PLAME, o el TXT de AFPnet de ancho fijo de 141 bytes por afiliado ordinario con aporte. Verifique la carga con los aplicativos antes de una presentación oficial. Las cotizaciones AFP con excepción de aporte se revisan en su canal de declaración.

## Alcance por completar

Integraciones con relojes de asistencia, incidencias y subsidios detallados, T-Registro, cálculo anual de quinta categoría, remuneraciones variables y regímenes especiales, liquidaciones, rectificaciones y trazabilidad del asiento registrado pertenecen a la siguiente etapa. La jornada `.jor` es una estructura parcial. El detalle de ingresos y descuentos `.rem` requiere distinguir devengo y pago y registrar el concepto oficial de cada importe.

Fuentes de contraste para el [régimen privado](https://www.gob.pe/72288), [ONP y sobretiempo](https://www.gob.pe/es/8246-aportes-a-la-onp-recaudados-por-sunat-conceptos-afectos-a-la-onp), [EsSalud](https://www.gob.pe/es/218-seguro-regular-essalud-seguro-aportes), [tasas AFP](https://www.sbs.gob.pe/app/spp/empleadores/comisiones_spp/Paginas/comision_prima.aspx), [PDT PLAME](https://orientacion.sunat.gob.pe/pdt-plame) y [formato AFPnet](https://www.afpnet.com.pe/files/ManualUsuarioEmpleador.pdf).
