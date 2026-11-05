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
