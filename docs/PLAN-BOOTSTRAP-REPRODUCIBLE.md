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

