# UI compartida · Libro Mayor Perú

Componentes y estilos base para nuevas pantallas del ERP. La identidad está definida en [DESIGN.md](../../../../DESIGN.md) y los tokens en `src/assets/design-tokens.css`.

## UiButton

```vue
<script setup lang="ts">
import UiButton from '@/shared/ui/UiButton.vue';
</script>

<template>
  <UiButton variant="primary" type="submit">Registrar asiento</UiButton>
  <UiButton variant="secondary">Cancelar</UiButton>
  <UiButton variant="danger" :disabled="true">Eliminar</UiButton>
  <UiButton variant="ghost" size="icon" aria-label="Abrir opciones">
    <span aria-hidden="true">⋯</span>
  </UiButton>
</template>
```

Opciones: `variant = primary | accent | secondary | ghost | danger`; `size = md | sm | icon`; `type = button | submit | reset`, `disabled`, `loading` y `ariaLabel`.

- `type` por defecto es `button`: los formularios deben indicar `submit` explícitamente.
- Usar siempre `aria-label` si el botón solo tiene iconografía.
- `loading` bloquea clics y presenta un estado accesible.
- No se debe aplicar apariencia de botón a elementos no interactivos.
- Se conservan `.ui-btn-*` y las vistas existentes; la migración se hará gradualmente.
- Para selector tipado usar `UiSelect.vue`, sin degradar su navegación por teclado.

## Seguridad del dominio

Los componentes visuales no realizan peticiones, navegación ni cálculos financieros. Las vistas conservan sus permisos, validaciones, ciclos de vida, aislamiento de empresa y reglas de precisión numérica.
