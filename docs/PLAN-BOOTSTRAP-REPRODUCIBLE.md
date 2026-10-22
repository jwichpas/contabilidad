# Plan de solución — instalación y migración reproducible del ERP contable

**Estado:** plan propuesto, implementación y carga **PENDIENTES**.  
**Destino inicial:** Neon, proyecto `soft-bonus-52215969`, rama `contabilidad-dev` (`br-odd-lake-b6wshqv3`), base `neondb`; solo `main` en GitHub.  
**Fuera de alcance:** Neon `production`, Supabase `Nexus Contable Dev` y Supabase `contabilidad-database` para cualquier escritura; **ambos Supabase son fuentes de lectura solamente**.

## 1. Causa raíz

El inicializador `backend/scripts/phase1-neon-schema-bootstrap.php` ejecutó exclusivamente una allowlist de **16 migraciones DDL portables**. La migración independiente de la política RLS elevó el recuento a **17**. El inicializador nunca ejecutó los seeders de catálogos, matrices PCGE, NIC/NIIF ni ajustes iniciales.

Por tanto, migró **esquema** pero **NO configuración funcional**. Varias tablas del Supabase histórico `Nexus Contable Dev` ni siquiera existen en el modelo de destino (p. ej., `sunat_ple_reference_rows`, `sunat_book_catalog_codes`, `accounting_plan_reference_accounts`). No se puede inferir que dos esquemas con nombres diferentes tengan semántica y restricciones idénticas.

El `DatabaseSeeder` existente NO debe ejecutarse indiscriminadamente: crea otra empresa y usuario de ejemplo con contraseña fija, además de inicializar un plan en un tenant arbitrario. `MasterChartOfAccountsSeeder` hace `TRUNCATE` del catálogo maestro y solo salta la carga cuando `COUNT >= 3400`, sin integridad, hashes ni control de versión. Esto no puede ser la ruta de instalación profesional.

## 2. Contrato de instalación

**INSTALADO = SCHEMA_OK && REFERENCE_DATA_OK && ACCOUNTING_PLAN_OK && COMPANY_SETTINGS_OK && SECURITY_OK && INTEGRATION_SMOKE_OK**.

Separa estrictamente:
- **Migraciones de estructura:** tablas, constraints, índices, RLS, funciones, grants (versionadas en Git).
- **Migraciones de datos globales/referencias:** códigos SUNAT, matriz PCGE, NIC/NIIF, catálogos de libros, monedas (datos reproducibles incluidos/versionados en Git o artefactos inmutables con checksum y procedencia). Pueden ser seeders idempotentes controlados por un manifiesto y versionados con cada cambio. Forman parte del *mismo proceso obligatorio de instalación*, aunque no sean operaciones `Schema::create()`.
- **Configuración por empresa:** áreas, centro de costos, lista de monedas, plan de cuentas de trabajo, reglas de dimensiones, tributación, periodos. Se inicializa por cada tenant y se verifica por tenant sin copiar IDs extranjeros.
- **Datos de desarrollo:** tres empresas y tres usuarios ficticios ya creados en Neon `contabilidad-dev`. Su conservación es obligatoria; no recrearlos ni eliminarlos. Nunca incluir identidades/contraseñas reales en archivos de esquema, seeds globales, fixtures Git o logs.

**No usar `php artisan migrate:fresh`, `db:seed` general, `TRUNCATE`, ni restaurar un dump completo de Supabase sobre Neon.**

## 3. Línea base constatada antes de implementar

Fuentes:
- Supabase `contabilidad-database`: `accounting_account_templates = 3401` (PCGE_2019: 1764; PCGE_2026: 1637), `accounting_accounts = 3528` asociados a tenant, `sunat_table_01_payment_methods = 20`, `sunat_table_02_identity_document_types = 5`, `sunat_table_10_electronic_document_types = 24`, `sunat_table_12_operation_types = 17`, `cash_flow_areas = 9`, `company_areas = 6`, `cost_centers = 0`.
- Supabase `Nexus Contable Dev`: `accounting_plan_reference_accounts = 3522`, `chart_of_accounts = 503`, `sunat_ple_reference_rows = 247`, `sunat_book_catalog_codes = 23`, `sunat_catalog_items = 47`, `cost_centers = 2`, `organizational_areas = 2`.
- Neon `contabilidad-dev`: 17 migraciones, 3 empresas y 3 usuarios ficticios; **0** en `accounting_account_templates`, `accounting_accounts`, catálogos SUNAT consultados, normas NIC/NIIF, monedas, centros de costo y áreas contables antes de este plan.

