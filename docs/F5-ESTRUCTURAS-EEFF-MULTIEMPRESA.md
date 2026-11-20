# F5 — Estructuras de estados financieros independientes por empresa

## Alcance técnico

La estructura estándar se conserva globalmente; cada empresa puede
**renombrar renglones de detalle, ordenar detalles dentro de una misma
categoría y ocultar detalles sin movimientos**. Nunca puede reescribir
fórmulas, cifras, subtotales, otros resultados integrales pendientes,
líneas sin clasificación ni controles de conciliación.

| Estado | Presentación actual | Personalización |
|---|---|---|
| ESF | Dos columnas, corriente/no corriente, patrimonio | Sí, por rubro de detalle dentro de su grupo |
| ERI | Por función, subtotales y OCI pendiente | Sí, por detalle del mismo bloque |
| EFE | Anual y 12 meses, operación/inversión/financiación | Sí, por concepto dentro de la actividad y sentido |
| ECP | Estado de Cambios en el Patrimonio preliminar implementado | Sí, detalles de componentes patrimoniales dentro de su grupo; resultados y totales protegidos |

El EFE **conserva también su configuración preexistente de reglas por
cuenta/área**. Esas reglas económicas no se modifican en el editor de
estructura. El editor visual EFE es adicional.

### Fuente y seguridad

- Nueva tabla `company_financial_report_layout_revisions` con clave
  única `(company_id, statement, revision)`.
- `FORCE ROW LEVEL SECURITY`: actor `app_backend` solo accede a su
  empresa mediante `public.app_current_company_id()`.
- `app_backend` solo tiene permisos de **SELECT e INSERT**, sin UPDATE
  ni DELETE de revisiones anteriores.
- Solo `ADMIN` y `CHIEF_ACCOUNTANT` pueden guardar. Los miembros
  autorizados de la empresa pueden leer la presentación aplicada.
- Cada PUT exige `expected_revision`; en concurrencia devuelve **409**.
- Cada actualización deja constancia en `audit_logs`, más historial
  inmutable visible de las últimas 20 revisiones.
- No se aceptan `company_id`, rubros inventados, códigos de subtotal
  ni cambios de categoría. Los códigos disponibles proceden de un
  catálogo del backend, y la UI solo permite subir/bajar dentro del
  bloque permitido.
- Las etiquetas se escapan en Vue y en HTML imprimible. Cualquier
  importe distinto de cero permanece visible aunque se haya solicitado
  ocultar ese renglón.
- Los informes históricos se **presentan** con la revisión vigente;
  los asientos nunca se reescriben. La selección de una versión histórica
  para reimpresión todavía no existe.

### Endpoints de la empresa activa

```http
GET /api/v1/company/financial-layout/ESF
PUT /api/v1/company/financial-layout/ESF
GET /api/v1/company/financial-layout/ERI
PUT /api/v1/company/financial-layout/ERI
GET /api/v1/company/financial-layout/EFE
PUT /api/v1/company/financial-layout/EFE
```

`GET /api/v1/company/financial-layout/ECP` está disponible para
la personalización de rubros patrimoniales. La naturaleza legal de cada
movimiento y otros resultados integrales sigue pendiente de sustento.

Ejemplo de payload PUT (empresa deducida del contexto autenticado):

```json
{
  "expected_revision": 0,
  "settings": {
    "labels": { "sales": "Ventas comerciales de la empresa" },
    "hidden_codes": ["discounts"],
    "line_order": ["discounts", "sales"],
    "hide_zero": false
  }
}
```

No modificar códigos de subtotales ni enviar órdenes de distintos
grupos buscando cambiar el significado contable: el servidor los
valida y la UI no permite moverlos entre actividades.

## Instalación protegida solo Docker PG18 (laboratorio)

Se requiere **la migración previa del EFE ya verificada**, dejando
**30 migraciones** en el Docker local. El script creado para esto
usa los mismos controles de etiqueta, volumen, puerto, RLS y
catálogo F2 que los anteriores, respalda antes de migrar y solo
ejecuta esta única migración nueva.

```powershell
cd C:\Proyectos\contabilidad
git switch test/ciclo-contable-eeff-niif-2026
git pull --ff-only

cd backend
.\scripts\configurar-estructuras-eeff-pg18-local.ps1 -Accion Plan
```

**Solo si Plan pasa sin BLOQUEO**, aplicar una vez:

```powershell
.\scripts\configurar-estructuras-eeff-pg18-local.ps1 -Accion Aplicar
.\scripts\configurar-estructuras-eeff-pg18-local.ps1 -Accion Verificar
```

Concluye con **31 migraciones**. No ejecutar `migrate` general,
`migrate:fresh`, recargar vouchers, borrar volumen ni tocar Neon.

Luego:

```powershell
cd ..\frontend
pnpm test:unit
pnpm build
```

Reiniciar frontend y backend. Abrir
**Estados financieros → ESF o ERI o ECP → Personalizar estructura de esta
empresa**, o **Flujo de Efectivo → Anual detallado → Personalizar
estructura del EFE**.

## Criterios de aceptación

1. Empresa A renombra una línea del ERI y guarda revisión 1.
2. Empresa B mantiene la etiqueta global y revisión 0.
3. Empresa A modifica ESF sin modificar su ERI ni el EFE.
4. Los PDF ESF/ERI y CSV/PDF EFE presentan la misma estructura
   vigente que la pantalla.
5. Un subtotal/código obligatorio no puede ocultarse ni renombrarse.
6. «Ocultar sin movimiento» no oculta movimientos con valor distinto
   de cero ni modifica ninguna suma.
7. Los 14 asientos Docker y la fórmula del balance siguen intactos:
   activo S/ 94,340.00, pasivo S/ 30,490.70, patrimonio incluido
   resultado S/ 63,849.30, utilidad neta S/ 3,849.30, efectivo final
   S/ 62,560.00.

La migración **no ha sido aplicada** por ChatGPT al Docker local. Las
pruebas de integración PostgreSQL y visuales deben hacerse en el
laboratorio del usuario, no en producción.
