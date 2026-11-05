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
