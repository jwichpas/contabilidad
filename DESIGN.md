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

La paleta azul/naranja estaba documentada en `main.css` pero no coincidía con los valores aplicados. Fase 1 la consolida en `frontend/src/assets/design-tokens.css` preservando alias existentes.

### Colores canónicos

| Rol | Claro | Oscuro | Aplicación |
|---|---|---|---|
| Fondo | `#FFFFFF` | `#0A1230` | Área de trabajo |
| Barra lateral | `#F4F6FB` | `#0A1230` | Navegación |
| Superficie | `#FFFFFF` | `#101B42` | Paneles y diálogos |
| Texto principal | `#0B1B3F` | `#E8EDFA` | Contenido prioritario |
| Texto secundario | `#5A6683` | `#9AA8CF` | Ayudas, etiquetas |
| Bordes | `#DCE2EE` | `#223062` | Separadores |
| Acción primaria | `#002A8D` | `#2F5FD6` | Botones principales |
| Acción destacada | `#AD4F00` | `#A84F00` | CTA en naranja sobre blanco |
| Acento como texto | `#A84F00` | `#FFB766` | Enlaces/avisos, separado del fondo |
| Éxito | `#1F7A4D` | `#5FD09A` | Estado positivo |
| Advertencia | `#995000` | `#FFB766` | Pendiente o advertencia |
| Error | `#C8102E` | `#FF6B7F` | Error/acción de riesgo |

Las acciones `--brand` y `--accent` se eligieron para superar 4.5:1 frente a texto blanco en ambos temas. Esto **no significa** que todos los usos de color del sistema hayan superado una auditoría: revisar componentes renderizados, iconos y clases heredadas.

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


## 13. Iteración 8 — Historial del Libro Diario y T.C. veraz (06/11/2026)

**Evidencia:** el usuario registró un asiento ficticio en PostgreSQL 18 local
(`11-00001`, fecha 2026-11-06, estado `POSTED`). La captura del historial
mostró una fecha ISO cruda y una etiqueta de tipo muy estrecha; la cabecera
mostraba tasas codificadas que no coincidían con las registradas en el editor.

**Ajustes propuestos en esta rama (no integrados en main):**
- Mostrar la fecha del asiento como `DD/MM/AAAA` sin interpretar la medianoche
  UTC como una fecha local diferente; el valor de la API se conserva.
- Separar el tipo contable y el subdiario, traducir estados de presentación
  (Registrado/Revertido) y evitar saltos de línea en la fecha.
- Sustituir el indicador fijo de `App.vue` por una consulta GET del tipo de
  cambio diario de la **empresa activa**, con clave de caché por empresa y fecha
  contable peruana. Solo se muestran cifras si el API confirma registro exacto
  para ese día (`is_recorded_for_date`), moneda USD y base PEN.
- Mostrar el estado de consulta, falta de registro, error o base diferente;
  no identificar la tasa empresarial como una cotización SUNAT en vivo.
- Cancelar solicitudes anteriores con `AbortSignal` al cambiar el contexto
  y refrescar el indicador al guardar tasas en el maestro de cambios.
- Pruebas unitarias de fechas y selección estricta de tasa; regresiones
  estáticas de presentación, aislamiento y actualización.

**Referencias:** UI UX Pro Max, Hallmark e Impeccable guían esta iteración.
Frontend Slides queda para reportes y Scroll World para eventual landing,
según §1. No se añadieron dependencias, efectos 3D ni animaciones pesadas.

**No se modifica:** backend, esquemas, RLS, funciones de registro/extorno,
precisión monetaria, cuentas, idempotencia ni datos persistidos.

**Aprobación pendiente:** ejecutar `pnpm typecheck`, `pnpm test:unit`,
`pnpm build` y `pnpm check:bundle` en `frontend`; probar navegador
con el laboratorio PostgreSQL 18 (recarga del asiento `11-00001`, detalle
de partidas, cambio de empresa, fecha sin tasa, actualización de tasas,
claro/oscuro, ancho 375/768/1024/1440 px y teclado). Una inspección
estática de GitHub no sustituye esas comprobaciones.
