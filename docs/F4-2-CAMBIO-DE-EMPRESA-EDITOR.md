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

La captura posterior del buscador de cuentas mostró opciones superpuestas con los importes: el selector usaba `bg-surf`, una clase sin token en el tema Tailwind actual. El selector ahora usa `bg-surface` y bordes del tema. En móvil abre un diálogo opaco con búsqueda y resultados desplazables; en escritorio conserva la lista flotante, también con fondo sólido. El filtrado sigue siendo local al catálogo cargado. Compilación y 53/53 pruebas frontend aprobadas. Queda pendiente comprobar en el teléfono el teclado, la selección y el retorno al formulario.

No dar por aprobada F4.2 hasta verificar también edición de varias partidas, accesibilidad y comportamiento responsive en la aplicación real.

## Primer asiento registrado en DEMO A · 25/10/2026

Captura del usuario: éxito de `10-00001`, historial con un asiento y siguiente correlativo `10-00002`. Consulta de solo lectura en Neon `contabilidad-dev` (26/10/2026 02:12 UTC, equivalente a 25/10/2026 21:12 en Lima): `journal_entries.id = 109`, empresa `DEMO A - Comercial Andina S.A.C.`, fecha contable 26/10/2026, estado `POSTED`, subdiario `CAJA`, moneda `PEN`, TC `3.758000`.

Hay dos filas persistidas en `journal_entry_lines`, ambas en cuenta `101000` y con área de flujo `9`: la primera Debe S/ 100.0000 y USD 26.6099; la segunda Haber S/ 100.0000 y USD 26.6099. Los totales cuadran exactamente en las dos monedas. Al debitar y acreditar la misma cuenta por el mismo importe, el efecto neto de este asiento de prueba en esa cuenta es cero. Esta evidencia acredita un POST y la persistencia bimoneda, pero no acredita un caso económico con cuentas distintas, el cambio de empresa durante POST ni los escenarios de redondeo de 0.0001.

## Segundo asiento con cuentas distintas · 26/10/2026

La captura del usuario confirma el éxito de `10-00002` y muestra el siguiente correlativo `10-00003`. Una consulta de solo lectura a Neon DEV verificó `journal_entries.id = 110` en DEMO A: fecha 26/10/2026, tipo `OPENING`, estado `POSTED`, cuatro líneas en cuatro cuentas distintas. Se persistieron débitos en `101000` por S/ 3400.0000, `201110` por S/ 6480.0000 y `104100` por S/ 9240.0000 (esta última con anexo), y un crédito en `501100` por S/ 19120.0000. Ambos lados suman S/ 19120.0000 y USD 5087.8127. Esto acredita el POST bimoneda de cuentas distintas y un anexo persistido, sin afirmar la pertinencia contable de las cuentas de un asiento ficticio.

La captura móvil del selector de anexos reveló otro `bg-surf` sin token de Tailwind: el contenido quedaba transparente sobre el formulario. El diálogo ahora ocupa la pantalla en móvil, usa superficies opacas con tokens del tema, resultados desplazables, foco de búsqueda y cierre con Escape; la búsqueda sigue siendo explícita. Se añadieron indicadores y skeletons para verificación de sesión, bootstrap del editor, historial y lista de anexos, y se evita mostrar `Libro Diario (0)` antes de consultar el historial. `pnpm build` y `pnpm test:unit` **53/53 PASS** en el workspace. Falta revisión visual en el teléfono tras recibir el cambio, además de cambio de empresa durante POST y redondeo de 0.0001; **F4.2 permanece abierta**.

## Conmutación visible durante un POST · 26/10/2026

Las capturas y el log del servidor PHP de desarrollo mostraron un POST de asiento con respuesta `201` seguido de solicitudes `POST /tenant/switch-company` con `200`. La conmutación parecía esperar a que acabara el guardado, porque `switchCompany()` solo actualizaba el estado global después de recibir la respuesta HTTP. En Neon DEV se verificó el asiento `10-00003` (`id = 111`) en **DEMO A**, `POSTED`, dos cuentas distintas; la pantalla posterior muestra **DEMO B** y su siguiente correlativo `10-00001`. El asiento no fue atribuido a B.

La selección de una empresa asignada se refleja ahora de inmediato. Se bloquea el contenido mientras el servidor confirma la membresía; una respuesta rechazada restaura la empresa previa y muestra error. Las solicitudes ya iniciadas conservan su cabecera `X-Company-Id` original; las del nuevo tenant no permiten interacción mientras la validación está pendiente. Una segunda selección simultánea se ignora, y un cambio mientras había un POST muestra el aviso de consultar el Libro Diario de la empresa anterior antes de reintentar. Test de conmutación con respuesta diferida: cambio visible antes del HTTP, cabecera anterior preservada, exclusión de cambios simultáneos y reversión por `403`. Build y `pnpm test:unit` **54/54 PASS**. Pendiente comprobar la secuencia con navegador y red real en el teléfono; **F4.2 sigue abierta**.

## Captura móvil con cambio durante el guardado · 26/10/2026

La nueva captura muestra DEMO B en el selector y «Verificando acceso» mientras el servidor procesa el cambio. El log muestra `POST /journal-entries` con `201` a las 06:21:48 (hora local del dispositivo), seguido de `POST /tenant/switch-company` con `200` a las 06:21:57. Una consulta de solo lectura a Neon DEV verificó `10-00004` (`id = 112`) **POSTED en DEMO A** con dos cuentas distintas; Debe y Haber S/ 600.5000. No aparece un asiento en DEMO B entre los últimos asientos de ambas empresas. La cabecera B durante esa espera indica la empresa solicitada, pendiente de confirmación, sin atribuir a B el POST que ya se había iniciado en A.

La pantalla de espera dejaba un área móvil casi vacía. Se sustituyó por un estado con explicación, aviso visible cuando había un POST en curso y skeletons del editor. Al desmontar la vista de A se invalidan también las respuestas asíncronas de POST, correlativo, tasas, historial y bootstrap para evitar que la instancia antigua solicite datos adicionales tras el cambio. `pnpm build` y `pnpm test:unit`: **54/54 PASS**. Las mediciones del log incluyen unos 9 s para confirmar la conmutación y unos 10 s para cargar el bootstrap de B en este servidor de desarrollo; esta mejora visual no reduce esos tiempos. Falta repetir la aceptación en móvil y medir/optimizar la latencia del backend. **F4.2 permanece abierta.**
