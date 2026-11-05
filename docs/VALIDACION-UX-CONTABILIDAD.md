# Lista de aceptación funcional y visual del ERP

Esta lista complementa las pruebas automáticas, sin reemplazarlas. En un ERP contable una
compilación correcta no demuestra que los saldos, los permisos o el aislamiento por
empresa funcionen bien en el navegador.

## Preparación

1. Ejecutar `pnpm typecheck`, `pnpm test:unit`, `pnpm build` y `pnpm check:bundle`
   en `frontend/`, o revisar el flujo «Validación frontend» de GitHub Actions en `main`.
2. Abrir la aplicación local con `pnpm dev` y disponer de dos empresas de pruebas con
   catálogos diferentes y un usuario sin privilegios de escritura.
3. No utilizar credenciales ni información contable real en grabaciones, capturas o logs.

## Escenarios de aceptación

| Escenario | Comprobación en navegador | Resultado esperado |
|---|---|---|
| Inicio y navegación | Abrir el ERP, visitar PCGE, Subdiarios, Centros de costo, Terceros y Libro diario | Cada vista carga por separado; menús, encabezados y mensajes de error son visibles |
| Tema oscuro | Cambiar a modo oscuro y recorrer los mismos módulos | Textos y tablas legibles; controles y foco visibles, sin fondos blancos inesperados |
| Adaptabilidad | Probar anchos 375, 768, 1024 y 1440 px y zoom 200 % | No se cortan botones esenciales; las tablas anchas permiten desplazamiento horizontal |
| Teclado | Usar Tab, Shift+Tab, Enter y Escape en formularios y buscadores | Orden de foco coherente, selección y cierre accesibles |
| Cambio de empresa | Escribir un borrador de asiento y cambiar a otra empresa | No se conserva el borrador anterior ni se muestran terceros/cuentas de la otra empresa |
| Libro diario | Elegir subdiario, cuenta, dimensiones requeridas y Debe/Haber en PEN/USD | Cálculos con cuatro decimales, diferencia correcta y bloqueo si falta información |
| Tipos de cambio | Cambiar fecha y criterio y revisar compra/venta diaria | Valores coherentes con la fecha y criterios usados; no se reutilizan tasas obsoletas |
| PCGE | Expandir nodos, buscar cuentas, crear o actualizar una cuenta de prueba | Se actualiza el árbol; «Actualizar» obtiene una consulta nueva y se refrescan selectores dependientes |
| Centro de costo | Crear, editar y desactivar un centro de prueba | Destinos Debe/Haber persistidos, mensajes correctos y registros históricos conservados |
| Terceros/anexos | Escribir `credi` en un directorio pequeño y repetir con uno paginado | Filtrado local inmediato si todo está cargado; búsqueda remota para directorios de varias páginas |
| Caché | Abrir DevTools > Red, repetir una consulta, cambiar empresa y refrescar | No hay GET repetidos innecesarios; sí hay lecturas tras invalidación/cambio de contexto |
| Roles | Acceder con usuario de solo lectura | No puede ejecutar creación, edición ni desactivación |
| Errores | Simular falta de conexión y respuesta HTTP 401/422 de prueba | Mensajes comprensibles; no se muestran tokens; no se pierden permisos del servidor |

## Evidencia y criterio para cerrar

Registrar la fecha, commit de `main`, navegador, tamaño de pantalla, resultado
(APROBADO / FALLÓ) y ruta afectada. Si falla un caso, conservar solo capturas y datos
sintéticos. No declarar cerrada la aceptación funcional por un `build PASS`:
los flujos de lectura, escritura, revalidación y cambio de empresa deben probarse.

## Revisión de rendimiento

El JavaScript de entrada debe mantenerse por debajo de **250 kB** minificados y
**90 kB** comprimidos en gzip (controlado por `pnpm check:bundle`). Es un presupuesto
de tamaño, no una garantía de tiempo de carga. Medir en DevTools también el tiempo
de respuesta del bootstrap del Libro Diario y las solicitudes del backend; no atribuir
al cliente las demoras del servidor sin comprobar `Server-Timing`.