**Conflictos:** no seleccionar por defecto datos del Supabase de nombre parecido, ni unir automáticamente PCGE y catálogos que tienen modelos diferentes. Para cada entidad identificar fuente canónica, versión, columnas compatibles, claves naturales, checksums, diferencia de cobertura, y registro de aprobaciones.

## 4. Etapas de ejecución

### Etapa A — Inventario y comparación READ ONLY (puerta A)

1. Extraer SOLO catálogos y metadatos necesarios de ambos Supabase. Comparar tablas, recuentos, claves naturales, campos y versiones por columna.
2. Preparar `database/reference_data/manifest.json` con: nombre, fuente, versión, artefacto, SHA-256, claves de upsert, cantidad esperada y dependencias. Sin claves, passwords, tokens ni datos personales.
3. Clasificar:
   - global/regulatorio inmutable y versionable;
   - parametrización y plantilla de empresa;
   - dato transaccional que NO se migra;
   - estructura histórica que necesita adaptación DDL a PostgreSQL 18.
4. Comparar PCGE de Supabase 2019/2026 y `Nexus Contable Dev`; localizar divergencias de jerarquía y códigos de movimiento de 6 dígitos.

**Criterio:** informe de diferencias con fuente canónica por catálogo y ninguna fila perdida sin justificación.

### Etapa B — Completar estructura portable (puerta B)

1. Crear migraciones PostgreSQL 18 nuevas, nunca editar migraciones antiguas ya aplicadas.
2. Agregar tablas portables para los catálogos PLE/libros que carecen de equivalente; donde sí hay destino `sunat_table_*`, mapear mediante transformadores deterministas.
3. Índices y constraints de unicidad y validación por código/versión; RLS y permisos mínimos según tipo de catálogo.
4. Separar las partes comunes PostgreSQL de los parches específicos Supabase/Neon.

**Criterio:** schema diff sin dependencias de `auth.*`, `storage.*` ni roles Supabase en rutas portables; ningún cambio fuera de la rama dev.

### Etapa C — Datos globales versionados (puerta C)

1. Versionar snapshots de catálogos SUNAT PLE/SIRE, NIC/NIIF, rubros, monedas y plantillas PCGE verificadas; normalizar tipos/códigos y vincular fuente y vigencia.
2. Reemplazar `COUNT >= 3400` y `TRUNCATE` por importación **idempotente con upsert de claves estables**, manifiesto SHA-256, transacciones y comprobación estricta de conjuntos de códigos. Nunca borrar/reescribir silenciosamente configuraciones.
3. No interpretar todos los códigos oficiales PCGE de longitud menor a 6 como defectuosos. Conservar el código oficial de referencia y generar/enlazar cuentas de movimiento empresariales de 6 dígitos bajo reglas explícitas; cuentas no hoja o sin nivel operativo no admiten movimiento. Resolver las excepciones y evitar crear subcuentas fiscales inventadas sin soporte.
4. Dejar un ledger `reference_data_versions` o equivalente de qué versión/sha se instaló, con marca de fecha y estado.

**Criterio:** catálogos completos con recuento + equivalencia de claves/contenido (no solo COUNT), sin duplicados, checksums y versiones visibles.

### Etapa D — Inicialización de empresas existentes (puerta D)

1. Conservar las 3 empresas y 3 usuarios del entorno de desarrollo y sus 8 membresías.
2. Elegir versión PCGE para cada una y clonar la matriz por `company_id`, reconstruyendo `parent_id` y aplicando 6 dígitos solo para cuentas de movimiento según reglas acordadas.
3. Poblar `company_settings`, `company_currencies`, áreas, centros de costo y dimensiones con valores útiles; bloquear la carga si faltan cuentas de destino para CC/automáticos.
4. Asegurar independencia empresa A/B/C y perfiles ADMIN/ACCOUNTANT/AUDITOR sin heredar permisos cruzados.

