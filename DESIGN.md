# DESIGN.md — Sistema de diseño v1 · Libro Mayor Perú

**Estado:** Primera iteración de la Fase 1 (identidad visual). No equivale a aprobación visual ni a una auditoría automatizada completada. Se aplica gradualmente a componentes existentes.

## 1. Fuentes de referencia y responsabilidad

| Referencia | Papel dentro del proyecto |
|---|---|
| [UI UX Pro Max](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) | Reglas de estilo, estructura, tablas, tipografía y accesibilidad; compatible con Vue. |
| [Hallmark](https://github.com/Nutlope/hallmark) | Evitar patrones genéricos; identidad editorial propia y consistencia visual. |
| [Impeccable](https://github.com/pbakaus/impeccable) | Evaluación iterativa: critique, audit, polish, layout, harden y optimize. |
| [Frontend Slides](https://github.com/zarazhangrui/frontend-slides) | Solo narrativas de reportes y presentaciones ejecutivas, sin interferir en formularios. |
| [Scroll World](https://github.com/oso95/scroll-world) | Opcional para una landing comercial separada; nunca dentro del registro contable. |

Son referencias para el trabajo del agente, **no dependencias de producción** de Vue. Antes de instalar un CLI, revisar sus scripts, permisos, licencia, costo y efectos en el repo.

## 2. Identidad

**Nombre que ya utiliza la interfaz:** Libro Mayor Perú.  
**Personalidad:** competente, transparente, ágil, serena y precisa.  
**Dirección:** utilitaria premium, con jerarquía clara y elementos de marca discretos, no tarjetas decorativas ni gradientes innecesarios.

La base visual anterior era azul/naranja. Desde la iteración del **12/11/2026**
la identidad de esta rama utiliza los valores **OKLCH exactos** proporcionados
por el usuario, centralizados en `frontend/src/assets/design-tokens.css`.
Este cambio alcanza a los módulos contables actuales y a las nuevas pantallas de
Compras/Ventas porque sus clases Tailwind usan alias semánticos compartidos.

### Roles de color vigentes en esta rama

| Rol | Claro | Oscuro | Uso |
|---|---|---|---|
| Fondo | zinc-50 | zinc-950 | Área de trabajo |
| Superficie de panel | blanco | zinc-900 | Tarjetas, editor, modales |
| Navegación lateral | zinc-100 | zinc-950 | Sidebar |
| Texto principal | zinc-950 | zinc-50 | Datos y títulos |
| Texto secundario | zinc-600 | zinc-400 | Etiquetas y ayuda |
| Borde en reposo | zinc-200 | zinc-800 | Un borde por contenedor |
| Borde marcado | zinc-300 | zinc-600 | Límites de mayor jerarquía |
| Acción primaria | violet-600 | violet-600 | Botones principales |
| Foco | violet-600 | violet-600 | Inputs, selectores, accesibilidad |
| Acento secundario | blue-700 | blue-700 | Acciones secundarias |
| Éxito, texto | green-700 | green-500 | Confirmaciones |
| Advertencia, texto | yellow-800 | yellow-500 | Advertencias |
| Error, texto | red-700 | red-500 | Validaciones y fallos |
| Acento visual | orange-400 | orange-400 | Gráficos e indicadores secundarios |

Las variables `--color-red-*`, `--color-orange-*`, `--color-yellow-*`,
`--color-green-*`, `--color-blue-*`, `--color-violet-*`,
`--color-gray-*`, `--color-zinc-*`, `--color-neutral-*`,
`--color-black` y `--color-white` reproducen los valores facilitados.
Sólo están declarados explícitamente los tonos suministrados: no suponer
que existe, por ejemplo, `--color-zinc-700`.

La familia 500 se conserva en la paleta, pero los colores de texto sobre blanco
pueden emplear los tonos 700/800 para asegurar al menos 4.5:1. Para superficies
oscuras se utilizan normalmente tonos 500 legibles sobre zinc-900.

### Pautas de aplicación

- Mantener el foco violeta `violet-600` y reposo neutro zinc, no duplicar contornos.
- Mantener estados con texto e iconografía, nunca exclusivamente con color.
- Los paneles deben ser sobrios; bordes suaves claros y zinc-800 en modo oscuro.
- No codificar colores hex o de marca en vistas nuevas: usar tokens y utilidades semánticas.
- Validar contraste de los componentes renderizados en ambas modalidades.
- Mantener tipografía y densidad del editor; no introducir animaciones pesadas.

### Tipografía, densidad y cifras

- Sans: Inter cuando esté disponible y fuente del sistema como fallback. No añadir descargas de fuentes en esta fase.
- Importes: `font-variant-numeric: tabular-nums` y alineación a la derecha (`.ui-amount`, `.num`).
- Títulos de página 18–24 px; secciones 14–16 px; etiquetas 12–14 px; ayudas legibles sin depender solo de color.
- Controles: altura mínima compartida `--control-height: 2.25rem` (36px), densidad alta solo donde aporta valor. Objetivos táctiles mayores en móvil.
- Radio: controles `--radius-control: 7px`; paneles `--radius-panel: 10px`.
- Animaciones cortas y útiles; respetar `prefers-reduced-motion`.

## 3. Reglas de interacción

1. Cada pantalla debe tener un título y una acción primaria claramente reconocible.
2. Foco visible para navegación con teclado. El color de foco usa `--focus-color`.
3. Campos requeridos con etiqueta, ayuda y error asociado; nunca representar un error exclusivamente con borde rojo.
4. Deshabilitado, cargando, vacío, éxito y error deben tener mensajes claros y consistentes.
5. En edición de asientos, priorizar lectura de datos, tabulación, precisión y confirmación sobre efectos visuales.
6. Los selectores grandes permanecen autocompletables; no sustituir por listas masivas.
7. La aplicación debe reacomodarse sin cortar códigos, glosas, importes o RUC a 375, 768, 1024 y 1440 px (verificar manualmente).
8. No usar animaciones de scroll, 3D o medios pesados en flujos transaccionales.

## 4. Arquitectura y adopción progresiva

- Tokens de tema: `frontend/src/assets/design-tokens.css`.
- Mapeo Tailwind, estilos globales y clases heredadas: `frontend/src/assets/main.css`.
- Entrada de estilos: `frontend/src/main.ts` importa tokens **antes** de CSS principal.
- Componentes reutilizables: `frontend/src/shared/ui/`, iniciando con `UiButton.vue`.
- Nuevas vistas deben usar tokens y clases compartidas; evitar hexadecimales locales salvo visualizaciones justificadas.
- La adopción de componentes se hará por módulo, en cambios separados con pruebas funcionales.

## 5. Validación manual antes de integrar

```powershell
cd frontend
pnpm typecheck
pnpm test:unit
pnpm build
```

Además de las pruebas: revisar modo claro/oscuro, foco y teclado, zoom 200%, textos extensos, validación en formularios, impresión/PDF, contraste en navegador, desempeño de tablas con muchos registros y el cambio de empresa con borradores.

**Restricción:** ninguna prueba visual, build o test se considera aprobado hasta ejecutarlo y documentar su resultado real. El plan de validación sigue el proceso manual descrito en `docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md`; no agregar GitHub Actions automáticamente.

## 6. Siguientes iteraciones

1. Aceptación de identidad visual y auditoría de pantallas reales.
2. Migración paulatina de botones, etiquetas, formularios y tablas a componentes compartidos.
3. Mejora de layout, dashboard y editor de asientos sin tocar reglas del dominio.
4. Reportes y presentaciones con Frontend Slides; landing externa opcional con Scroll World.

### Login — revisión del 12/11/2026

- `LoginView.vue` adopta los tokens vigentes: superficies zinc y acción violeta en ambos temas. Conserva la marca ContaPerú que muestra actualmente el acceso.
- Composición de dos columnas en escritorio, título y formulario con jerarquías distintas, y referencia tipográfica al libro diario sin cifras ficticias. Se retiran gradientes, órbitas y el documento de cristal.
- En móvil se reduce el bloque introductorio y se oculta la composición decorativa; campos con texto de 16px y controles táctiles de al menos 44px.
- Se mantienen eventos, contrato de autenticación y avisos de sesión. Se añaden asociación accesible del error, estado ocupado del formulario y protección frente a envíos duplicados durante la carga.
- Las cinco referencias orientan la revisión. Frontend Slides aporta criterios de composición; Scroll World se considera como referencia de continuidad visual, sin introducir vídeo, 3D ni navegación por scroll en el acceso.
- Verificado: `pnpm typecheck`, `pnpm test:unit` (129/129), `pnpm build`, `pnpm check:bundle` y `git diff --check`. JS inicial: 238.43 kB; gzip: 77.69 kB.
- Pendiente: inspección visual en navegador, teclado, móvil y ambos temas. Chromium no inicia en este entorno: `socket() failed: Operation not permitted`. La compilación no sustituye esta comprobación; entregar como PR borrador hasta completarla.


## 7. Iteración 2 — Layout, menú lateral y encabezado

- Se sustituyeron las clases claras/oscuras contradictorias del menú lateral por los colores semánticos actuales.
- El menú utiliza `aria-current="page"` para las 13 rutas existentes; mantiene los eventos de navegación, cambio de empresa y el drawer móvil.
- El indicador del menú activo utiliza `--nav-active` independiente de `--brand`, con contraste mínimo 4.5:1 sobre `--soft` en ambos temas.
- Se retiraron las sobrescrituras globales con `!important` que modificaban indiscriminadamente la barra lateral.
- El encabezado ya no presenta un supuesto estado de certificación ISO 27001 a partir de `isLiveConnected`: se limita a informar sobre la última comprobación de API.
- Se retiró la etiqueta fija `2026-10`, que no representaba necesariamente el período activo. Solo debe reintroducirse cuando provenga del estado real de la empresa.
- Se simplificaron los indicadores de escritorio y la cabecera móvil; el selector de empresa móvil sigue accesible dentro del drawer.
- No se modificaron contratos API, lógica de asiento, permisos, rutas ni estados contables.

### Comprobación necesaria en el navegador antes de integrar

Validar en 375/768/1024/1440 px: menú expandido/contraído, drawer y cierre, selector de empresa, estado activo de cada ruta, encabezado con nombre largo, modo claro/oscuro, teclado, zoom 200% y `prefers-reduced-motion`. Verificar visualmente colores aplicados por Tailwind y contraste final con datos reales; estas observaciones no pueden sustituirse con pruebas estáticas.

## 8. Iteración 3 — Libro diario y editor bimoneda

**Objetivo:** ordenar la experiencia de trabajo de alta densidad conservando exactamente el flujo de registro, las reglas de validación y las funciones de decimal.

### Cambios realizados
- `JournalEntryView.vue`: jerarquía de la pantalla, pestañas con `aria-pressed`, ocho campos de cabecera con etiquetas asociadas y advertencia veraz de período sujeto a validación.
- La indicación `ISO 27001 Inmutable` se eliminó porque no representaba una certificación demostrada; también se retiró la misma afirmación del historial.
- Acciones `autoBalance`, `addLine`, `clearForm`, `handleSubmit`, `loadHistory` y creación inicial conectadas a `UiButton`. Estado de registro usa `:loading="isSubmitting"`.
- Totales de Debe/Haber local y extranjero se muestran como cuatro datos semánticos `dl/dt/dd`, conservando `toDisplay(4)` para cada importe.
- Partidas seleccionables en desktop y móvil con `aria-pressed`. Se conservan el editor único, los selectores y las validaciones condicionales.
- Historial: expansión accesible por botón (`aria-expanded`), sin perder el clic existente en la fila; el detalle ancho tiene desplazamiento horizontal propio.
- `main.css`: estilos compartidos `.journal-*` con geometría uniforme, foco visible, densidad compacta y ajustes móviles.
- `frontend/scripts/journal-design.test.mjs`: nuevas pruebas estáticas, agregadas a `pnpm test:unit`.

### Verificación
- Revisión estática directa del contenido de GitHub: **37/37 comprobaciones satisfechas** (no es una ejecución de Node ni un test de navegador).
- No se ha ejecutado `pnpm typecheck`, `pnpm test:unit`, `pnpm build` ni una sesión visual local. **El PR continúa como borrador.**
- Antes de integrar, verificar el flujo con `PEN` y `USD`, una cuenta que exige las cuatro dimensiones, cambio de empresa con borrador, deshabilitación durante POST, autobalanceo, historial desplegable con teclado, visualización en móvil y exportación si aplica.

**Fuera de alcance:** cambios en validación del dominio, cuentas, eventos HTTP, números monetarios, transacciones, migraciones y permisos.

## 9. Iteración 4 — Ajustes derivados de captura real (05/11/2026)

**Evidencia visual:** captura del ERP abierto en escritorio facilitada por el usuario tras ejecutar con éxito `typecheck`, 71/71 pruebas y `pnpm build` en la iteración anterior. Los siguientes cambios son nuevos y necesitan repetir la validación técnica local.

### Hallazgos y soluciones
- Navegación de ancho reducido: varios nombres aparecían cortados por etiquetas secundarias (`Vouchers`, `SUNAT`, `Monedas`). Se amplía el menú desplegado a 17.5rem y se retiran las etiquetas decorativas que competían por espacio. Se preservan los 13 destinos, el comportamiento de colapsar y el drawer móvil.
- Selector de empresa: identificador y régimen se apretaban en una fila. Ahora se muestra el RUC explícito y régimen debajo, manteniendo el mismo selector de empresa y sin exponer información nueva de la API.
- Lectura fatigosa: se mejora la tipografía de navegación, títulos, etiquetas y datos del editor sin inflar los importes ni modificar su formato.
- Cabecera de siete columnas: algunos selectores cortaban opciones. Se limita a cinco columnas en pantallas 2xl, con filas de campos adaptables.
- El estado del asiento quedaba debajo del pliegue: se añade un pequeño indicador en la barra del editor, con `isReadyToPost`, `isBalanced` y la diferencia calculada ya existente. No se presenta diferencia cero antes de ingresar valores y no se cambian fórmulas contables.
- Las tasas de cambio de compra y venta parecían deshabilitadas por el fondo gris: ahora parecen controles editables, sin cambiar sus handlers ni la obligatoriedad.

### Controles añadidos
- Tests de estructura de menú y resumen del editor integrados en `pnpm test:unit`.
- Inspección estática de la rama: **19/19 comprobaciones**, incluyendo igualdad del script contable respecto de `main` una vez excluidas las importaciones visuales.
- **Pendiente:** ejecución local de `pnpm typecheck`, `pnpm test:unit`, `pnpm build` después de esta iteración y nueva captura en claro/oscuro, con ancho reducido y gran tamaño de texto.

### No se hizo
- Ningún cambio en backend, RLS, autorización, POST, líneas contables, conversión exacta, reintentos ni datos persistidos.

## 10. Iteración 5 — Maestros y carga diferida (05/11/2026)

### Objetivo
Completar los cuatro pendientes abiertos tras la validación de 74/74 pruebas: tipos de cambio del libro diario, PCGE, centros de costo/terceros y tamaño del JavaScript inicial.

### Cambios
1. **Libro diario:** criterio de compra/venta, tipo de cambio efectivo y tasas diarias se agrupan en `journal-fx-panel`; se conservan `v-model`, las tasas decimal exactas y los dos `updateRateForCurrency`. Las etiquetas están asociadas a sus controles.
2. **Plan contable:** nuevo título de página, buscador accesible y superficie de filtros compartida. Se preserva `role=treegrid`, navegación por teclado y maestro-detalle.
3. **Centros de costo:** pantalla y formulario de edición utilizan `workspace-heading`, `workspace-toolbar`, `workspace-table`, `workspace-form`/campos y `UiButton`. Las confirmaciones de desactivación mantienen mensajes de conservación histórica y permisos.
4. **Terceros/anexos:** filtros, tabla, formulario, avisos y confirmaciones comparten tokens. Se mantienen los roles autorizados `canWrite` y `canDeactivate`, la unicidad por documento/empresa y `saveEntity`.
5. **Rendimiento:** las trece vistas de dominio antes importadas estáticamente en `App.vue` ahora utilizan `defineAsyncComponent` y `import()`. El shell, el login y el selector de empresa siguen cargándose inicialmente. Estados comunes `UiViewLoading` y `UiViewLoadError` para carga o falla de módulo. El código por empresa sigue usando las mismas claves de remontaje.
6. **Pruebas:** `workspace-design.test.mjs` se incorpora a `pnpm test:unit` para vigilar cargas diferidas, permisos, eventos y estructura visual.

### Validación actual
- Comprobación de anidamiento de etiquetas para ocho plantillas Vue: **sin inconsistencias**.
- Comparación del script de cuatro vistas (Libro diario, PCGE, Centros de costo y Terceros) con `main`: **sin cambios de lógica**, salvo imports visuales.
- Inspección estática de las 13 declaraciones de componentes cargados diferidamente.
- **No se ha ejecutado aún `pnpm typecheck`, `pnpm test:unit` ni `pnpm build` después de esta iteración.** No afirmar que el tamaño del bundle bajó hasta obtener la salida de Vite.

### Aceptación necesaria
Repetir pruebas manuales frontend y revisar rutas tras primer inicio (incluida la latencia de la primera navegación), cambio entre empresas, editor bimoneda y tasas, PCGE, formulario de centro de costo, formulario de tercero, permisos solo lectura, modo oscuro y ancho móvil. Registrar tamaño nuevo de los chunks y comparar especialmente `index-*.js` respecto de la línea base 715,11 kB.

## 11. Iteración 6 — Revisión de capturas de los tres maestros

**Origen:** tres capturas de escritorio facilitadas por el usuario con PCGE, centros de costo y terceros/anexos sobre la rama de diseño. No se atribuye a las capturas la validación de cambios de datos, permisos, teclado ni móvil.

### Hallazgos y decisiones
- **PCGE:** los nueve renglones visibles son nodos del árbol, no el número total de cuentas. El pie ahora distingue `filas visibles` de `cuentas en el plan`, evitando la lectura equivocada de `9 de 2966 cuentas`. Se mantiene el árbol y su inspector lateral.
- **Centros de costo:** la cabecera mostraba el ID interno de la empresa, que no es el RUC. Se sustituyó por una explicación del propósito del módulo; la empresa activa sigue identificada en el selector global.
- **Terceros/anexos:** escribir en el buscador no dispara la consulta automáticamente; el cambio se aplica con Enter o Buscar. El campo ahora informa explícitamente cuándo hay un término sin aplicar y cuándo el filtro está activo. El contador usa `anexos en la consulta`, no `anexos activos` cuando existen filtros. Se retiró el ID de empresa interno del subtítulo.
- No se modificaron métodos de búsqueda, consultas HTTP, cálculo de estadísticas, paginación, permisos, estado del tenant ni lógica de dominio.

### Verificación y aceptación
- Inspección estática en GitHub: 10/10 comprobaciones sobre plantilla, enlaces del texto de ayuda, eventos de filtrado y scripts de dominio conservados respecto de `main`.
- Nuevo test de regresión agregado a `workspace-design.test.mjs`.
- **Pendiente:** volver a ejecutar `pnpm typecheck`, `pnpm test:unit` y `pnpm build` en Windows y verificar visualmente los tres cambios. Las 79 pruebas aprobadas anteriormente corresponden al commit previo a esta iteración.

## 12. Iteración 7 — Búsqueda local y caché TanStack Query

### Decisión: no consultar la base de datos para filtrar un listado completo

- El frontend ya incluía `@tanstack/vue-query@5` y `VueQueryPlugin`; no se agregaron dependencias.
- `EntityMasterView.vue` consulta inicialmente la primera página de 20 terceros, según la API existente.
- Si esa **página sin filtros** confirma `current_page = 1`, `last_page = 1` y `data.length === total`, se utiliza `filterEntitiesLocally`: al escribir cualquier término o cambiar el rol, se filtran **todos los terceros de la empresa activa en memoria**, sin HTTP y sin necesidad de Enter/Buscar.
- Cuando el servidor informa más de una página, no se interpreta la página cargada como directorio completo: se conserva la búsqueda paginada por servidor con Enter/Buscar, evitando búsquedas incompletas y descargas excesivas de datos sensibles.
- La API usa `fetchEntities({ signal })` para poder cancelar peticiones al limpiar caché.
- `queryClient.fetchQuery` almacena respuestas por `['entity-directory', companyId, search, role, page]` (frescura 120 s, recolección 300 s), por lo que repetir un filtro ya consultado mientras esté fresco no repite HTTP.
- El botón Actualizar fuerza invalidación, y guardar/desactivar invalida la caché y vuelve a consultar la página pertinente.
- El contexto de petición fija el Bearer y empresa al comienzo, y rechaza respuestas tardías de otra sesión. `App.vue` limpia la caché en memoria cuando cambia el Bearer o la empresa; no se persiste el directorio en almacenamiento del navegador.

### Comportamiento esperado

- Empresa demo con siete anexos: al escribir `credi` debe aparecer inmediatamente **Banco de Crédito del Perú**, sin peticiones GET adicionales por cada tecla; puede haber una consulta inicial.
- Empresas con más de 20 registros: el botón Buscar sigue disponible, la búsqueda es global y paginada; los resultados ya consultados se recuperan de caché mientras sigan frescos.
- No se modificaron reglas de consulta o permisos del backend, ni se filtró por empresa con un identificador controlado por cliente.

### Criterios de validación

1. Ejecutar `pnpm typecheck`, `pnpm test:unit`, `pnpm build`.
2. Abrir la pestaña Red (Network) del navegador y verificar que teclear en el directorio completo no dispara nuevas llamadas a `/api/v1/company/entities`.
3. Pulsar Actualizar y comprobar una nueva consulta; crear/editar/desactivar un anexo y comprobar invalidación.
4. Con más de 20 anexos, comprobar la búsqueda paginada y que un resultado de otra página no desaparezca por filtrado local incompleto.
5. Cambiar de empresa o cerrar sesión: no deben aparecer terceros de una sesión anterior.
6. Verificar pantalla móvil y mensajes accesibles del buscador.

**Pendiente:** compilación local y pruebas con los datos reales sobre este commit; las 79 pruebas PASS reportadas antes pertenecen a la iteración anterior.

## 13. Iteración 8 — Caché selectiva para maestros, PCGE y referencias

**Estado:** implementado en rama de diseño; pendiente ejecutar nuevamente `pnpm typecheck`, `pnpm test:unit`, `pnpm build` y validación funcional local sobre los últimos commits. La evidencia compartida de 83/83 pruebas PASS corresponde a la iteración anterior.

### Política de caché

| Recurso | Clave TanStack Query | Frescura | Invalidación |
|---|---|---:|---|
| Centros de costo paginados | `['cost-center-master', companyId, search, status, page]` | 60 s | Crear, editar, desactivar, botón Actualizar |
| Selector de centros de costo en PCGE | `['company-catalog', companyId, 'cost-centers']` | 30 s | Cambios del maestro |
| Selector de cuentas operativas | `['operational-accounts', companyId]` | 30 s | Cambios del Plan Contable |
| Subdiarios, lista completa | `['company-subdiaries', companyId, 'all']` | 60 s | Crear, renombrar, activar, desactivar |
| Árbol PCGE | `['account-tree', companyId, onlyActive]` | 30 s | Crear, editar, desactivar, botón Actualizar |
| Monedas, rubros EF y bancos SUNAT | `['reference-catalog', tipo]` | 30 min | Cambio de sesión/empresa; caducidad |
| Áreas de flujo de efectivo por empresa | `['company-catalog', companyId, 'cash-flow-areas']` | 30 s | Caducidad; cambio de sesión/empresa |
| Configuración financiera | `['company-catalog', companyId, 'settings']` | **0 s** | Nueva lectura al abrir editor |

Todos estos caches permanecen únicamente en memoria y se eliminan en `App.vue` cuando cambia el token de sesión o la empresa. La recolección es de 5 minutos para datos empresariales y 60 minutos para referencias globales; las referencias globales se limpian igualmente al cambiar de sesión/empresa. Las consultas protegidas capturan Bearer y empresa de origen, comprueban el contexto al resolver y soportan `AbortSignal` en los endpoints grandes o frecuentes.

### Elección deliberada: mantener datos transaccionales en vivo
**NO se agregan `fetchQuery` con frescura a** asientos contables, tipos de cambio diarios o estados de períodos. El ahorro en estos módulos no debe comprometer valores ni estados fiscales vigentes.

### Comportamiento esperado
- Reabrir Centros de Costo o Subdiarios dentro de la ventana de frescura no repite el mismo GET; los botones Actualizar fuerzan una nueva lectura.
- Cambiar de filtro/página en Centros de Costo y volver al filtro/página reciente reutiliza esa consulta; la búsqueda completa permanece paginada.
- Cambiar el texto de búsqueda en Subdiarios filtra de inmediato el listado existente, sin GET por carácter.
- Reabrir PCGE poco después reutiliza el árbol de cuentas; el botón Actualizar y las mutaciones invalidan el árbol antes de volver a leerlo.
- Abrir una segunda vez el formulario de edición de cuentas reutiliza monedas/rubros/SUNAT, sin reutilizar configuración financiera antigua.
- Una cuenta operativa recién creada o editada invalida la lista que selecciona destinos de centros de costo.
- Tras cambiar de empresa o cerrar sesión, no deben verse resultados almacenados de otro tenant.

### Pruebas nuevas y manuales
- `frontend/scripts/catalog-cache.test.mjs`: seis controles de claves, invalidaciones, aislamiento y exclusión de datos transaccionales, registrados en `pnpm test:unit`.
- Revisar en Network la reutilización de las mismas solicitudes, la carga de una nueva empresa, los permisos y las mutaciones de cada maestro.
- Probar errores HTTP, accesos sin permisos, cierre de sesión durante cargas y refetch explícito del árbol PCGE.

**Sin cambios en backend**, migraciones o reglas financieras. No fusionar hasta validar nuevamente los resultados reales.
