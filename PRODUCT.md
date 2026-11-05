# PRODUCT.md — Libro Mayor Perú (contexto para agentes de diseño)

> Documento de producto para UI UX Pro Max, Hallmark e Impeccable. Esta fase visual es independiente de las fases contables F0–F8 del plan maestro.

## Producto
Libro Mayor Perú es un ERP contable/financiero bimoneda para organizaciones peruanas. Frontend Vue 3 + TypeScript + Tailwind CSS 4 y backend Laravel/PostgreSQL.

## Usuarios y tareas principales
- Contadores y auxiliares: registrar, revisar y consultar asientos con alta densidad de información.
- Responsables de contabilidad: plan contable, subdiarios, centros de costo, terceros, tasas y cierre de períodos.
- Administración: consultar estados, reportes y entidades multiempresa.

## Promesa de experiencia
Precisión, legibilidad, velocidad y confianza. La pantalla debe funcionar por teclado y sin efectos distractores. Diseño sobrio, corporativo, original y consistente.

## Reglas innegociables
1. Cambiar de empresa no debe mostrar ni conservar borradores o datos de otra entidad.
2. Las dimensiones por partida (tercero, centro de costo y áreas) se muestran/validan según la configuración de la cuenta; no son opcionales por defecto.
3. El campo importe respeta precisión decimal de dominio, moneda y formato contable; nunca introducir conversiones visuales que cambien valores.
4. Los estados contables se expresan también con texto y/o iconos, nunca solo con color.
5. El sistema no debe afirmar certificaciones o estados de seguridad sin respaldo verificable.
6. No alterar contratos API, permisos, flujos de POST/extorno, persistencia o lógica de cuentas al modificar identidad visual.
7. Mantener filtros y tablas operables en móvil, teclado, zoom y preferencias de movimiento reducido.
8. No introducir renderizado 3D, videos o bibliotecas pesadas en pantallas transaccionales.

## Primer alcance de diseño
- Centralizar tokens de color, espacio y geometría con tema claro/oscuro.
- Conservar las clases y componentes existentes mientras se migran progresivamente.
- Iniciar catálogo de componentes compartidos; primero botón, después campos, alertas, paneles y tablas.
- Documentar reglas de aceptación y auditar contraste, foco, estados, responsive y rendimiento.

## Fuera de alcance de este cambio
Migraciones de BD, normas contables, autorización, nuevos módulos, rediseño funcional del editor, nuevas dependencias, página pública 3D y presentaciones interactivas.

## Fuentes del producto
README.md, README-PLAN-MAESTRO.md, frontend/src/App.vue y el editor actual de asientos.