**Criterio:** cada tenant con jerarquía íntegra, cuenta de movimiento válida, ajustes completos, accesos RLS/HTTP correctos y cero modificaciones de contraseñas de usuarios existentes.

### Etapa E — Instalador único y verificable (puerta E)

Construir un comando orquestador, por ejemplo:
`php artisan nexus:install --plan`,
`php artisan nexus:install --verify`,
`php artisan nexus:install --apply=contabilidad-dev`.

Orden fijo: `schema → datos globales → matrices contables → configuración tenant → permisos → pruebas`. Por defecto es read-only. Guardas de host/proyecto/rama/base, versiones PG, identidad SQL, hash manifiesto y ausencia de config cacheada. Fallar cerrado ante estado parcial. No ejecutar seeds públicos, DDL, credenciales u operaciones `--force` generales por omisión.

**Criterio:** una instalación nueva no puede reportar PASS mientras falten catálogos, monedas, plan de cuentas, configuración requerida o policies.

### Etapa F — Ensayo real de reinstalación (puerta final)

1. Restauración desde cero en PostgreSQL 18 desechable (Docker opcional; o una rama temporal **solo con autorización explícita**, nunca crear ramas adicional por defecto).
2. Ensayo repetido de instalación: misma versión => sin diferencias; upgrade => solo diferencias auditadas.
3. Consultas de integridad (FK, recuentos, hashes, six-digit leaf/postable, líneas RLS), CRUD y operaciones reales: login multiempresa, POST, reversión, cierre mensual, correlativos y pruebas SUNAT/libros.
4. Emitir reporte `SCHEMA_OK/REFERENCE_DATA_OK/PLAN_OK/COMPANY_SETTINGS_OK/SECURITY_OK/SMOKE_OK` con anomalías precisas.

## 5. Orden inmediato para este entorno

1. **A**: leer ambos Supabase y elaborar manifiesto de concordancia sin mutar ninguna base.
2. **B**: migraciones faltantes de SUNAT PLE / referencias compatibles en Neon.
3. **C**: carga global aprobada, idempotente, compatible PG18, sin `TRUNCATE`.
4. **D**: completar las 3 empresas existentes y los tres usuarios (sin duplicación).
5. **E/F**: instalador obligatorio y ensayo reproducible.

No ejecutar F1.14 `--probe` contra Neon con fixturas que exijan `companies=0`: ya existen 3 empresas persistentes por decisión de desarrollo. Adaptar pruebas a fixtures identificables o a una base desechable **sin borrar las empresas existentes**. La falta de Docker requiere una alternativa aislada, pero no justifica sobreescribir o truncar datos.

## 6. Decisiones que NO deben quedar implícitas

- La referencia SUNAT para **libros electrónicos** es distinta de los catálogos de **facturación electrónica**; versionarlas y validarlas por separado.
- Mantener dos versiones PCGE oficiales (2019/2026), y no convertir a ciegas todos los 1,764/1,637 códigos históricos en cuentas de movimiento de seis dígitos.
- Migración de catálogos de fuente a destino = transformación versionada con pruebas, no dump directo ni comandos manuales irreplicables.
- Los archivos de inicialización de DEV (empresas/usuarios) NO se ejecutarán jamás como seeder global de producción.
- Las 17 migraciones existentes representan el estado actual y NO se vuelven a ejecutar ni se modifican retroactivamente.



## 7. Primer componente implementado: referencia global inmutable (21/10/2026)

**Estado: código publicado en `main`, aún SIN APLICAR a Neon.** Este primer componente no reemplaza todavía al instalador portátil integral `nexus:install`. Es una carga de referencia estrictamente restringida a la rama Neon de desarrollo, para verificar fuentes antes de extenderla a nuevas bases de datos.

### Archivos añadidos y corregidos

