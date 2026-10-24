# F3.3 · Libro Diario: latencia, autocomplete, totales y subdiarios

**Fecha:** 24/10/2026. **Estado:** migración de subdiarios **Ran + columna true**, Feature áreas **9/9 PASS (70 aserciones; 189,85 s)**, frontend **39/39 PASS** y compilación **PASS (1633 módulos; 2,55 s)** según resultados de PowerShell del usuario. Aceptación visual parcial; rendimiento real pendiente. Checklist rector **8/32 (25%)**, F3.3 abierta.

## Evidencia inicial y causas identificadas

Captura del usuario: siete peticiones distintas a Laravel `artisan serve` (Windows) se sirvieron con tiempos aproximados de 2–17 segundos. No se dispone de profiling SQL ni trazas de red independientes para atribuir de forma concluyente el tiempo a Neon. En el editor las solicitudes `accounts/operational`, `journal-entries`, `next-number`, `exchange-rates`, `cost-centers`, `areas`, `cash-flow-areas` se lanzaban simultáneamente, pero el servidor PHP de desarrollo puede serializar trabajo HTTP. La petición de historial transporta además líneas y relaciones que no son necesarias al abrir el editor.

La captura también mostró importes visibles `10` en Debe/Haber, mientras que los totales indicaban `0.00`: un `<input type="number" v-model>` de Vue puede producir un valor numérico JavaScript y la función `parseAmount` exige `string.trim()`, lo que terminaba en `SystemDecimal.zero()` al atrapar el error. Para contabilidad bimoneda, los importes deben permanecer como cadenas decimales, sin usar IEEE-754.

## Correcciones

1. **Autocompletado de cuentas**: nuevo `JournalAccountCombobox.vue` sin dependencia externa (semántica `role=combobox/listbox/option`, flechas, Enter, Escape, búsqueda por código o nombre sin tildes); filtra en memoria el catálogo ya cargado y monta **máximo 40 opciones**. Ya no se renderizan miles de `<option>`.
2. **Montos exactos**: entradas Debe/Haber usan `:value` + `@input` con `HTMLInputElement.value` y `inputmode=decimal`. Los totales reactivan los cálculos `SystemDecimal`; todos los importes del POST siguen siendo **strings**. Probar que 10 Debe y 10 Haber producen totales 10/10, no 0/0.
3. **Carga consolidada**: nueva ruta solo lectura `GET /api/v1/accounting/journal-entries/editor-bootstrap?date=YYYY-MM-DD` reutiliza controladores autorizados bajo el mismo contexto de empresa y devuelve cuentas, centros, áreas, flujos, próximo correlativo, cambios y catálogo de subdiarios. Las seis consultas ahora comparten una única solicitud HTTP. `Server-Timing` desglosa sus duraciones (`accounts`, `cost_centers`, `company_areas`, `cash_flow_areas`, `next_number`, `exchange_rates`). **No hay tiempos nuevos medidos todavía** y no se promete un resultado sin evidencia.
4. **Historial bajo demanda**: `journal-entries?limit=50` se consulta al entrar a la pestaña `Libro Diario`, no para dibujar `Nuevo Asiento`. Guardados confirmados mantienen su idempotencia y el mensaje de éxito.

## Próximo incremento propuesto: maestro de subdiarios por empresa

**Estado actual verificado:** todavía no existe una tabla independiente de subdiarios ni una interfaz para crearlos, renombrarlos, agregarlos o desactivarlos. Las ocho opciones se definen en el archivo PHP `JournalSubdiaryCatalog.php` y quedan almacenadas como `journal_entries.subdiary_code`. El CHECK de PostgreSQL y la validación `Rule::in` del POST rechazan códigos nuevos no declarados en esos catálogos.

**Diseño propuesto, no implementado ni migrado:** tabla `company_subdiaries` con aislamiento por `company_id`, ID, código único por empresa, nombre, estado y metadatos de auditoría; administración por usuarios autorizados (ADMIN / CHIEF_ACCOUNTANT), consulta por personal contable; los históricos deben seguir siendo consultables aunque el subdiario haya sido desactivado. Código estable tras su uso, edición controlada del nombre, desactivación sin eliminación física. Migrar los códigos existentes por empresa con respaldo y verificación. Para aceptar códigos configurables, sustituir el CHECK cerrado y la validación hardcodeada de PHP por validación tenant-safe contra el maestro, preservando referencias de asientos inmutables y extornos históricos. No cambiar la numeración por empresa/período por accidente.

**Evidencia manual recibida:** `check-journal-subdiary-schema.php --check`: Ran y columna true; `JournalAreaDimensionTest.php --debug`: 9/9 PASS, 70 aserciones; `pnpm test:unit`: 39/39 PASS; `pnpm build`: PASS, 1633 módulos en 2,55 s. La captura acredita la presencia del selector, no el CRUD ni una mejora de velocidad cuantificada.
## Subdiarios contables: nueva columna real, no alias del tipo de asiento

