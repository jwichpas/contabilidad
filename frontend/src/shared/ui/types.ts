/**
 * Opción tipada para `UiSelect`. `V` es una unión literal de strings (ej. un enum de dominio),
 * por lo que es imposible pasar un valor que no pertenezca al dominio.
 */
export interface UiSelectOption<V extends string> {
  readonly value: V;
  readonly label: string;
  /** Texto secundario bajo la etiqueta (ej. cuenta PCGE típica). */
  readonly hint?: string;
  /** Código corto monoespaciado a la izquierda (ej. "USD", "ESF"). */
  readonly badge?: string;
}
