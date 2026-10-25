# F4.2 · Cambio de empresa en el editor único

**25/10/2026 · Implementación preparada.** El editor de asientos usa la empresa activa global. Al cambiarla, descarta el borrador local, limpia cuentas, subdiarios, centros y áreas cargados para la empresa anterior y vuelve a obtener el catálogo para la nueva. Muestra un aviso cuando había datos sin guardar.

Cada respuesta asíncrona del número de asiento, tipo de cambio, historial, catálogo y envío POST se valida contra la empresa y la solicitud que la inició. Una respuesta tardía de la empresa anterior no debe reemplazar el estado de la nueva. Si el cambio ocurre durante un POST, el aviso pide consultar el Libro Diario de la empresa anterior antes de reintentar: el servidor pudo haber confirmado el asiento aunque el navegador no muestre la respuesta.

## Comprobación local realizada

Desde `frontend/`: `pnpm build` y `pnpm test:unit` (50/50 PASS). Estas pruebas y compilación no sustituyen la aceptación visual con sesión y dos empresas.

## Aceptación pendiente con sesión real

1. En DEMO A, escribir una glosa, elegir subdiario y añadir una partida sin registrar. Cambiar a DEMO B con el selector global. Comprobar que el editor muestra el aviso y el borrador está vacío; las opciones visibles deben corresponder a DEMO B.
2. Con red lenta, iniciar carga de DEMO A y cambiar enseguida a DEMO B. Comprobar que el número de asiento, tasas, cuentas e historial de A no aparecen en B al completarse las peticiones antiguas.
3. Registrar un asiento ficticio autorizado y cambiar de empresa mientras el POST está pendiente. Consultar el Libro Diario de la empresa original antes de volver a intentar el asiento; no duplicarlo. Confirmar que la respuesta tardía de A no borra el borrador nuevo de B.
4. Cambiar la fecha mientras carga el editor. Confirmar que el número y las tasas corresponden a la fecha visible, no a la respuesta inicial tardía.

No dar por aprobada F4.2 hasta verificar también edición de varias partidas, accesibilidad y comportamiento responsive en la aplicación real.
