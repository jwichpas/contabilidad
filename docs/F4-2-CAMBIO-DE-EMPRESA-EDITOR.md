# F4.2 · Cambio de empresa en el editor único

**25/10/2026 · Implementación preparada.** El editor de asientos usa la empresa activa global. Al cambiarla, descarta el borrador local, limpia cuentas, subdiarios, centros y áreas cargados para la empresa anterior y vuelve a obtener el catálogo para la nueva. Muestra un aviso cuando había datos sin guardar.

Cada respuesta asíncrona del número de asiento, tipo de cambio, historial, catálogo y envío POST se valida contra la empresa y la solicitud que la inició. Una respuesta tardía de la empresa anterior no debe reemplazar el estado de la nueva. Si el cambio ocurre durante un POST, el aviso pide consultar el Libro Diario de la empresa anterior antes de reintentar: el servidor pudo haber confirmado el asiento aunque el navegador no muestre la respuesta.

Durante el POST, el formulario y sus acciones quedan deshabilitados para que el usuario no altere el borrador ya enviado; el controlador también descarta un segundo intento simultáneo. La conversión bimoneda aplica un residuo exacto de redondeo de cuatro decimales a una partida positiva del lado que lo necesita cuando la moneda base ya cuadra. Se informa el ajuste en el editor. Ninguna partida cuyo importe extranjero redondee a cero se considera lista para registrar, porque el backend exige importe positivo en ambas monedas.

## Comprobación local realizada

Desde `frontend/`: `pnpm build` y `pnpm test:unit` (53/53 PASS, incluidos tres escenarios de redondeo). Estas pruebas y compilación no sustituyen la aceptación visual con sesión y dos empresas.

## Aceptación pendiente con sesión real

1. En DEMO A, escribir una glosa, elegir subdiario y añadir una partida sin registrar. Cambiar a DEMO B con el selector global. Comprobar que el editor muestra el aviso y el borrador está vacío; las opciones visibles deben corresponder a DEMO B.
2. Con red lenta, iniciar carga de DEMO A y cambiar enseguida a DEMO B. Comprobar que el número de asiento, tasas, cuentas e historial de A no aparecen en B al completarse las peticiones antiguas.
3. Registrar un asiento ficticio autorizado y cambiar de empresa mientras el POST está pendiente. Consultar el Libro Diario de la empresa original antes de volver a intentar el asiento; no duplicarlo. Confirmar que la respuesta tardía de A no borra el borrador nuevo de B.
4. Cambiar la fecha mientras carga el editor. Confirmar que el número y las tasas corresponden a la fecha visible, no a la respuesta inicial tardía.
5. Con TC 3,750000, registrar en moneda base dos débitos de 0,0100 y un crédito de 0,0200 en cuentas válidas. Verificar ajuste de 0,0001 en moneda extranjera y cuadre exacto, sin alterar importes en moneda base. Repetir con los lados invertidos y revisar el asiento efectivamente registrado.
6. Durante un POST lento, comprobar que fecha, tasa, importes, dimensiones, «Limpiar» y «Agregar partida» no aceptan cambios. Una segunda pulsación no debe crear un segundo asiento.

## Revisión móvil del editor · 25/10/2026

Las capturas proporcionadas muestran que la tabla de ocho columnas divide una misma partida entre dos pantallas horizontales. También muestran un asiento con importes cuadrado en ambas monedas y anexo obligatorio sin seleccionar; el mensaje anterior lo llamaba genéricamente «descuadrado».

La primera adaptación móvil puso cuenta, glosa e importes en cada tarjeta. Las capturas posteriores confirmaron que seguían siendo formularios repetidos. Se sustituyeron por **un solo formulario de partida seleccionada**, con cuenta, glosa, importes y dimensiones contextuales, seguido de una tabla de partidas agregadas en escritorio y una lista compacta en móvil. «Agregar partida» crea y selecciona una nueva línea; «Editar» vuelve a cargar una existente en ese mismo formulario. Los resúmenes no contienen controles de importe ni selectores duplicados. Cada partida señala sus datos faltantes y el estado distingue falta de anexo/cuenta del descuadre monetario. «Registrar asiento» se habilita cuando cuadran los importes y están completos subdiario, glosa, tipos de cambio y dimensiones. El envío, cálculo decimal y aislamiento por empresa siguen usando el mismo estado y payload.

Compilación `pnpm build` y `pnpm test:unit` (53/53 PASS). Pendiente inspección visual en navegador móvil real: cambiar entre partidas sin perder importes ni anexo, selección del anexo y cambio de empresa durante la edición. Las capturas no demuestran el POST ni la persistencia del asiento.

No dar por aprobada F4.2 hasta verificar también edición de varias partidas, accesibilidad y comportamiento responsive en la aplicación real.
