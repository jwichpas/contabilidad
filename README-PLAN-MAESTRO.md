# Plan maestro, hoja de ruta e hitos — Sistema Contable

> **Documento rector del proyecto** | Repositorio: `jwichpas/contabilidad` | Rama: `main` | Versión: **1.0** | Fecha de registro: **2026-10-19**  
> **Estado global: EN EJECUCIÓN — FASE 0 EN PROGRESO (no aprobada).**  
> **Validación F0 2026-10-20 — RLS:** el usuario ejecutó `phase0-audit-tenant-read-only.php` con resultado **PASS**: rol `app_backend`, sin empresa 0 cuentas/0 asientos; empresa inexistente 0/0; empresa activa 1.764 cuentas/2 asientos; transacción solo lectura sin cambios persistentes. **Pendiente** verificar autorización HTTP, especialmente `GET /api/v1/tenant/companies`: código fuera de `SetTenantContext` podría recibir lista vacía por política RLS de `company_user`. Se agregó prueba **solo GET** `backend/tests/Feature/Security/ReadOnlyTenantApiSmokeTest.php`, aún no ejecutada; requiere resultados reales y no presupone PASS. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Resultado actualizado F0 — 2026-10-20:** **17 migraciones aplicadas** y rotación de `app_backend` confirmada en Supabase (batch 8). Conexión Laravel `pgsql` contra pooler 6543 operativa: el usuario ejecutó `php artisan test tests/Feature/Security/PublicDataApiPrivilegesTest.php` con resultado **PASS (1 prueba / 11 aserciones)**. Se verificó identidad y privilegios efectivos sin modificación de datos; autenticación operativa **resuelta**. Se preparó auditoría adicional **solo lectura** `php scripts/phase0-audit-tenant-read-only.php`, aún sin ejecutar, que comprueba RLS para cuentas y asientos sin empresa, con empresa inexistente y con empresa real; no sustituye pruebas completas de aislamiento entre dos empresas. **F0 no aprobada**: pendiente aislamiento funcional completo, recuperación/respaldo, revisión de 18 tablas sin RLS, y demás pruebas. Tras la verificación retirar del `.env` `DB_APP_BACKEND_NEW_PASSWORD`; mantener `DB_PASSWORD` y sincronizar despliegues. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Registro histórico (antes de la rotación y prueba superada) — Incidencia de autenticación 2026-10-20:** tras corregir `DB_URL`, PHPUnit ya intenta usar `app_backend` en el puerto 6543 pero la contraseña es rechazada, sin ejecutar aserciones. Catálogo Supabase confirma rol existente, `LOGIN`, no vencido y `NOBYPASSRLS`. Se preparó **una tercera migración Laravel en `main`, todavía PENDIENTE**, `2026_10_20_000000_rotate_app_backend_credential.php`, para rotar la clave sin exponerla en GitHub. Estado previo confirmado: **16 aplicadas**; repositorio actualizado: **17 archivos, 1 nueva pendiente**. La rotación requiere `DB_APP_BACKEND_NEW_PASSWORD` local y actualización coordinada de `DB_PASSWORD`; pruebas seguras y F0 aún abiertas. [Diagnóstico](docs/FASE-0-DIAGNOSTICO.md).  

> **Validación 2026-10-20, tras segunda migración:** el usuario ejecutó por Artisan la migración `2026_10_19_010000_add_company_economic_profile`; Supabase confirma **16 migraciones, batch 7 y 32 tablas**. La nueva `company_economic_activities` existe con RLS ENABLE/FORCE, una política para `app_backend`, FKs, restricciones de sector/CIIU, CIIU único por empresa y máximo una actividad principal por empresa. `company_settings` contiene los cinco nuevos campos. `anon`/`authenticated`: **0 tablas, secuencias o funciones** con permisos efectivos; `app_backend`: SELECT en 31 tablas, USAGE en 20 secuencias y EXECUTE en 5 funciones. Datos preservados: **1 empresa, 1 configuración, 2 asientos y 4 partidas**. Consulta F0 del ledger: **0 asientos inválidos, 0 partidas huérfanas, 0 referencias inválidas**. Existen **18 tablas sin RLS**; revocar privilegios públicos no equivale a habilitarlo. **No se ha probado la creación de empresas vía Laravel ni completado la suite de tests; no se realizó respaldo de desarrollo por decisión expresa del usuario.** La Fase 0 permanece abierta.  

> **Validación de seguridad 2026-10-20 (más reciente):** el usuario ejecutó con Artisan la migración `2026_10_19_000000_restrict_supabase_data_api_grants` y Supabase confirma **15 migraciones aplicadas** (seguridad, batch 6). `anon` y `authenticated`: **0 tablas, 0 secuencias y 0 funciones** con permisos efectivos; `app_backend` conserva SELECT sobre 30 tablas, USAGE sobre 19 secuencias y EXECUTE sobre 5 funciones. Se preservan **2 asientos y 4 partidas**. La migración F3 `2026_10_19_010000_add_company_economic_profile` sigue pendiente. Sin prueba HTTP/funcional de Laravel aún y sin respaldo realizado (riesgo aceptado por el usuario en desarrollo). Los registros históricos siguientes reflejan fechas previas a esta validación.  

> Esta hoja de ruta establece trabajo futuro. Las casillas en blanco **no** significan que el código existente carezca de esa funcionalidad; indican que la actividad de verificación/implementación del plan todavía no se ha aprobado.