- `backend/database/reference_data/manifest.json`: versión `nexus-global-reference-2026-10-21-v1`. Define seis fuentes Git exactas con su **Git blob SHA-1** (checksum de contenido del objeto Git), recuentos contrastados con Supabase `contabilidad-database`, 13 tablas objetivo y versiones de PCGE 2019/2026. Cada archivo se verifica contra el hash Git y además se calcula un SHA-256 para la auditoría de instalación. Cambiar una fuente sin publicar una nueva versión provoca **FAIL antes de conectar**.
- `backend/database/portable_migrations/2026_10_21_040000_create_reference_data_versions.php`: migración portable PostgreSQL que crea un **ledger de versión** con SHA-256 del manifiesto, hashes SHA-256 de las seis fuentes y **huella de contenido SHA-256 por cada tabla**. Deniega permisos sobre el ledger a `app_backend` y `PUBLIC`.
- `backend/database/seeders/MasterChartOfAccountsSeeder.php` corregido: sin `TRUNCATE`, sin regla `COUNT >= 3400`; ya no modifica automáticamente el plan de la primera empresa. Para matriz vacía carga los 3.401 registros; para existente exige coincidencia de **cada código y de todos sus campos** (excepto timestamps) antes de aceptar la versión. Nunca sobrescribe diferencias silenciosamente.
- `backend/scripts/phase2-neon-global-reference.php`: instalador protegido para `contabilidad-dev`, con `--plan` SIN conectar Laravel/SQL, `--verify` solo lectura, y `--apply=contabilidad-dev` explícito. Aplica únicamente la migración de ledger si aún faltaba (**17→18 migraciones**), luego dentro de una transacción global ejecuta `CurrenciesSeeder`, `SunatCatalogSeeder`, `AccountingStandardsAndRubricsSeeder` y `MasterChartOfAccountsSeeder::seedTemplates()`. Exige **3 empresas, 3 usuarios y 8 membresías** como control contra destinos inesperados. Al verificar, compara 13 recuentos y SHA-256 de contenido, no solo cantidades; nunca altera tablas multiempresa. Si la migración del ledger se aplicó pero la transacción de datos se revirtió, se permite recuperar únicamente desde un estado **18 migraciones, ledger sin versión y 13 catálogos completamente vacíos**. No ejecutar `db:seed` general.
- CI `.github/workflows/phase1-security-static.yml`: PHP lint del instalador y la migración, además de ejecución de `--plan` en GitHub Actions para validar hashes Git sin bases de datos.

### Cantidades obligatorias de Fase Global 1

| Área | Recuento esperado |
|---|---:|
| Monedas del seeder existente (PEN/USD/EUR) | 3 |
| Normas NIC/NIIF | 43 |
| Rubros financieros | 48 |
| SUNAT Tabla 01 medios de pago | 20 |
| SUNAT Tabla 02 identidades | 5 |
| SUNAT Tabla 03 bancos | 33 |
| SUNAT Tabla 05 existencias | 6 |
| SUNAT Tabla 06 unidades | 16 |
| SUNAT Tabla 08 libros | 20 |
| SUNAT Tabla 10 documentos | 24 |
| SUNAT Tabla 11 aduanas | 22 |
| SUNAT Tabla 12 operaciones | 17 |
| PCGE matriz 2019 + 2026 | 3.401 |

Las 9 tablas SUNAT suman **163 códigos**, y PCGE se desglosa en **1.764 PCGE 2019 + 1.637 PCGE 2026**.