El esquema anterior solo tenía `entry_type` (STANDARD, OPENING, ADJUSTMENT, REVERSAL, CLOSING). El subdiario es una dimensión distinta. La migración aditiva **`2026_10_24_130000_add_journal_entry_subdiary.php`** crea `journal_entries.subdiary_code VARCHAR(24) NOT NULL DEFAULT 'GENERAL'` con CHECK y actualiza la función de inmutabilidad para proteger esta columna al marcar un asiento como REVERSED. Los asientos existentes conservan el valor `GENERAL` como clasificación transitoria predeterminada; no se recalculan importes ni correlativos.

El catálogo inicial interno al ERP (no catálogo ni código oficial SUNAT) incluye: **Diario general, Compras, Ventas, Caja, Bancos, Planillas, Ajustes, Apertura y cierre**. El POST valida los códigos contra el catálogo del servidor; la interfaz exige elección explícita y el campo se persiste en la cabecera. El extorno copia el subdiario del asiento original. Para mantener compatibilidad con clientes API anteriores, un POST sin código utiliza `GENERAL` mientras se migra el ecosistema. La pantalla permite seleccionar el subdiario sin confundirlo con el tipo de asiento; más adelante deberá implementarse el maestro de subdiarios configurable **por empresa**, si ese es el alcance requerido.

El correlativo **continúa siendo global por empresa y período fiscal**, no un contador por subdiario. Diseñar numeraciones independientes sería un proyecto adicional que necesita nueva política de unicidad y concurrencia; no modificar la lógica histórica silenciosamente.

## Incidencia de instalación y corrección — 24/10/2026

La primera ejecución de `2026_10_24_130000_add_journal_entry_subdiary.php` falló con `SQLSTATE[42601]` porque el cuerpo PL/pgSQL tenía apertura `AS $` y cierre `$$;` incompatibles. Se corrigieron los dos delimitadores a `$journal_immutable_guard$` y se conservó `SET search_path = pg_catalog`.

La primera prueba Unit del delimitador falló por `base_path()` sin aplicación Laravel inicializada. Se corrigió con `dirname(__DIR__, 2)` y el usuario confirmó **1/1 PASS, 5 aserciones**.

El usuario verificó posteriormente mediante `scripts/check-journal-subdiary-schema.php --check` que la migración figuraba **Pending** y que la columna `journal_entries.subdiary_code` **no existía** (`false`). Esto descartó una instalación parcial observada antes del reintento.

**Instalación manual confirmada:** segundo intento con `php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_130000_add_journal_entry_subdiary.php --force` → **DONE (908,49 ms)**, según salida de PowerShell aportada por el usuario. **Aún falta la comprobación posterior** `Ran` + `true`, la suite Feature, las pruebas frontend, la compilación y la aceptación visual. No se ha repetido la migración desde ChatGPT ni realizado SQL de escritura aquí.

**Regla de seguridad:** no ejecutar migraciones generales pendientes, `migrate:fresh`, `rollback` global, ni tocar el libro histórico. El diagnóstico de preinstalación es únicamente de lectura y la finalización `DONE` no sustituye las pruebas de extorno/inmutabilidad.

## Validación manual segura

En PowerShell, confirmar primero entorno Neon DEV y estado del esquema:

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php scripts/phase2-neon-connection-diagnostic.php --check
```

El diagnóstico valida configuración, **no autenticación SQL real**. Solo si apunta a la rama `contabilidad-dev` correcta, se dispone de respaldo y la ejecución está autorizada:

```powershell
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_130000_add_journal_entry_subdiary.php --force
php artisan migrate:status --database=pgsql_migrations
php artisan test tests/Feature/JournalAreaDimensionTest.php --debug
cd ..\frontend
pnpm test:unit
pnpm build
```

**No ejecutar `migrate` sin `--path`, `migrate:fresh` ni migraciones antiguas pendientes.** No efectuar POST sobre asientos reales, ni extornar el histórico ID 52.

**Aceptación visual:** abrir `Nuevo Asiento` y verificar: (i) una sola carga `editor-bootstrap` por empresa (además de peticiones globales ajenas a esta vista); (ii) tiempo total en Chrome Network y cabecera `Server-Timing` para separar latencia HTTP/SQL; (iii) buscador de cuentas por código/nombre y navegación de teclado; (iv) totales de Debe/Haber y cálculo USD; (v) selector de subdiario y distinción frente a `Tipo de Asiento`; (vi) filtros de dimensiones exigidas por cuenta y limpieza al cambiar; (vii) abrir `Libro Diario` carga el historial solo bajo demanda.

Las pruebas automatizadas incluyen **dos nuevas pruebas Node** en `journal-area.test.mjs` (se esperan **39** frontend en total) y **tres nuevas Feature** en `JournalAreaDimensionTest.php` (se esperan **9** en esa suite) para subdiario persistido/extorno, rechazo de código inválido y GET consolidado sin escritura. **No declarar PASS todavía para esta revisión.** La aceptación de navegador, compilación y latencia real siguen pendientes. La migración de subdiarios conserva el ajuste de seguridad `search_path=pg_catalog` del trigger de inmutabilidad existente. El audit log de creación y extorno recibe el código de subdiario.