[Volver al README principal](README.md) · [Diagnóstico Fase 0](docs/FASE-0-DIAGNOSTICO.md) · [Seguimiento detallado](#10-checklist-de-seguimiento) · [Hitos](#8-hitos-y-puertas-de-calidad)

---

## 1. Propósito, alcance y principios

**Objetivo:** evolucionar el sistema actual hacia un ERP contable peruano multiempresa, seguro, bimonetario, confiable para la operación diaria, conforme a los requerimientos aplicables de SUNAT/PCGE/NIIF y con un frontend profesional.

**Proyecto de destino, exclusivamente:**
- GitHub: [jwichpas/contabilidad](https://github.com/jwichpas/contabilidad), rama `main`.
- Supabase: **contabilidad-database**, referencia `bnjwxphwtkhblvhsnylj`.
- Arquitectura existente: **Laravel 11 + PHP / Laravel Sanctum**, **Vue 3 + TypeScript + Tailwind CSS 4**, **PostgreSQL (Supabase)**.
- **No confundir ni mezclar** este proyecto con `Nexus-contable` / `Nexus Contable Dev`.

**Principios obligatorios:**
1. Preservar el proyecto existente y mejorar incrementalmente; no reescribir Laravel/Vue sin una justificación documentada.
2. Usar `main` como rama de trabajo solicitada; no crear ramas por defecto. Los cambios deben ser pequeños, probados y trazables.
3. **Laravel es el único gestor del esquema:** toda creación o modificación de tablas, columnas, índices, constraints, triggers, vistas, RLS y funciones SQL debe vivir en una migración nueva de `backend/database/migrations/`, ejecutada con Artisan y con plan de respaldo/reversión. **No hacer DDL de cambios funcionales directamente en Supabase.** Consultar [política de BD](docs/ARQUITECTURA-BD-LARAVEL.md).
4. Aislamiento estricto por empresa en consultas, relaciones y accesos. Nada de secretos o tokens privilegiados en el frontend.
5. Libro Diario como fuente de verdad; toda rectificación contable mediante flujo autorizado y trazable, no por edición destructiva.
6. Precisión monetaria decimal exacta de extremo a extremo; nada de `float`/`double` para importes o tipos de cambio.
7. Cumplimiento normativo **versionado por fecha de vigencia y tipo de contribuyente**. No asumir que una norma citada en el README está vigente sin verificarla.
8. No declarar una fase completada por existencia de archivos: requerir pruebas, evidencia, revisión contable y aprobación.

### Alcance funcional final

- Empresas, usuarios, roles, permisos, parámetros fiscales, monedas y maestros.
- Plan de cuentas PCGE versionado, cuentas de movimiento, dimensiones contables y centros de costos.
- Motor de asientos, subdiarios, CUO, partida doble, tipos de cambio, extornos, cierres y auditoría.
- Libro Diario, Mayor, Balance de Comprobación, estados financieros y reportes.
- Cuentas por cobrar/pagar, caja/bancos y conciliación bancaria.
- Catálogos SUNAT correctos para libros electrónicos, PLE, RCE/RVIE y conciliación SIRE según obligatoriedad.
- NIIF completas y NIIF para PYMES mediante reglas y configuraciones aplicables; activos fijos, diferencias contable-fiscal e impuesto diferido.
- Frontend responsive orientado a digitación intensiva, integración API, automatización de pruebas y despliegue controlado.

**Fuera del alcance de esta hoja de ruta:** una reescritura de stack, mezclar datos de otro repositorio o realizar cambios en producción sin controles. Integraciones adicionales podrán priorizarse luego con aprobación.

---

## 2. Línea base observada (revisión previa; pendiente de ratificación en Fase 0)

Esta información se documenta para no perder los hallazgos, **no** como certificación definitiva del estado del sistema.

| Dimensión | Evidencia observada |
|---|---|
| Repositorio | `jwichpas/contabilidad`, rama `main`; frontend y backend separados |
| Base de datos | `contabilidad-database`, PostgreSQL; 31 tablas del esquema `public` |
| Migraciones | 14 registradas en la base de datos |
| Datos de prueba | 1 empresa; 1 usuario; 1,764 cuentas de empresa; 3,401 plantillas PCGE; 2 asientos y 4 líneas |
| Seguridad | 18 tablas `public` con RLS deshabilitado; verificar y corregir grants públicos |
| Rendimiento | 17 claves foráneas sin índice de soporte reportadas; 8 índices sin uso observado (no eliminarlos precipitadamente) |
| SQL | 5 funciones reportadas con `search_path` mutable |
| Contexto tenant | `SetTenantContext.php` usa `set_config(..., false)`; requiere revisar alcance de sesión/transacción y conexión |
| Precisión | `CreateJournalEntryController.php` usa conversiones `float`/`number_format` pese al contrato de precisión decimal |
| Concurrencia | Correlativo del asiento generado mediante `COUNT + 1`, sujeto a carreras |
| Contabilidad | Existen migración, controlador y servicio de POST de asientos; sus garantías deben probarse, no presumirse |
| UI | Existen vistas Vue para plan de cuentas, asientos y tipos de cambio; falta validación funcional end-to-end |

**Riesgo de máxima prioridad:** las tablas `users`, `companies`, `personal_access_tokens` y otras sin RLS presentan privilegios amplios otorgados a `anon`/`authenticated` en PostgreSQL. Se requiere diseñar `REVOKE`, RLS y políticas apropiadas **sin bloquear** Laravel ni el rol restringido `app_backend`. No ejecutar una activación masiva de RLS sin plan de autorización y pruebas.

---

## 3. Hoja de ruta general

Duración indicativa: **32 semanas (~8 meses) para un equipo pequeño**, con solapamientos controlados. No son fechas comprometidas. La **Fase 0 comenzó el 2026-10-19**; las ventanas S1–S32 son estimaciones y se reajustarán según los resultados.

| Fase | Nombre | Prioridad | Ventana estimada | Duración | Estado |
|---|---|---|---|---|---|
| **0** | Diagnóstico y línea base | Inmediata, previa a cambios | S1 | 1 sem. | 🟡 En progreso |
| **1** | Seguridad, autenticación y multiempresa | Crítica | S2–S3 | 2 sem. | ⬜ No iniciada |
| **2** | Integridad y motor contable | Crítica | S4–S6 | 3 sem. | ⬜ No iniciada |
| **3** | Empresas, PCGE y maestros | Alta | S7–S9 | 3 sem. | ⬜ No iniciada |
| **4** | Modernización UX/UI | Alta | S9–S12 | 4 sem. | ⬜ No iniciada |
| **5** | Libros y operación contable | Alta | S12–S17 | 6 sem. | ⬜ No iniciada |
| **6** | SUNAT, PLE y SIRE | Alta | S17–S22 | 6 sem. | ⬜ No iniciada |
| **7** | Estados financieros, NIIF y activos fijos | Alta | S21–S29 | 9 sem. | ⬜ No iniciada |
| **8** | Calidad final, preview y despliegue | Final | S29–S32 | 4 sem. | ⬜ No iniciada |

**Dependencias:** Fase 1 depende de la línea base F0; F2 de la seguridad F1; F3 de contratos y motor F2. Las tareas de interfaz F4 pueden solaparse con F3 sin integrar funciones no estabilizadas. SUNAT F6 depende de la trazabilidad contable F5. Los informes y NIIF F7 pueden comenzar parcialmente durante F6 si los libros base están conciliados. F8 requiere criterios de aceptación de las fases comprometidas.

### Gantt orientativo (32 semanas)

```text
Semanas     01 02 03 04 05 06 07 08 09 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32
F0          ██
F1             ██ ██
F2                   ██ ██ ██
F3                            ██ ██ ██
F4                                  ██ ██ ██ ██
F5                                           ██ ██ ██ ██ ██ ██
F6                                                          ██ ██ ██ ██ ██ ██
F7                                                                      ██ ██ ██ ██ ██ ██ ██ ██ ██
F8                                                                                              ██ ██ ██ ██
```

La tabla de ventanas prevalece si la tipografía del Gantt no se visualiza exactamente alineada.

---

## 4. Fases detalladas y criterios de salida

### FASE 0 — Diagnóstico, inventario y línea base (1 semana)

**Objetivo:** establecer estado verificado, riesgos, dependencias y pruebas iniciales antes de modificar el sistema.

**Trabajo:**
- Inventariar tablas, vistas, índices, funciones, triggers, roles, grants y políticas RLS del proyecto Supabase correcto.
- Comparar migraciones del repositorio con las 14 migraciones registradas y verificar **sin destruir datos** restricciones, triggers, RLS y esquema de la única base de desarrollo. Reconstruir desde cero sobre esta base no está permitido.
- Inventariar controladores, endpoints, servicios, vistas, tests y dependencias.
- Ejecutar y registrar resultados de `composer test`, análisis estático/lint, `pnpm build` y `pnpm typecheck` (si el entorno lo permite).
- Verificar posibilidad de respaldo y recuperación de la **única base Supabase de desarrollo** y emplear datos ficticios, pruebas SQL de solo lectura y tests con transacciones/rollback. **No crear base aislada** (decisión del usuario).
- Revisar vigencia normativa del PCGE 2019/2026, NIIF 18/19, PLE y SIRE en fuentes oficiales.
- Clasificar funcionalidades en **implementadas y probadas / implementadas sin validar / parciales / pendientes**.

**Entregable:** diagnóstico versionado, matriz de riesgos, línea base de tests, inventario de datos y orden de correcciones.

**Puerta F0:** arquitectura y riesgos críticos identificados, recuperación evaluada y baseline reproducible no destructivo en la **base de desarrollo única**. No ejecutar migraciones destructivas, `migrate:fresh` ni borrados en la base activa. Las medidas urgentes de manejo de secretos pueden adelantarse si son necesarias para reducir exposición.

### FASE 1 — Seguridad, autenticación y aislamiento multiempresa (2 semanas)

**Objetivo:** impedir exposición indebida y accesos cruzados de datos.

**Trabajo:**
- Segmentar tablas expuestas/no expuestas; revocar permisos de `anon` y `authenticated` donde sean innecesarios.
- Definir y probar RLS para las 18 tablas identificadas, según su rol (privada, catálogo público o dato de empresa); evitar políticas genéricas permisivas.
- Endurecer `users`, `companies`, `personal_access_tokens`; proteger secretos, contraseñas, claves y tokens.
- Revisar la interacción de `SetTenantContext`, `set_config`, `app_backend`, transacciones y pool de conexiones.
- Revisar `SECURITY DEFINER`, `search_path`, permisos `EXECUTE` y políticas para tablas con `company_id`.
- Implementar autorización por rol y empresa (administrador, contador, asistente, auditor, consulta).
- Pruebas negativas: empresa A no lee/escribe empresa B, incluidas relaciones y solicitudes directas API.
- Verificar que Laravel y los trabajos asíncronos conservan su acceso legítimo tras el endurecimiento.

**Entregable:** migraciones de seguridad, pruebas de aislamiento y matriz de acceso a datos.

**Puerta F1:** cero vías conocidas de lectura/escritura anónima indebida, acceso tenant validado, pruebas de autenticación y autorización aprobadas.

### FASE 2 — Motor contable exacto, concurrente y auditable (3 semanas)

**Objetivo:** asegurar partida doble, idempotencia y trazabilidad antes de ampliar módulos.

**Trabajo:**
- Quitar conversiones a `float` y redondeos ambiguos de importes y tasas en controlador/servicio/API; usar strings decimales + BCMath y `NUMERIC`.
- Reemplazar `COUNT + 1` por correlativos seguros bajo concurrencia, transacciones y restricciones únicas.
- Incorporar clave idempotente por operación para impedir duplicación por reintentos.
- Auditar `PostJournalEntryService`, orden de persistencia, triggers diferidos, estado de periodos y consistencia de operaciones auxiliares.
- Consolidar flujo de borrador → revisión/validación → POST → extorno, con autorización y auditoría, sin editar asientos asentados.
- Modelar subdiarios, CUO, referencia documental, origen, periodo y numeración oficial donde corresponda.
- Validar cuentas operativas, partida doble, dimensiones obligatorias, moneda funcional, tipo de cambio y operación de extorno.
- Probar simultaneidad, recuperaciones por fallos, casos límite de redondeo y saldos en MN/ME.

**Entregable:** motor de contabilización probado y contratos de API estables.

**Puerta F2:** ninguna duplicación en pruebas concurrentes; ninguna transacción desbalanceada o parcial; todos los casos críticos de precisión y extorno cubiertos por tests.

### FASE 3 — Alta de empresas, PCGE y datos maestros (3 semanas)

**Objetivo:** configurar una empresa sin scripts manuales.

**Trabajo:**
- Wizard de empresa: identificación fiscal, **persona natural/jurídica y forma legal**, **régimen NRUS/RER/RMT/General según elegibilidad**, **sector/subsector y CIIU Rev. 4** (una actividad principal y varias secundarias), ejercicio, ingresos/UIT, parámetros, moneda funcional y secundarias. Especificación: [perfil de empresa y libros SUNAT](docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md).
- Selección y copia de plantilla PCGE por empresa, manteniendo matrices globales inmutables.
- Cuentas agrupadoras sin movimiento y cuentas operativas con granularidad de 6 dígitos o superior configurable.
- Reglas por cuenta: requerir o no entidad, área, centro de costos, banco, cuenta bancaria, medio de pago y documento.
- Maestros de clientes, proveedores, bancos, trabajadores, entidades, áreas, centros de costos y subdiarios.
- Control de adopción/migración entre versiones del PCGE y mapeos trazables.
- Pruebas con empresas ficticias **comercial, servicios, industrial, agrícola, ganadera, construcción, extractiva y transporte/logística**, incluyendo mezcla de actividades y separación de perfiles tributarios por empresa.
- Mantener **historial de regímenes tributarios** y perfiles de ingresos/UIT por ejercicio. Normalizar el valor genérico `ESPECIAL` («Otros Regímenes Especiales») hacia regímenes concretos, sin romper registros históricos ni confundirlo con `RER`.

**Entregable:** asistente funcional y empresa apta para iniciar registros.

**Puerta F3:** crear empresa de prueba y registrar su primer asiento, sin modificaciones manuales en SQL.

### FASE 4 — UX/UI contable en Vue (4 semanas; parcialmente paralela)

**Objetivo:** una interfaz profesional, consistente y eficiente para contadores.

**Trabajo:**
- Separar layouts de autenticación y aplicación.
- Selector de empresa persistente en sidebar usando estado global de Vue (p. ej. Pinia) con validación de permisos.
- Mejorar navegación y carga de datos con Vue Router y estrategia de caché/queries apropiada.
- Diseñar **un editor de asiento** con cabecera única y tabla de partidas agregadas; eliminar formularios repetitivos.
- Autocompletado de cuentas/entidades, validación contextual y totales Debe/Haber en tiempo real.
- Listas avanzadas: filtros, paginación, columnas, búsqueda, exportación y estados vacíos/carga/error.
- Biblioteca visual accesible, responsiva, atajos de teclado y confirmación de cambios destructivos.
- Pruebas de navegadores desktop/móvil y de accesibilidad.

**Entregable:** flujo usable de configuración, asiento, consulta y exportación.

**Puerta F4:** completar un asiento desde una sola pantalla, sin cambiar empresa en cada vista ni repetir formularios por línea.

### FASE 5 — Libros, cuentas por cobrar/pagar y operaciones (6 semanas)

**Objetivo:** habilitar el ciclo contable mensual con saldos trazables.

**Trabajo:**
- Libro Diario (incluido formato simplificado donde aplique), Libro Mayor y Balance de Comprobación (movimientos y saldos). Disponibilidad de reportes separada de su obligatoriedad tributaria.
- Auxiliares por entidad/documento; cuentas por cobrar, cuentas por pagar y aplicación de cobros/pagos.
- Caja y bancos, cuentas 104xxx, medios de pago y conciliación bancaria.
- Apertura, ajustes, regularizaciones, cierres y reapertura autorizada.
- Reportes PDF/Excel y vistas imprimibles con filtros y origen verificable.
- Reconciliación del mayor y saldos con el Libro Diario, fuente de verdad.
- Catálogo de **tipos de libros contables** y estados de obligación por empresa/ejercicio; calcular los mínimos desde reglas versionadas SUNAT, no únicamente desde un booleano.
- Evitar saldos editables manualmente desligados del ledger.
- Conciliar mensualmente débito IGV local, crédito fiscal admitido/no admitido, prorrata si corresponde y **SFE aplicado a IGV local**, sin mezclar SFMB, solicitudes de devolución ni caja; referencia: [módulo SFE/SFMB](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md).

**Entregable:** ciclo contable operativo para una empresa ficticia de punta a punta.

**Puerta F5:** movimientos y saldos concilian; cierre impide contabilización no autorizada; reportes reproducibles.

### FASE 6 — SUNAT, PLE, RCE/RVIE y conciliación SIRE (6 semanas)

**Objetivo:** preparar información y archivos tributarios según formatos y obligación aplicables.

**Trabajo:**
- Versionar tablas SUNAT de **libros electrónicos** por formato, código y vigencia; no confundir con catálogos de emisión electrónica.
- Determinar libros obligatorios según **régimen tributario, ingresos anuales brutos/netos según norma, tramo UIT del ejercicio (300/500/1700), fecha de vigencia y actividad**, cubriendo NRUS, RER, RMT y General, así como contabilidad completa y registros adicionales.
- Diferenciar el libro obligatorio del **medio de llevado** (PLE, SIRE, físico/electrónico); gestionar fecha de inicio de obligatoriedad, rectificaciones, cambios de régimen y sustento legal por empresa. No depender de `is_sire_obliged`/`is_ple_obliged` manuales como único dato.
- Registro de Compras y Registro de Ventas con comprobantes, serie, número, fechas, RUC, IGV y moneda.
- Generación/validación de archivos PLE y de libros sujetos a obligación.
- Flujos de importación, comparación y conciliación para RCE/RVIE y SIRE, conforme a interfaces oficiales disponibles.
- Reglas de duplicados, observaciones, rectificaciones, periodos y control documentario.
- Pruebas con casos peruanos (gravado, exonerado, inafecto, notas, diferencias).
- Incorporar **SFE y SFMB de exportadores de bienes/servicios**, incluyendo empresas mixtas con ventas nacionales, límite por exportaciones computables (FOB/DAM, facturas de servicios, notas), arrastre, PDB Exportadores, compensación y devolución, con pruebas por periodo y rastreo SUNAT. La exportación calificada es **inafecta** al IGV, no gravada con tasa cero. [Especificación normativa](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md).
- Separar crédito fiscal arrastrable de empresas locales, SFE, SFMB determinado, límite disponible, compensaciones, solicitudes pendientes y cobro real; no reconocer dinero en bancos por el cálculo ni crear solicitudes automáticas sin constancia oficial.
- Validación de formato contra especificaciones SUNAT vigentes y muestras aceptadas.

**Entregable:** registros y exportaciones validadas, conciliadas con asientos y documentos.

**Puerta F6:** archivos conformes al formato oficial aplicable, trazables y reproducibles; diferencias justificadas.

### FASE 7 — Estados financieros, NIIF y activos fijos (9 semanas)

**Objetivo:** producir EEFF consistentes y soportar tratamientos contable-tributarios avanzados.

**Bloque A — EEFF:**
- Estado de Situación Financiera, Resultados (función y naturaleza), Cambios en el Patrimonio, Flujos de Efectivo y notas.
- Mapeo versionado de cuentas PCGE a rubros, subtotales y presentaciones.
- Comparativos, periodos, PDF/Excel y conciliación con Balance de Comprobación.

**Bloque B — NIIF/NIIF PYMES:**
- Motor de reglas contables versionadas: NIC 12, NIC 16, NIC 21, NIC 23, NIC 41 y normas pertinentes según la empresa.
- NIIF 18/19 y NIIF para PYMES con evaluación de alcance, fechas de vigencia y adopción.
- Ajustes de conversión, deterioro/revaluación, resultados y efecto diferido donde aplique.

**Bloque C — Activos fijos:**
- Altas con soporte documental, clasificación, depreciación financiera y tributaria.
- Mejoras, bajas, ventas, cambios de vida útil, revaluación y trazabilidad.
- Conciliación NIC 16–SUNAT, bases temporarias e impuesto diferido NIC 12.
- Construcciones en curso: contratos, costos, mano de obra, historial y fecha de disponibilidad para uso.
- Registro de Activos Fijos exigible por SUNAT en formatos vigentes, según aplicabilidad.

**Entregable:** EEFF conciliados, auxiliares de activos y reportes normativos con trazabilidad.

**Puerta F7:** EEFF = saldos de libros; auxiliar de AF = control contable; conciliación de impuesto diferido reproducible.

### FASE 8 — QA integral, rendimiento, preview y preparación productiva (4 semanas)

**Objetivo:** liberar una versión candidata estable y respaldada por evidencia.

**Trabajo:**
- Ejecutar suites unitarias, integración, end-to-end, seguridad, aislamiento tenant, concurrencia y recuperación.
- Revisar índices, planes de ejecución, carga, límites, logs y alertas.
- Automatizar CI/CD: pruebas + lint + typecheck + build antes de publicar.
- Ensayar reconstrucción por migraciones, rollback controlado y restauración de respaldos.
- Publicar un preview, verificar operación con usuarios de prueba y aprobar manuales.
- Definir checklist de paso a producción, responsables, soporte inicial y criterios de rollback.

**Entregable:** release candidate verificada, documentación y acta de aceptación.

**Puerta F8:** cero vulnerabilidades críticas conocidas, integridad contable probada, backups restaurables, pruebas obligatorias aprobadas y decisión explícita de despliegue.

---

## 5. Arquitectura objetivo y límites

- **API:** Laravel; controladores delgados, FormRequest/DTO, servicios de dominio e interfaces bien definidas.
- **Frontend:** Vue 3 + TypeScript + Tailwind; módulos funcionales, composables, estado tenant global, formularios y tablas accesibles.
- **BD:** Supabase/PostgreSQL como servidor; **DDL exclusivamente mediante migraciones de Laravel** con conexión `pgsql_migrations`, sin cambios de esquema desde el editor SQL de Supabase. Transacciones, `NUMERIC`, claves foráneas compuestas, triggers contables y RLS según corresponda.
- **Seguridad:** Sanctum, autorización por empresa/rol, privilegios mínimos, secretos fuera del cliente y auditoría.
- **Contabilidad:** ledger inmutable para POST, borradores separados cuando aplique, correlativos concurrentes, idempotencia y ajustes con trazabilidad.
- **Normativa:** catálogos vigentes con versión, fuentes y fecha de aplicación; EEFF y libros por reglas verificables.
- **Entrega:** un único repositorio, migraciones reproducibles, pruebas, documentación y previews controladas.

### Reglas de cambio

Un cambio que afecte asientos, saldos, períodos, RLS o usuarios requiere: (1) caso de uso y riesgo, (2) prueba previa, (3) migración o código versionado, (4) validación multiempresa y (5) evidencia de salida. No ejecutar cambios irreversibles directamente en Supabase sin aprobación y mecanismo de recuperación.

---

## 6. Riesgos iniciales y tratamiento

| ID | Riesgo | Gravedad | Tratamiento / fase |
|---|---|---|---|
| R01 | Tablas `public` sin RLS con grants públicos (incluidos tokens) | **Crítica** | F0: inventario; F1: `REVOKE` y políticas específicas, pruebas directas API |
| R02 | Contexto tenant persistente por sesión en conexiones reutilizadas | **Crítica** | F1: transacciones, limpieza garantizada y pruebas de pooling |
| R03 | Uso de `float` para importes y tasas | **Alta** | F2: decimal exacto y casos de redondeo |
| R04 | Correlativo `COUNT + 1` bajo concurrencia | **Alta** | F2: asignación serializada/única y reintentos idempotentes |
| R05 | Documentación normativa potencialmente desactualizada | **Alta** | F0/F6/F7: fuentes oficiales, versionado y vigencias |
| R06 | Funciones SQL con `search_path` mutable | Alta | F1: función por función y privilegios |
| R07 | Índices insuficientes o innecesarios | Media | F8: medir antes de agregar/eliminar |
| R08 | Cobertura de pruebas limitada o no verificada | Alta | F0 baseline; controles continuos F1–F8 |
| R09 | Acoplamiento de módulos / regresiones UX | Media | F3/F4: contratos, tests y diseño modular |
| **R10** | **Contraseña de BD versionada en `backend/phpunit.xml`** | **Crítica** | F0: registrar exposición; rotación urgente y retiro del secreto, incluidos rastros de Git, antes de ejecutar pruebas |
| R11 | Tests usan la base única de desarrollo | Alta | F0: revisar cada test, forzar entorno de pruebas y rollback, confirmar conexión y respaldos antes de ejecutarlos; no crear otra BD |
| R12 | README cita erróneamente resolución del PCGE 2026 | Media | F0: corregido en README; norma oficial **002-2026-EF/30**, PCGE 2026 obligatorio desde **2028**, adopción anticipada posible |
| R13 | Runner GitHub Actions no disponible en primera ejecución | Media | F0: workflow estático ya creado, run [39354337434](https://github.com/jwichpas/contabilidad/actions/runs/39354337434) sin runner ni pasos; ejecución manual hasta resolver disponibilidad |
| R15 | Rol `app_backend` con contraseña predeterminada posible en instalación nueva | Alta | F0: revisar migración original y secretos; F1: evitar valores de contraseña por defecto y rotar credenciales expuestas mediante flujo seguro |

**Política de manejo:** un riesgo crítico abierto puede bloquear la habilitación de usuarios reales o el despliegue, incluso si el módulo parece funcional.

---

## 7. Método de implementación, gobernanza y evidencias

**Estados permitidos por fase y actividad:** `NO INICIADA` → `EN PROGRESO` → `EN VALIDACIÓN` → `COMPLETADA`; adicional `BLOQUEADA` si existe impedimento.

**Definición de terminado (DoD) de una tarea:**
- Código y/o migración versionados en `main`, cuando corresponda.
- Prueba automatizada o protocolo manual con resultado registrado.
- Verificación de seguridad, precisión, empresa/tenant y normativa según el cambio.
- Sin regresiones críticas conocidas; documentación actualizada.
- Evidencia enlazada (commit, ejecución CI, captura, consulta de prueba o reporte) y responsable/revisor indicado.

**Reglas de seguimiento:**
1. Actualizar casillas y estado solo **después de la evidencia**, nunca por expectativa.
2. Registrar fecha, responsable, commit y resultado del gate de fase.
3. Conservar los hallazgos no resueltos en la tabla de riesgos.
4. Replanificar duración y dependencias si la F0 descubre trabajo no previsto.
5. Mantener el plan en este archivo y enlazar decisiones relevantes a issues/commits, sin crear ramas adicionales automáticamente.
6. No desplegar por cada ajuste menor: agrupar validaciones y publicar previews deliberados.

**Plantilla para registro de avance:**

```md
### Actualización YYYY-MM-DD — Fase X
- Responsable / revisor:
- Estado: NO INICIADA | EN PROGRESO | EN VALIDACIÓN | COMPLETADA | BLOQUEADA
- Actividades cerradas y evidencia (URL de commit/CI):
- Pruebas realizadas / resultados:
- Riesgos nuevos o mitigados:
- Bloqueos y decisiones:
- Próximo paso:
- Gate de salida aprobado por:
```

---

## 8. Hitos y puertas de calidad

| Hito | Alcance | Estado inicial | Condición verificable |
|---|---|---|---|
| **H1 — Base segura** | F0–F2 | ⬜ Pendiente | Inventario, RLS/grants corregidos, aislamiento y contabilización exacta/concurrente probados |
| **H2 — Sistema operativo** | F3–F5 (núcleo) | ⬜ Pendiente | Alta empresa + plan + asiento + Diario/Mayor/Balance + cierre sin operaciones manuales |
| **H3 — Cumplimiento tributario** | F5–F6 | ⬜ Pendiente | Libros y reportes electrónicos validados y conciliados |
| **H4 — Versión candidata** | F7–F8 | ⬜ Pendiente | EEFF/NIIF/AF comprometidos, tests integrales, preview y aceptación formal |

**Condición de desbloqueo:** cada hito requiere evidencias enlazadas y visto bueno explícito. El cronograma no reemplaza las puertas de calidad.

### Registro de aprobación (completar cuando suceda)

| Hito / Fase | Fecha de aprobación | Responsable | Evidencia | Estado |
|---|---|---|---|---|
| F0 | — | — | [Diagnóstico en progreso](docs/FASE-0-DIAGNOSTICO.md) | EN PROGRESO; pendiente de aprobación |
| F1 | — | — | — | NO INICIADA |
| F2 | — | — | — | NO INICIADA |
| F3 | — | — | — | NO INICIADA |
| F4 | — | — | — | NO INICIADA |
| F5 | — | — | — | NO INICIADA |
| F6 | — | — | — | NO INICIADA |
| F7 | — | — | — | NO INICIADA |
| F8 | — | — | — | NO INICIADA |
| H1–H4 | — | — | — | PENDIENTES |

---

## 9. Orden de ejecución y primera decisión

**Prerequisito completado (2026-10-19):** este README está en GitHub, verificado y enlazado desde `README.md`. **Fase 0 iniciada**, con evidencia y hallazgos en [docs/FASE-0-DIAGNOSTICO.md](docs/FASE-0-DIAGNOSTICO.md). Ninguna tarea se ha aprobado aún.

**Secuencia recomendada:** F0 → F1 → F2 → F3 → F4/F5 → F6 → F7 → F8, respetando los solapamientos de la hoja de ruta.

**Importante:** la Fase 0 se inició por instrucción explícita del usuario el 2026-10-19. Hasta este registro se efectuaron consultas de solo lectura y actualizaciones de Markdown; **no se ejecutaron migraciones, no se modificó Supabase ni código aplicativo, no se corrieron pruebas contra la base compartida y no se desplegó**. F1 continúa sin iniciar.

---

## 10. Checklist de seguimiento

> Marcar `[x]` exclusivamente tras completar las verificaciones y aportar evidencia. **Inicio: 0/27 actividades aprobadas (0%).**

### Fase 0 — Diagnóstico y línea base
- [ ] **F0.1** Inventariar Supabase, grants/RLS y respaldo con restauración verificada.
- [ ] **F0.2** Contrastar migraciones, código, módulos y estado funcional real.
- [ ] **F0.3** Ejecutar baseline de pruebas y revisar documentación normativa.

### Fase 1 — Seguridad
- [ ] **F1.1** Corregir exposición pública, privilegios y políticas RLS sin bloquear backend.
- [ ] **F1.2** Corregir contexto tenant transaccional, pooling y funciones privilegiadas.
- [ ] **F1.3** Probar separación entre empresas, roles y permisos.

### Fase 2 — Motor contable
- [ ] **F2.1** Eliminar punto flotante del circuito monetario y validar precisión.
- [ ] **F2.2** Implementar numeración segura, idempotencia y atomicidad.
- [ ] **F2.3** Validar POST, extornos, cierre y controles de partida doble.

### Fase 3 — Empresas y maestros
- [ ] **F3.1** Implementar asistente de empresas y parámetros fiscales.
- [ ] **F3.2** Consolidar plantillas PCGE y cuentas operativas parametrizadas.
- [ ] **F3.3** Completar maestros, dimensiones y empresas ficticias.

### Fase 4 — Interfaz
- [ ] **F4.1** Separar layouts, navegación y selector global de empresa.
- [ ] **F4.2** Sustituir formularios repetidos por editor único de asientos.
- [ ] **F4.3** Mejorar tablas, validaciones, accesibilidad y responsive.

### Fase 5 — Gestión contable
- [ ] **F5.1** Consolidar Diario, Mayor y Balance de Comprobación.
- [ ] **F5.2** Completar CxC, CxP, bancos y conciliación.
- [ ] **F5.3** Validar cierres, reconciliación y reportes PDF/Excel.

### Fase 6 — SUNAT
- [ ] **F6.1** Versionar catálogos SUNAT y generar archivos PLE aplicables.
- [ ] **F6.2** Incorporar registros RCE/RVIE y flujos de conciliación SIRE.
- [ ] **F6.3** Validar formatos, duplicidades, observaciones y conciliación.
- [ ] **F6.4** Implementar y probar SFE/SFMB (exportaciones bienes/servicios y empresas mixtas): origen del crédito, aplicación a IGV local, límites/arrastres, PDB, compensación/devolución y trazabilidad. [Documento de diseño](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md).

### Fase 7 — NIIF y EEFF
- [ ] **F7.1** Implementar EEFF y matrices de rubros trazables.
- [ ] **F7.2** Aplicar reglas contables NIIF/NIIF PYMES y diferencias temporarias.
- [ ] **F7.3** Completar activos fijos, obra en curso y conciliación contable-fiscal.

### Fase 8 — Liberación
- [ ] **F8.1** Completar pruebas y optimización de rendimiento/seguridad.
- [ ] **F8.2** Automatizar CI/CD, migraciones y recuperación.
- [ ] **F8.3** Validar preview, documentación y aceptación final.

### Resumen editable de seguimiento

| Indicador | Valor inicial | Método de actualización |
|---|---|---|
| Actividades aprobadas | **0/27 (0%)** | Contar `[x]` en sección 10 |
| Fases aprobadas | **0/9** | Registrar puerta de calidad y evidencia de sección 8 |
| Hitos aprobados | **0/4** | Registrar aprobación de H1–H4 |
| Fase actual | **F0 — EN PROGRESO, sin aprobaciones** | [Registro de diagnóstico](docs/FASE-0-DIAGNOSTICO.md) |
| Bloqueos críticos conocidos | **R01, R02, R10 (contraseña antigua aún pendiente de rotación)** | Actualizar matriz de riesgos y evidencia |
| Próxima acción prioritaria | **Rotar contraseña expuesta, revisar tests transaccionales y verificar recuperación** | Se usará exclusivamente la BD de desarrollo actual |

---

### Continuación 2026-10-19 — Base única de desarrollo

- **Decisión de trabajo:** `contabilidad-database` es la única base durante el desarrollo; no se creará BD aislada ni nueva rama. Se permiten pruebas transaccionales y verificaciones SQL controladas; **no** se permiten `migrate:fresh`, `db:wipe`, truncados ni seeders destructivos sobre los datos existentes.
- **Cambio aplicado en GitHub:** contraseña literal retirada de `backend/phpunit.xml` en commit [cc363a17](https://github.com/jwichpas/contabilidad/commit/cc363a17a351114bc61c3a29f1901f127b095b16). **Pendiente crítico:** rotar la contraseña real y actualizar conexiones; retirar del HEAD no borra el historial.
- **Base validada mediante SELECT:** 2 asientos, 4 partidas, cero descuadres MN/ME, cero orfandades verificadas; 1,212 cuentas operativas y solo 21 de seis dígitos. Ninguna prueba automatizada ejecutada aún.
- **Normativa verificada:** [Resolución CNC 002-2026-EF/30](https://busquedas.elperuano.pe/dispositivo/NL/2550786-1), PCGE 2026 obligatorio desde 2028, adopción anticipada posible; README principal corregido.
- **Evidencias y pendientes:** [Registro actualizado de Fase 0](docs/FASE-0-DIAGNOSTICO.md). F0.1–F0.3 aún abiertas; 0/27 marcadas; F1 no iniciada.

### Actualización 2026-10-19 — F0: preflight, respaldo y matriz funcional

- Se agregó [preflight estático de F0](backend/scripts/phase0-preflight.php), independiente de Laravel/Composer/PostgreSQL. Validada su sintaxis en PHP 8.4 y ensayados dos casos simulados: 8 verificaciones satisfactorias y detección de contraseña inline. **Aún no se ejecutó sobre un checkout completo del repositorio con credenciales reales.**
- Se agregó [script protegido de respaldo](backend/scripts/phase0-backup.sh): exporta `public` en formato custom de `pg_dump`, genera catálogo con `pg_restore --list` y SHA-256 fuera del repositorio. Sintaxis Bash comprobada; **no se ha ejecutado contra la BD ni se ha hecho prueba de restauración**.
- [Matriz de estado funcional de F0](docs/FASE-0-MATRIZ-FUNCIONAL.md): inventario por módulo con evidencia, rutas ya existentes y ausencias localizadas. Precaución: Vue ya incluye selector global de empresa y un editor de asientos; **no rehacer sin pruebas**.
- [Comando Artisan F0](backend/app/Console/Commands/AuditPhaseZero.php) mejorado: comprueba el proyecto correcto, abre transacción de solo lectura y compara nombres de migraciones.
- Nuevos hallazgos para **F2**: frontend `JournalEntryView.vue` usa `parseFloat`/`toFixed`; motor de extornos disponible como servicio, pero sin ruta API localizada.
- **Sin modificación de esquema**, migraciones o datos en Supabase. F0.1, F0.2 y F0.3 siguen **EN PROGRESO** (0/27 tareas aprobadas).

### Actualización 2026-10-19 — Laravel como único gestor de esquema

- **Regla obligatoria:** las tablas, índices, restricciones, triggers, políticas RLS y demás cambios DDL se crean o modifican desde **migraciones Laravel**, jamás desde el editor SQL de Supabase. Las verificaciones `SELECT` y advisories de Supabase continúan permitidas.
- Política formal: [docs/ARQUITECTURA-BD-LARAVEL.md](docs/ARQUITECTURA-BD-LARAVEL.md). Se mantendrá exclusivamente la base de desarrollo `contabilidad-database` y la rama `main`.
- Nuevo comando `php artisan contabilidad:auditar-fase0` en `backend/app/Console/Commands/AuditPhaseZero.php`, que realiza consultas de solo lectura en la conexión administrativa Laravel. **La sintaxis PHP fue comprobada localmente, pero el comando todavía no se ha ejecutado desde el proyecto desplegado.**
- `backend/config/database.php`: `pgsql_migrations` ya no hereda el `DATABASE_URL` de la conexión de aplicación; `backend/phpunit.xml` dejó de fijar host y usuario privilegiado; `backend/.env.example` diferencia roles y puertos.
- Catálogo PostgreSQL confirmado por SQL de solo lectura: **31 tablas, 94 índices, 5 funciones, 6 triggers no internos, 14 políticas y 14 migraciones Laravel**.
- F0.1–F0.3 siguen abiertas por respaldos sin verificar, ejecución de tests bloqueada y revisión funcional incompleta. **Fase 1 no iniciada.**

### Actualización 2026-10-19 — Workflow estático de verificación

- Se creó [`phase0-static-baseline.yml`](.github/workflows/phase0-static-baseline.yml) para comprobar Vue y Laravel **sin conexión a Supabase** ni despliegues.
- El [primer workflow](https://github.com/jwichpas/contabilidad/actions/runs/39354337434) acabó fallido porque no se asignó ningún runner ni se ejecutaron pasos; no es evidencia de error del código.
- Para evitar fallos repetidos en cada push se mantuvo **solo ejecución manual**, pendiente de resolver disponibilidad de GitHub Actions.
- Sigue pendiente rotar la credencial real de la BD; `backend/phpunit.xml` ya no tiene contraseña inline.
- **F0 sigue abierta (0/27)**. Ver [diagnóstico completo](docs/FASE-0-DIAGNOSTICO.md).

### Actualización 2026-10-19 — Inicio Fase 0

- **Estado:** EN PROGRESO; F0.1, F0.2 y F0.3 con trabajo de inventario en curso, ninguna completada.
- **Evidencias:** [docs/FASE-0-DIAGNOSTICO.md](docs/FASE-0-DIAGNOSTICO.md).
- **Descubrimientos críticos:** R10 (contraseña de BD en PHPUnit), R11 (tests apuntan a BD compartida), R01/R02 reconfirmados.
- **Puerta pendiente:** controles de respaldo/recuperación de la BD existente, comparación no destructiva de migraciones, ejecución real de tests y validación restante de PLE/SIRE.

### Correcciones implementadas en código — aún sin aplicar a PostgreSQL (2026-10-19)

- **Middleware multiempresa:** `backend/app/Http/Middleware/SetTenantContext.php` ahora usa `TenantContext::run()` para ejecutar verificación de membresía y controlador en una misma transacción; evita el contexto de sesión persistente y elimina la caché de autorizaciones de 60 s. Commit [2bb34d6](https://github.com/jwichpas/contabilidad/commit/2bb34d6b98a3d7bd6ffe57609de00410d4fd7cfa). **Integración PostgreSQL pendiente de probar**.
- **Migración Laravel de protección de tablas:** `backend/database/migrations/2026_10_19_000000_restrict_supabase_data_api_grants.php` retira los accesos de tablas y secuencias a los roles públicos `anon` y `authenticated`, manteniendo los permisos de `app_backend`. Commit [75a6407](https://github.com/jwichpas/contabilidad/commit/75a64070aad34fe68c6cc272bd78232fae903304). **Pendiente de ejecutar desde Artisan; no se ejecutó ninguna migración desde Supabase.**
- **Pruebas incorporadas:** `backend/tests/Unit/SetTenantContextMiddlewareTest.php` y `backend/tests/Feature/Security/PublicDataApiPrivilegesTest.php`. Se amplió `phpunit.xml` para registrar el suite Unit y el workflow para ejecutarlo cuando haya runner. **Resultados de ejecución pendientes**.
- **Último SELECT de validación de riesgo:** 14 migraciones aplicadas, 31 tablas, 18 sin RLS, y ambas funciones public API continúan con privilegio SELECT sobre 31 tablas. No indicar que la vulnerabilidad está cerrada hasta aplicar la migración Laravel y ejecutar su prueba.
- **Estado de gobernanza:** Fase 0 continúa EN PROGRESO; reparaciones críticas de Fase 1 iniciadas a nivel de código, no aprobadas ni aplicadas. Checklist sigue en 0/27 completadas.

### Ejecución de correcciones de código — 2026-10-19

**Se realizaron cambios funcionales en `main`, no solo documentación.** Sin cambiar directamente el esquema de Supabase.

| Área | Cambio en código | Evidencia y límites |
|---|---|---|
| Seguridad multiempresa | `SetTenantContext` ejecuta el flujo en una transacción con contexto local y comprueba la membresía por petición (sin caché de 60 s) | [Commit 2bb34d6](https://github.com/jwichpas/contabilidad/commit/2bb34d6b98a3d7bd6ffe57609de00410d4fd7cfa); pruebas de integración aún pendientes |
| Permisos de datos | Nueva migración **Laravel**, `2026_10_19_000000_restrict_supabase_data_api_grants.php`, retira GRANT de roles públicos; prueba ajustada para usar `pg_catalog.has_table_privilege` | [Migración](backend/database/migrations/2026_10_19_000000_restrict_supabase_data_api_grants.php). **No aplicada**. La BD todavía expone SELECT a ambos roles públicos en 31 tablas |
| Precisión backend | `CreateJournalEntryController` elimina todos los casts a `float` y `number_format`, valida 4/6 decimales y utiliza el objeto BCMath `Decimal` | [Controlador](backend/app/Http/Controllers/Api/V1/Accounting/CreateJournalEntryController.php), commit `e605d0e`; falta prueba Laravel real |
| Compatibilidad PHP | `Decimal.php` ya no usa constantes tipadas de PHP 8.3; así respeta el mínimo PHP 8.2 de Composer | [Objeto decimal](backend/app/Support/Math/Decimal.php), commit `2d3bacb` |
| Precisión frontend | Vue serializa montos y tasas como **strings**, calcula totales/conversiones/correcciones con `SystemDecimal` / `BigInt` y compara saldos a 4 decimales sin `parseFloat` | [Editor](frontend/src/modules/accounting/views/JournalEntryView.vue), [utilidad](frontend/src/shared/types/numeric.types.ts). Compilación y pruebas E2E pendientes |
| Pruebas preparadas | Unitarias de middleware y Decimal en Laravel; pruebas de `BigInt` en Node; workflow actualizado para incluirlas | [Backend Unit](backend/tests/Unit/DecimalExactnessTest.php) y [Frontend smoke](frontend/scripts/decimal-smoke.test.mjs). GitHub Actions sigue sin runner verificado |

**Verificación de PostgreSQL por SQL de solo lectura:** 14 migraciones registradas; 31 tablas; 18 sin RLS; 2 asientos / 4 partidas; el acceso SELECT de `anon` y `authenticated` sigue vigente en las 31 tablas. **No marcar R01 solucionado** hasta ejecutar la migración con Artisan en la misma BD, comprobar que se mantienen los permisos `app_backend` y pasar los tests.

**Avance parcial de F1/F2 en código:** estas correcciones se priorizaron por vulnerabilidades ya verificadas, sin declarar cerrada Fase 0, F1 o F2. La ejecución completa requiere un runner Laravel disponible; el único proyecto de desarrollo continúa siendo `contabilidad-database`. No ejecutar DDL desde Supabase.

### Continuación F0/F2 — cierre de pérdida de precisión y credenciales administrativas

- **Corrección en motor contable:** `Decimal::from()` ya **rechaza cifras decimales significativas que exceden la escala**, en lugar de truncarlas silenciosamente con BCMath. Se permiten ceros redundantes. [Commit d0a30dd](https://github.com/jwichpas/contabilidad/commit/d0a30dde01ef3427596c1c880c286207e9b733ed).
- **Pruebas nuevas:** `backend/tests/Unit/DecimalExactnessTest.php` verifica importes de 4 y tipos de cambio de 6 decimales, incluso cuando el exceso contiene cifras no cero. [Commit 7789224](https://github.com/jwichpas/contabilidad/commit/778922457133b8ce12b2e9161b5790e4efe55544). Se probaron localmente 4 casos de la **lógica de escala aislada** con PHP, 4/4 correctos; **no se ejecutó PHPUnit**, dado que no está disponible Composer/BCMath ni el runner remoto.
- **Credenciales separadas:** `config/database.php` deja de heredar `DB_HOST`, `DB_USERNAME` o `DB_PASSWORD` de la conexión de aplicación en `pgsql_migrations`. Requiere explícitamente sus variables `DB_MIGRATIONS_*`. [Commit 3a92751](https://github.com/jwichpas/contabilidad/commit/3a92751148fc487cbdf1ecfd4ca841c147d36e19b9251525c6b7).
- **Preflight fortalecido:** rechaza la configuración que vuelva a heredar credenciales del backend. [Commit ad386d3](https://github.com/jwichpas/contabilidad/commit/ad386d385534eda6b7a1f1fd4ca841c147d36e8e).
- **Bloqueos abiertos:** rotar contraseña expuesta y actualizar entorno Laravel; disponer de runner para `composer test`, `php artisan contabilidad:auditar-fase0` y `pnpm build`; aplicar la migración de permisos **solo mediante Artisan**, no desde Supabase. No se ha aplicado una nueva migración ni alterado tablas.
- **Estado:** F0 sigue `EN PROGRESO`; **F2 tiene correcciones en código sin aprobación**. No marcar tareas completas hasta ejecutar y registrar resultados.

### Verificación adicional de seguridad — 2026-10-19

- Migración Laravel pendiente `2026_10_19_000000_restrict_supabase_data_api_grants.php`: controla permisos de tablas, secuencias y ejecución de funciones `public`, preservando acceso de `app_backend`.
- Se corrigió una consulta PostgreSQL de secuencias mediante una CTE `MATERIALIZED`; comprobación por `SELECT` correcta. [Commit](https://github.com/jwichpas/contabilidad/commit/8e4c580d9e99fd5fb4f3eceb993d37f40ff89d80).
- Archivo PHP exacto de GitHub verificado por SHA de objeto Git, sintaxis PHP válida, **7/7 escenarios simulados** (aceptación, rechazo de proyecto/rol, y rollback por permisos persistentes o pérdida de acceso del backend). **No son pruebas sobre la base real**.
- La base Supabase reporta **14 migraciones aplicadas; la migración de seguridad aún no figura en `public.migrations`**. El script no se ha ejecutado con Artisan.
- Bloqueo real: ausencia de un ejecutor Laravel operativo y de conexión administrativa con credenciales renovadas. No ejecutar DDL desde el editor de Supabase, no dar la seguridad por aprobada y mantener F0/F1 abiertas.

### Incorporación de perfil empresarial, sectores y obligaciones SUNAT — 2026-10-19

- **Origen:** solicitud de contemplar los cuatro regímenes que aparecen en la imagen informativa, más segmentación de empresas por actividad (comercio, servicios, ganadería, industria, etc.).
- **Diagnóstico confirmado:** `company_settings.tax_regime` ya existe con `GENERAL`, `MYPE_TRIBUTARIO`, `RER` y `ESPECIAL`; Vue presenta selector tributario y booleanos SIRE/PLE. **No están implementados** forma jurídica, sector/CIIU, perfiles de ingresos/UIT ni motor de libros exigibles.
- **Requerimiento obligatorio añadido a F3/F5/F6:** [docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md](docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md). Separar naturaleza legal, régimen tributario, segmento económico y nivel de ingresos/obligaciones anuales, con reglas por vigencia SUNAT.
- **Normativa:** https://emprender.sunat.gob.pe/comprobantes-libros/registros-libros-electronicos/libros-registros-contables-obligatorios y régimen General: https://emprender.sunat.gob.pe/ruc/regimenes-tributarios-mype/regimen-general-renta-rg.
- **Ejecución:** especificación comprometida en `main`, **sin nueva migración ejecutada ni cambios a Supabase**. La migración pendiente de seguridad continúa pendiente de Artisan.

### Control de integración pendiente — clasificación económica de empresas

- **Dos migraciones Laravel preparadas y en orden de nombre:** `2026_10_19_000000_restrict_supabase_data_api_grants.php` (seguridad) y `2026_10_19_010000_add_company_economic_profile.php` (perfil económico). Ambas **sin aplicar**; `public.migrations` aún muestra **14** y no existen la nueva tabla ni la columna `legal_form`.
- **Cambio de API ya integrado:** `GET/PUT /api/v1/company/settings` conserva sus rutas existentes; respuesta adicional `economic_profile_ready` evita errores antes de migrar. La actualización económica es atómica e incluye actor `created_by/updated_by` y auditoría de la operación.
- **Protección tenant:** nueva tabla con `company_id`, FKs, políticas RLS `ENABLE/FORCE`, permisos de `app_backend`, unicidad de CIIU por empresa e índice único parcial que impide dos actividades principales.
- **Vue integrado:** pestaña «Tributario & SIRE» con forma jurídica, sector, CIIU, actividades secundarias y selección de principal. Si falta la migración, muestra aviso de configuración pendiente y permite usar la configuración previa. Al cambiar de empresa descarta cargas atrasadas.
- **Tests sin conexión:** cinco casos `backend/tests/Unit/CompanyEconomicProfileTest.php` para invariantes; falta ejecutar Pest y `pnpm typecheck/build` por indisponibilidad del runner. No considerar las pruebas aprobadas sin resultados.
- **Alcance pendiente:** esto es la **edición del perfil de empresa existente**, NO el wizard de alta completo ni el motor de libros obligatorios; esos continúan en F3/F5/F6. No se ha efectuado despliegue ni DDL por Supabase.

### Avance en Vue y API — Asistente de alta de empresas (2026-10-20)

- **Interfaz Vue terminada en código:** [`CompanyOnboardingWizard.vue`](frontend/src/modules/company/views/CompanyOnboardingWizard.vue) con cinco pasos (identificación → actividad económica/CIIU → tributación → monedas/PCGE → confirmación), progreso, errores por campo, edición de actividades principal/secundarias, resumen final y borrador de sesión.
- **Integración real en la navegación:** [`App.vue`](frontend/src/App.vue), [sidebar](frontend/src/shared/layout/AppSidebar.vue), encabezado y módulo `company-create` con URL `#nueva-empresa`. Una creación confirmada por la API se incorpora al selector de empresas y se activa.
- **API Laravel preparada:** `GET /api/v1/tenant/company-onboarding/capabilities` y `POST /api/v1/tenant/companies`, protegidos con Sanctum y membresía activa `ADMIN`, con validación RUC, CIIU, régimen, ejercicio, ingresos, monedas y adopción PCGE.
- **Alta transaccional:** al ejecutarse el POST se prevé crear empresa, membresía ADMIN, `company_settings`, monedas, actividades y copia privada de la plantilla PCGE como una sola unidad; en caso de error, rollback. Auditoría del evento registrada en el flujo. **Sin ejecución real todavía**.
- **Nueva migración Laravel pendiente (actualizada sin aplicar):** `2026_10_19_010000_add_company_economic_profile.php` ahora incorpora `initial_fiscal_year`, `income_reference_year`, `annual_income` e `initial_pcge_version`, además de forma jurídica y actividades.
- **Control de habilitación:** el asistente puede consultarse y guardar borradores antes de migrar, pero la confirmación de alta permanece deshabilitada hasta que `CompanyOnboardingGuard` confirme que existe el esquema y que el usuario es ADMIN. No se simulan catálogos de monedas ni plantillas PCGE.
- **Pruebas preparadas:** [`company-onboarding.test.mjs`](frontend/scripts/company-onboarding.test.mjs) (6 casos), [`CompanyOnboardingRulesTest.php`](backend/tests/Unit/CompanyOnboardingRulesTest.php) y existentes de perfil. Ejecutar `npm run test:unit`, `npm run typecheck`/`npm run build` en frontend y pruebas Pest Laravel cuando dispongamos de runner.
- **Estado honesto:** IMPLEMENTADO EN CÓDIGO; **no desplegado, no compilado ni probado end-to-end**. Supabase se conserva sin DDL. F3.1 sigue **EN VALIDACIÓN/PARCIAL** y no se marca `[x]` hasta crear empresa ficticia y registrar primer asiento desde UI.

### Estado verificado por SQL de solo lectura, F0 y F3 (2026-10-20)

- **Migraciones aplicadas por el usuario:** seguridad (batch 6) y nuevo perfil económico (batch 7); el inventario es **16/16 aplicadas**, sin nuevas pendientes al momento de la consulta.
- **Permisos R01 observados:** `anon` y `authenticated` sin permisos efectivos sobre las **32 tablas, secuencias y 5 funciones del esquema public**. Accesos `app_backend` conservados e incrementados solo para nueva tabla/secuencia (31 tablas con SELECT; 20 secuencias con USAGE; 5 funciones con EXECUTE).
- **Perfil empresarial:** cinco columnas añadidas a `company_settings`, tabla `company_economic_activities`, FKs y unicidades presentes, RLS habilitado/forzado y política tenant de `app_backend` confirmadas.
- **Integridad de movimientos:** 2 asientos, 4 partidas; **ningún asiento desbalanceado ni inválido**, ninguna partida huérfana ni referencia de cuenta inválida en el control F0.
- **Pendientes verificables:** ejecutar la auditoría Laravel de solo lectura `php artisan contabilidad:auditar-fase0`; comprobar credenciales/rutas `app_backend` y probar funcionalmente alta y selector de empresas; revisar 18 tablas sin RLS. Contraseñas rotadas/recuperación de respaldo aún por certificar.
- **Corrección previa a la siguiente ejecución de F0:** CTE `MATERIALIZED` en `AuditPhaseZero.php` y `PublicDataApiPrivilegesTest.php` para evitar errores PostgreSQL de secuencias. SQL comprobado por consulta de solo lectura. Es necesario `git pull --ff-only` en el equipo del usuario para tomar ambos archivos.
- **Criterio de aprobación:** las migraciones exitosas **no cierran** por sí mismas la Fase 0. Los controles de pruebas de aislamiento, integridad y recuperación siguen abiertos.

### Avance en código de Fase 3 — Perfil económico multiempresa (2026-10-19)

- **Preparado en `main` (NO aplicado en PostgreSQL):** [migración Laravel `2026_10_19_010000_add_company_economic_profile.php`](backend/database/migrations/2026_10_19_010000_add_company_economic_profile.php), que incorpora `company_settings.legal_form` y `company_economic_activities` con códigos sectoriales/CIIU, una actividad principal, varias secundarias, validaciones SQL, RLS forzado y permisos exclusivos de `app_backend`. No modifica las migraciones antiguas ni los registros existentes.
- **Backend desarrollado:** modelo tenant [`CompanyEconomicActivity`](backend/app/Models/CompanyEconomicActivity.php), catálogo y reglas [`CompanyEconomicProfile`](backend/app/Support/Company/CompanyEconomicProfile.php), consultas GET y guardado PUT transaccional en controladores de configuración; rechaza perfiles inválidos, códigos CIIU duplicados y NRUS en personas jurídicas.
- **Frontend desarrollado:** [`CompanySettingsView.vue`](frontend/src/modules/company/views/CompanySettingsView.vue) muestra forma jurídica, macrosector, CIIU, principal/secundaria y permite añadir hasta diez actividades. **Compatibilidad con despliegue antes de migrar:** API envía `economic_profile_ready=false`; Vue muestra aviso y no intenta consultar ni guardar columnas/tablas faltantes.
- **Pruebas preparadas:** [`CompanyEconomicProfileTest.php`](backend/tests/Unit/CompanyEconomicProfileTest.php) contiene cinco grupos de casos de validación sin necesitar base de datos. **Pendiente ejecutar PHPUnit y compilar Vue**; GitHub Actions sigue sin runner disponible.
- **Corrección multiempresa:** el formulario recarga al cambiar `activeCompanyId` y descarta respuestas atrasadas de otra empresa.
- **Mañana:** las migraciones se ejecutan **en orden desde Artisan**, primero la de seguridad `2026_10_19_000000_restrict_supabase_data_api_grants`, después `2026_10_19_010000_add_company_economic_profile`. Verificar tablas/permisos y ejecutar pruebas con la única base `contabilidad-database`.
- **Estado formal:** F3.1 **en implementación, no aprobada**. F0/F1/F2/F3 no han superado sus puertas de calidad; el checklist maestro permanece sin marcas hasta contar con evidencias reales.

### Análisis tributario SUNAT — SFE y SFMB exportadores (2026-10-20)

- **Especificación vinculante F3/F5/F6:** [docs/F6-IGV-SFE-SFMB-EXPORTADORES.md](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md), derivada de los arts. 33–35 del TUO del IGV, reglamento DS 126-94-EF y orientación oficial SUNAT de compensación/devolución.
- **Corrección jurídica:** exportaciones calificadas son operaciones **inafectas** IGV, no gravadas al «0 %». El SFE de crédito fiscal admitido se deduce del IGV bruto de ventas locales; el remanente SFMB no implica autorización automática de compensación/devolución.
- **Empresas mixtas y de cualquier sector:** sector económico, régimen, actividad exportadora y operaciones validadas son dimensiones diferentes. El SFMB requiere sustentos de periodo; una empresa solo local mantiene saldo ordinario arrastrable salvo otros supuestos normativos.
- **Hito F6.4 agregado:** motor mensual, límite tasa IGV+IPM sobre exportación computable, arrastres entre periodos, diferencias entre factura y embarque, registro de servicios cuando corresponda, PDB, estados de solicitudes, compensación y caja.
- **Estado:** exclusivamente análisis/arquitectura y criterios de pruebas registrados en GitHub; **no se ha desarrollado aún el motor SFMB, migraciones específicas, UI fiscal ni modificaciones de tablas.** No declarar F6.4 completo hasta pruebas comparadas con casos SUNAT y conciliación contable.

## 11. Referencias y control documental

- Código y arquitectura actual: `backend/`, `frontend/`, `backend/database/migrations/`, `backend/tests/`, `README.md`.
- **Política obligatoria de esquema Laravel:** [docs/ARQUITECTURA-BD-LARAVEL.md](docs/ARQUITECTURA-BD-LARAVEL.md).
- **Matriz funcional F0:** [docs/FASE-0-MATRIZ-FUNCIONAL.md](docs/FASE-0-MATRIZ-FUNCIONAL.md).
- **Requerimiento vinculante empresa/sector/libros SUNAT:** [docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md](docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md).
- **Preflight y respaldo F0:** `php backend/scripts/phase0-preflight.php` y `backend/scripts/phase0-backup.sh` (este último no ejecutado aún).
- **Comando de diagnóstico F0:** `php artisan contabilidad:auditar-fase0` (solo lectura; sin mutaciones).
- Referencias internas: `plan-contable-2019.md`, `plan-contable-2026.md`, `tablas-sunat.md`.
- Guías oficiales a comprobar en F0: [SUNAT](https://www.sunat.gob.pe/), [MEF — Consejo Normativo de Contabilidad](https://www.gob.pe/mef), [Supabase RLS](https://supabase.com/docs/guides/database/postgres/row-level-security), [Laravel](https://laravel.com/docs/11.x).
- **Control de versiones:** v1.7 — 2026-10-19 — Control estricto de decimales y credenciales de migración; v1.6 — 2026-10-19 — Cambios funcionales de seguridad y precisión en Laravel/Vue; v1.5 — 2026-10-19 — Preflight, respaldo no destructivo y matriz F0; v1.4 — 2026-10-19 — Laravel como único gestor de esquema y auditoría F0 read-only; v1.3 — 2026-10-19 — Workflow CI estático y riesgo runner; v1.2 — 2026-10-19 — Política de base única y F0 SQL; v1.1 — 2026-10-19 — Inicio F0 y enlace de diagnóstico; v1.0 — 2026-10-19 — plan aprobado para documentación previa a Fase 0. Los ajustes futuros deben registrar fecha, motivo y relación con decisiones/hallazgos.

**Estado al 2026-10-19: _FASE 0 EN PROGRESO SOBRE LA BD DE DESARROLLO ÚNICA; 0/27 ACTIVIDADES APROBADAS; HITOS H1–H4 PENDIENTES_.**