### Comandos secuenciales (NO aplicados todavía)

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-global-reference.php
php -l database/portable_migrations/2026_10_21_040000_create_reference_data_versions.php
php -l database/seeders/MasterChartOfAccountsSeeder.php
php scripts/phase2-neon-global-reference.php --plan
php scripts/phase2-neon-global-reference.php --verify
# Solo cuando todo lo anterior es correcto y se desea materializar los catálogos:
php scripts/phase2-neon-global-reference.php --apply=contabilidad-dev
php scripts/phase2-neon-global-reference.php --verify
```

**ADVERTENCIA DE COBERTURA:** esta primera fase NO incluye los 247 registros `sunat_ple_reference_rows`, 23 `sunat_book_catalog_codes` ni 3.522 `accounting_plan_reference_accounts` del otro proyecto `Nexus Contable Dev`; esas tablas tienen otro modelo y requieren transformaciones explícitas. Tampoco incluye el calendario SUNAT 2026 sin validación normativa independiente, la moneda CLP aún no presente en el seeder de 3 monedas, ni la configuración por empresa o sus cuentas a seis dígitos. Son las siguientes etapas, y hasta terminarlas `GLOBAL_REFERENCE_OK` **no significa que la instalación total del ERP sea completa**.

El manifestado permite reconstruir en nuevas bases PostgreSQL, pero el instalador inicial permanece explícitamente limitado a Neon `contabilidad-dev` mientras se verifican las rutas portables y se implementa el orquestador definitivo. **No mutar Neon production ni Supabase**.



### Protección adicional: seeder genérico deshabilitado

Se reemplazó `backend/database/seeders/DatabaseSeeder.php` por un seeder que **falla cerrado** con una explicación. La versión anterior creaba una cuarta empresa de demostración y un usuario con contraseña fija; sería un riesgo para cualquier migración posterior. La carga global versionada se realiza solamente por el instalador guardado, no por `php artisan db:seed`. Las tres empresas y los usuarios existentes no se modifican por este cambio de código.

## 8. Reconciliación histórica PLE: NO publicar como normativa vigente

Consulta READ ONLY al proyecto Supabase `Nexus Contable Dev` confirma que `sunat_ple_reference_rows` contiene **247 filas** de las tablas 1–12 del archivo histórico `234_tablas (1).xls`, y que **ninguna** tiene `code_review_status = APPROVED` ni `validity_review_status = VERIFIED`. Distribución: tabla 01=20, 02=5, 03=33, 04=3, 05=6, 06=16, 07=3, 08=31, 09=35, 10=48, 11=30 y 12=17. La tabla `sunat_book_catalog_codes` tiene **23 filas**, todas marcadas `DRAFT`.

Por lo tanto estos registros **NO se pueden etiquetar como catálogos oficiales actualmente vigentes**, aunque sí se deben conservar sin pérdida como referencia histórica y con estado de revisión. La operación correcta es un snapshot versionado de fuente, una estructura portable **separada** para referencia histórica y un proceso de aprobación posterior usando normativa SUNAT auténtica. No sobrescribir `sunat_table_*` operativas ni fusionar listas con semánticas diferentes.

El otro catálogo `accounting_plan_reference_accounts` de Nexus Contable Dev tiene 3.522 cuentas distribuidas en dos versiones (1.764 y 1.758). En una versión están las 1.764 cuentas con `posting_allowed=false`; en la otra, 1.202 cuentas están marcadas para movimiento. El identificador de versión es UUID específico de la fuente y exige mapearlo a una clave semántica antes de trasladarlo. Ninguna fila debe adquirir carácter operativo por una equivalencia de IDs supuesta.

**Puerta obligatoria:** preservar campos `source_file`, `code_review_status`, `validity_review_status`, `review_status`, las fechas de vigencia y versiones de referencia, y no habilitar como utilizable ninguna fila no aprobada.

## 9. Históricos fuente ya archivados en Git (sin activación normativa)

Se generaron snapshots desde consultas **SELECT** de Supabase `Nexus Contable Dev`, sin exportar usuarios ni operaciones empresariales:

- `backend/database/reference_data/historical/ple_234_tablas_2026-10-21.json` — **247 filas PLE**, preserva `table_number`, `source_row_number`, código/descripción originales, `source_file`, `source_metadata` y los estados `EXTRACTED` / `HISTORICAL_NOT_VERIFIED`.
- `backend/database/reference_data/historical/sunat_book_catalog_codes_draft_2026-10-21.json` — **23 códigos DRAFT**, conserva `catalog_version_id`, código, descripción, vigencia y metadatos. **No implica aprobación SUNAT**.

Ambos archivos están referenciados con hash Git blob en `backend/database/reference_data/manifest.json` bajo `historical_sources`, con `loaded_operationally=false`. El instalador `phase2-neon-global-reference.php --plan` valida sus SHA y contenidos, incluyendo número de filas, claves naturales y que no hayan sido aprobados sin revisión. **No los instala en tablas operativas de SUNAT**.

La etapa de integración histórica posterior deberá definir una migración PostgreSQL portable para tablas de `fuentes históricas/no verificadas`, conservar su trazabilidad y mantenerlas segregadas de los catálogos operativos hasta contar con evidencia normativa y revisión explícita. Esta operación **no forma parte de Fase Global 1**.
