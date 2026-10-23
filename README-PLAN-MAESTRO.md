# Plan maestro, hoja de ruta e hitos — Sistema Contable

> **Documento rector vigente · Actualizado 23/10/2026 · Versión 2.1**  
> Repositorio: `jwichpas/contabilidad` · Rama única: `main` · Base de desarrollo: **Neon PostgreSQL 18 / contabilidad-dev**.
>
> **Avance verificable: 7/32 actividades completas (22%).** El checklist de la [sección 10](#10-checklist-de-seguimiento) es la **fuente única** del conteo. Se distinguió expresamente entre una prueba/subetapa aprobada y una actividad amplia todavía pendiente.
>
> **Fase 1 validada técnicamente en Neon DEV.** El motor POST, extornos, analítica 94/95/79 y aislamiento ACTIVE+RLS fueron probados manualmente (F2.3b–F2.3h). **F2.2 ha quedado cerrada con pruebas de idempotencia HTTP en Neon DEV**, mientras **F2.3 completada:** POST/extornos y cierres/reaperturas/bloqueos bajo prueba HTTP, concurrencia de dos conexiones y apertura real con auditoría comprobada en Neon DEV. F3.2 dispone de 3.401 plantillas PCGE y 3 empresas DEMO con planes operativos de seis dígitos.
>
> **F2.2 (22/10/2026):** se publicaron migración aditiva `journal_post_idempotency`, servicio de recibos inmutables por empresa/usuario, respuestas HTTP 201/200/409, reutilización controlada de clave UUID en Vue y pruebas Docker PG18 ampliadas de 19 a 20 migraciones. **Docker PG18 y Vue (16/16 tests, typecheck y build) APROBADOS; migración aditiva Laravel aplicada y verificada en Neon DEV (22 migraciones, recibos FORCE RLS).** **HTTP 201/200/409 probado en Neon con app_backend/Sanctum**, extorno, RLS y ROLLBACK; sin recibos ni asientos persistidos. **F2.2 APROBADA**. Nota operativa: CACHE_STORE, SESSION_DRIVER y QUEUE_CONNECTION ya fueron configurados y su diagnóstico local terminó PASS; los ensayos integradores también mantienen drivers temporales en memoria. [Plan y comandos](docs/F2-2-IDEMPOTENCIA-POST.md).
>
> **Decisión: NO utilizar GitHub Actions.** Los dos workflows fueron retirados de `main`; las pruebas se ejecutan **manualmente** en Windows/PowerShell/PHP/Neon DEV y PostgreSQL Docker desechable. Procedimiento y resultados en [Validación manual y evidencias](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md). **No hay habilitación automática de matrices ni uso de datos reales.**
>
> **Línea base histórica del 22/10/2026:** **24 migraciones**, **4 empresas** (tres DEMO y TRANSPORTES URVISUR EIRL, ID 4, alta HTTP 201 confirmada), **9 membresías**, **3 matrices DRAFT / 0 ACTIVE**. La nueva empresa tiene 2.966 cuentas PCGE (1.214 operativas de seis dígitos), 2 monedas, 6 áreas, 9 flujos NIC 7, dos códigos tributarios y una auditoría de alta. El período DEMO A enero 2026 sigue OPEN; cero asientos registrados. **Rendimiento POST de 45,97 s y un macrosector incoherente en la actividad de empresa 4 permanecen como incidencias documentadas.**
>
> **Última evidencia 23/10/2026 (Neon DEV):** F3.5 con COMMIT del primer asiento ficticio **ID 52**, empresa 4: octubre 2026 OPEN, una tasa FX, un recibo idempotente y dos auditorías nuevas. Comprobación post-COMMIT PASS. F3.4 fue reversible con ROLLBACK. F3.3 comprobó 2.966 cuentas, 1.214 operativas. El onboarding reversible F3.1 más reciente midió **17,43 s / 52 SQL** (cumplió objetivo orientativo menor de 25 s). Pendiente: corregir de modo auditable sector COMERCIAL vs TRANSPORTE_LOGISTICA para CIIU 4923 de empresa 4; también completar maestros F3.3 del checklist.
>
> El registro de notas históricas que antes se acumulaba en el encabezado y debajo del checklist fue preservado en [Historial del plan](docs/HISTORIAL-PLAN-MAESTRO-2026-10.md). Los estados antiguos del archivo no reemplazan el estado vigente.


## Avance F3.2–F3.3 — 23/10/2026

- **F3.2 aislamiento tenant:** validación manual en Neon DEV por el usuario, `php artisan test --testsuite=Feature --filter=Tenant`: **19/19 PASS, 78 aserciones**. También `CompanySetupAndCatalogsTest` 6/6 (44 aserciones), `TenantIsolationTest` 5/5 (15) y `TenantContextNestedTest` 3/3 (20).
- **F3.3 empresa persistente:** el onboarding HTTP ya creó la empresa ficticia ID 4, documentada en la línea base. En lugar de duplicarla, se agregó el ensayo **solo lectura** `backend/scripts/phase3-neon-persistent-company-verify.php`. Comprobará la configuración persistida, PCGE operativo a seis dígitos, membresía ADMIN, RLS y conservación de los datos preexistentes.
- **F3.3 validada manualmente el 23/10/2026:** `php -l` sin errores; `--plan` correcto; `--verify=contabilidad-dev` PASS: empresa ID 4 con ADMIN, settings, monedas, CIIU, tributos, áreas, flujos, auditoría, **2.966 cuentas (1.214 operativas de seis dígitos)**, RLS bloquea lectura de empresa ajena, **0 asientos**, baseline preservado. Esta verificación es de solo lectura.
- **No cerrar F3 global:** el criterio de salida sigue incluyendo crear empresa y registrar su primer asiento por el flujo autorizado, sin SQL manual. Sigue pendiente rendimiento de onboarding y coherencia de macrosector/actividad, además del alcance de maestros y SUNAT.
- Mantener el conteo del checklist como fuente única: este ensayo añade cobertura, **no aprueba automáticamente una actividad general**.

---

## Validación F3.4 — 23/10/2026

- **PASS manual Neon DEV:** `php -l scripts/phase3-neon-first-journal-http.php`, `--plan` y `--probe=contabilidad-dev`.
- Empresa persistente ID 4: apertura HTTP 201 del período; POST HTTP 201 de primer asiento bimoneda, idempotencia 200/409; una auditoría y un recibo, constraints comprobadas.
- **ROLLBACK confirmado:** sin persistir períodos, asientos, tipos de cambio, recibos ni auditorías nuevos. Baseline preservado.
- **Alcance:** validación transaccional reversible del primer asiento. No implica que el primer asiento esté persistido ni que F3 global esté cerrada. Pendientes: prueba persistente autorizada desde interfaz/flujo normal, revisión de coherencia sector/CIIU, completar maestros y optimizar alta PCGE.
- Este hito no modifica automáticamente el contador oficial de 32 actividades del checklist.

---

## F3.5 — Primer asiento ficticio persistente (preparado, no ejecutado)

- Publicado `backend/scripts/phase3-neon-first-journal-persist.php` para reutilizar el contrato HTTP validado F3.4 con comando explícito `--apply=contabilidad-dev`.
- `--plan` no conecta a la base ni escribe datos. `--apply=contabilidad-dev` **confirma (COMMIT)** una apertura de período octubre 2026, un asiento PEN/USD en empresa 4, tipo de cambio, recibo idempotente y auditoría, tras comprobar las restricciones y respuesta HTTP. Nunca ejecutar en producción.
- Antes de iniciar, exige empresa 4 activa con ADMIN, cero asientos y sin período octubre 2026. Tras escribir, verifica diferencias contra el baseline. En errores previos al COMMIT ejecuta rollback. Si falla la comprobación después del COMMIT, **no reintentar** sin inspeccionar los datos.
- **No está probado aún en el entorno local Neon.** La persistencia requiere `php -l` y ensayo manual. La prueba anterior F3.4 continúa siendo reversible.
- La prueba no certifica la interfaz Vue ni todos los maestros; la puerta F3 global permanece abierta.

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

### FASE 7 — Estados financieros, NIIF, financiamiento y activos fijos (9 semanas orientativas; alcance ampliado)

**Documento normativo-funcional vinculante para esta fase:** [Plan integral F7 — NIIF 18, NIIF para PYMES, NPIF, NIIF 9 y activos fijos con tratamiento tributario peruano](docs/F7-PLAN-INTEGRAL-NIIF-NPIF-PRESTAMOS-ACTIVOS-FIJOS.md). **Estado: PLANIFICADO, no implementado.** El alcance adicional requiere reestimar la duración antes de iniciar F7.

**Objetivo:** producir EEFF consistentes, registrar préstamos y activos a lo largo de su ciclo completo, y conciliar tratamiento contable-financiero versus tributario peruano.

**Bloque A — Marcos contables y presentación:** determinar por empresa/ejercicio si corresponde NIIF completas, NIIF para las PYMES (ediciones 2015/2025) o NPIF peruana, con sustento de ingresos/UIT y resoluciones del CNC. Preparar NIIF 18 para periodos aplicables (vigencia internacional 2027), cinco categorías de resultados y subtotales, excepciones por actividad principal, comparativos, medidas gerenciales y notas; sin imponer NIIF 18 sobre NIIF PYMES/NPIF. NIIF 19 solo para subsidiarias elegibles.

**Bloque B — Préstamos e instrumentos:** NIIF 9 (o sección equivalente según marco): principal contractual, comisión de desembolso, flujo neto, costo amortizado y tasa de interés efectiva/TIR, periodos de gracia, interés compuesto, cronograma contractual versus contable, pagos, renegociaciones, asientos PCGE, NIC 21, revelaciones y tratamiento tributario de gastos financieros.

**Bloque C — Módulo integral de activos fijos y obra en curso:** expediente documental SUNAT (tipo, serie y correlativo), ficha y componentes, contratos, presupuestos, compras, materiales, mano de obra, valorizaciones, avances y puesta en uso; depreciación financiera NIC 16 y tributaria SUNAT separadas; mejoras vs mantenimiento, cambios de vida/residual, revaluaciones, deterioros, bajas, ventas, leasing, propiedad de inversión e intangibles cuando corresponda; NIC 23, 36, 37, 40, 38, NIIF 16, 5, 13, etc., según el hecho económico.

**Bloque D — Fiscalidad y reporte:** bases contables/fiscales por activo, diferencias temporarias e impuesto diferido NIC 12, IGV, deducibilidad, tasas y límites versionados, Registro de Activos Fijos SUNAT (formatos aplicables, incluido 7.1), trazabilidad CUO, asientos y conciliación a libros/EEFF.

**Implementación incremental F7.0–F7.6:** marcos normativos -> activos básicos -> construcciones -> operaciones posteriores -> impuesto diferido y registro SUNAT -> préstamos NIIF 9 -> EEFF NIIF 18 y versiones PYME/NPIF. Las subtareas, normas aplicables, estructuras conceptuales, pruebas y fuentes oficiales constan en el [anexo F7](docs/F7-PLAN-INTEGRAL-NIIF-NPIF-PRESTAMOS-ACTIVOS-FIJOS.md).

**Entregable:** EEFF conciliados, cronogramas de crédito, auxiliares de activo contable/fiscal, impuesto diferido y reportes SUNAT trazables.

**Puerta F7:** EEFF = libros; préstamo = flujos y TIE reproducibles; activos = costos y depreciación financiera/fiscal auditables; obra en curso sin depreciación financiera antes de disponibilidad; NIC 12 y Registro de Activos Fijos conciliados; pruebas multiempresa A/B aprobadas.

### FASE 8 — QA integral, rendimiento, preview y preparación productiva (4 semanas)

**Objetivo:** liberar una versión candidata estable y respaldada por evidencia.

**Trabajo:**
- Ejecutar suites unitarias, integración, end-to-end, seguridad, aislamiento tenant, concurrencia y recuperación.
- Revisar índices, planes de ejecución, carga, límites, logs y alertas.
- Ejecución **manual** de pruebas, lint, typecheck y build antes de publicar; no se utilizan GitHub Actions.
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
| R13 | GitHub Actions descartado; verificación manual vigente | Baja | F8: workflows eliminados, run [39354337434](https://github.com/jwichpas/contabilidad/actions/runs/39354337434) sin runner ni pasos; ejecución manual hasta resolver disponibilidad |
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
- Evidencia enlazada (commit, salida de ejecución manual, captura, consulta de prueba o reporte) y responsable/revisor indicado.

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
- Actividades cerradas y evidencia (URL de commit/evidencia manual):
- Pruebas realizadas / resultados:
- Riesgos nuevos o mitigados:
- Bloqueos y decisiones:
- Próximo paso:
- Gate de salida aprobado por:
```

---

## 8. Hitos y puertas de calidad

| Hito | Alcance | Estado vigente (22/10/2026) | Condición verificable |
|---|---|---|---|
| **H1 — Base segura** | F0–F2 | ⏳ Pendiente: F1 validada, respaldo/restauración y F2.2/F2.3 restantes | Inventario, RLS/grants corregidos, aislamiento y contabilización exacta/concurrente probados |
| **H2 — Sistema operativo** | F3–F5 (núcleo) | ⏳ Pendiente: F3.2 aprobada, resto en desarrollo | Alta empresa + plan + asiento + Diario/Mayor/Balance + cierre sin operaciones manuales |
| **H3 — Cumplimiento tributario** | F5–F6 | ⬜ Pendiente | Libros y reportes electrónicos validados y conciliados |
| **H4 — Versión candidata** | F7–F8 | ⬜ Pendiente | EEFF/NIIF/AF comprometidos, tests integrales, preview y aceptación formal |

**Condición de desbloqueo:** cada hito requiere evidencias enlazadas y visto bueno explícito. El cronograma no reemplaza las puertas de calidad.

### Registro de aprobación (completar cuando suceda)

| Hito / Fase | Fecha de aprobación | Responsable | Evidencia | Estado |
|---|---|---|---|---|
| F0 | — | — | [Diagnóstico en progreso](docs/FASE-0-DIAGNOSTICO.md) | EN PROGRESO; pendiente de aprobación |
| F1 | 2026-10-22 (técnica) | Pruebas manuales | [F2.3 HTTP/RLS](docs/F2-3B-MOTOR-ANALITICO-POST.md) y [registro](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md) | 3/3 actividades verificadas en DEV; aprobación formal H1 pendiente |
| F2 | — | — | [F2.3b–h probadas](docs/F2-3B-MOTOR-ANALITICO-POST.md), [decimal](backend/app/Support/Math/Decimal.php) | 3/3 completas; F2.3 aprobada con HTTP Neon, doble sesión Docker y apertura auditada desde Vue |
| F3 | — | — | [Preflight matriz](backend/scripts/phase2-neon-analytic-promotion-preflight.php) | 1/3 completa; asistente y demás maestros pendientes |
| F4 | — | — | [Editor único](frontend/src/modules/accounting/views/JournalEntryView.vue) | En implementación; sin aprobación integral de UX |
| F5 | — | — | — | NO INICIADA |
| F6 | — | — | — | NO INICIADA |
| F7 | — | — | — | NO INICIADA |
| F8 | — | — | — | NO INICIADA |
| H1–H4 | — | — | — | PENDIENTES |

---

## 9. Orden de ejecución vigente (actualizado 22/10/2026)

**Se prioriza trabajo funcional y validación manual.** El motor contable cuenta con pruebas manuales PASS de HTTP 201/422/403, monedas PEN/USD, contabilización analítica 94/95/79, extornos, trazabilidad, RLS y aislamiento por empresa. F2.3b–h **no equivalen al cierre total de F2.3** porque está pendiente la operación de cierre de periodo.

1. **F3.1:** migraciones SUNAT 23/24 y alta real de empresa 4 (PCGE 2.966 cuentas, 1 auditoría, 2 tributos) verificadas en Neon. **Frontend PASS:** 18/18 unitarias, typecheck y build. Falta resolver rendimiento (último POST reversible 75,27 s, meta <25 s) y clasificar con actualización auditada su CIIU 4923 actualmente etiquetado COMERCIAL. Se publicó optimización de llamadas anidadas al mismo tenant con SAVEPOINT/RLS preservados y perfil SQL detallado; **pendiente revalidar, sin repetir alta real**. [Evidencia](docs/F3-1-ACEPTACION-ALTA-REAL-NEON.md).
2. Completar la revisión de **F3.1** (tiempo de alta y clasificación interna), continuar **F3.3** y **F4.2** con el editor único de asientos. Conservar íntegros los datos de la empresa ID 4 y los datos DEMO.
3. Completar F4 y F5: editor único, historial con partidas automáticas, Diario/Mayor/Balance, CxC/CxP y cierre.
4. Continuar F6 (SUNAT) y F7 (NIIF/NPIF/activos/préstamos) por módulos verificables.
5. Aplicar **pruebas manuales de regresión** con [el procedimiento aprobado](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md) antes de aceptación F8; **sin GitHub Actions**.

**Regla de avance:** modificar la casilla, la evidencia, el conteo y el estado de fase/hito en una misma edición del README. Ningún `--plan` ni resultado de sintaxis se considera por sí solo evidencia funcional.

---

## 10. Checklist de seguimiento

> **Estado reconciliado al 23/10/2026: 7/32 actividades aprobadas (22%).** El total incluye todas las casillas F0.1–F8.3, incluidas F6.4 y F7.0–F7.6. El antiguo indicador **0/27** era incorrecto: existían **32 casillas**, no 27. Cada `[x]` cuenta únicamente con evidencias del alcance completo; `[ ]` puede tener subpruebas PASS sin representar una aprobación global.

### Fase 0 — Diagnóstico y línea base
- [ ] **F0.1** Inventariar Supabase, grants/RLS y respaldo con restauración verificada. *Pendiente certificar restauración recuperable y custodia del backup.*
- [ ] **F0.2** Contrastar migraciones, código, módulos y estado funcional real. *Inventario realizado parcialmente; actualizar matriz funcional tras migración a Neon.*
- [ ] **F0.3** Ejecutar baseline de pruebas y revisar documentación normativa. *Pruebas puntuales realizadas; baseline completo y aceptación normativa pendientes.*

### Fase 1 — Seguridad
- [x] **F1.1** Corregir exposición pública, privilegios y políticas RLS sin bloquear backend. [Evidencia de privilegios/roles y RLS](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md). *Aprobada en Neon DEV; sin inferir seguridad de una producción no desplegada.*
- [x] **F1.2** Corregir contexto tenant transaccional, pooling y funciones privilegiadas. [Pruebas tenant/RLS bajo app_backend](docs/F2-3B-MOTOR-ANALITICO-POST.md). *Contexto por transacción y conexión directa usados en ensayos aprobados.*
- [x] **F1.3** Probar separación entre empresas, roles y permisos. [HTTP 403 y RLS ACTIVE simultáneo en Docker PG18](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md). *A/B aisladas; auditor sin membresía/permiso.*

### Fase 2 — Motor contable
- [x] **F2.1** Eliminar punto flotante del circuito monetario y validar precisión. [Decimal PHP](backend/app/Support/Math/Decimal.php), [pruebas manuales PEN/USD y partida doble exacta](docs/F2-3B-MOTOR-ANALITICO-POST.md). *Importes DECIMAL 4 y TC 6; cálculos frontend con BigInt.*
- [x] **F2.2** Implementar numeración segura, idempotencia y atomicidad. **APROBADO:** Docker PostgreSQL 18 con 20 migraciones, concurrencia entre conexiones app_backend, HTTP 201/200/409, clave y recibo inmutables FORCE RLS; migración 22 en Neon DEV, HTTP real Sanctum/app_backend (1 recibo, 1 asiento, 1 auditoría), extorno y rechazo de duplicados, más ROLLBACK con cero movimientos/recibos persistidos. Vue: 16/16 tests, typecheck y build PASS. [Evidencia](docs/F2-2-IDEMPOTENCIA-POST.md).
- [x] **F2.3** Validar POST, extornos, cierre y controles de partida doble. **APROBADO:** POST/extorno idempotente y RLS por empresa; HTTP real Neon OPEN→CLOSED→OPEN→CLOSED→LOCKED (403 roles, 409 transiciones, 422 POST/extorno bloqueados y auditorías) con rollback; carreras CLOSE-first y POST-first con dos conexiones PostgreSQL 18 en Docker, NOWAIT 55P03 y asientos balanceados; Vue compila y muestra estado y acciones. **E2E real:** apertura de enero 2026 para DEMO A desde la interfaz, verificada por lectura directa en Neon (OPEN, 0 asientos, 1 auditoría). **RLS app_backend:** DEMO B ve 0, DEMO A ve 1. [Evidencias](docs/F2-3-CIERRE-PERIODOS.md).

### Fase 3 — Empresas y maestros
- [ ] **F3.1** Asistente de creación y configuración fiscal de empresas. **Alta real HTTP 201 y wizard Vue: PASS funcional.** Empresa 4, ADMIN, PEN/USD, CIIU 4923, tributos 1011/3031, PCGE 2.966 cuentas (1.214 operativas), seis áreas, nueve flujos NIC 7 y auditoría. SUNAT RUC y reglas de alta: 6 pruebas / 537 aserciones; Vue 18/18, typecheck y build PASS. Último ensayo HTTP reversible: **17,43 s / 52 sentencias SQL** (objetivo orientativo de 25 s alcanzado en esta medición). **Para marcar la casilla:** corregir y auditar el sector almacenado COMERCIAL, incompatible con el CIIU 4923 de transporte; corroborar la configuración fiscal resultante. [Historial y evidencia de F3.1](docs/F3-1-ACEPTACION-ALTA-REAL-NEON.md).
- [x] **F3.2** Consolidar plantillas PCGE y cuentas operativas parametrizadas. *Neon DEV: 3.401 plantillas (PCGE 2019/2026), 2.966 cuentas y 1.214 operativas de seis dígitos por empresa; [verificaciones](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md).*
- [ ] **F3.3** Completar maestros, dimensiones y empresas ficticias. *Existen 4 empresas persistentes (3 DEMO + ID 4); PCGE, áreas y monedas iniciales listos. Maestros sectoriales, dimensiones completas, historia tributaria por ejercicio y aceptación integral de escenarios pendientes.*

### Subhitos técnicos de Fase 3 (no agregan casillas al total 32)

> Las casillas F3.1–F3.3 son **actividades del Plan Maestro**. Los nombres F3.4 y F3.5 son **hitos de pruebas técnicas** que antes solo aparecían fuera de este checklist. Igualmente, la prueba de seguridad llamada F3.2 no sustituye a la actividad F3.2 de PCGE, ni el ensayo F3.3 sustituye al cierre de maestros.

| Hito técnico | Validación al 23/10/2026 | Evidencia y alcance |
|---|---|---|
| F3.1 · Onboarding HTTP | **PASS funcional** | HTTP 201 con alta real ID 4; último probe reversible **17,43 s / 52 SQL**; falta corrección auditable de sector de la empresa existente |
| F3.2 · Aislamiento TenantContext | **PASS** | PHPUnit Feature filtro Tenant: **19/19 tests, 78 aserciones**, RLS, roles, FK compuesta, savepoints |
| F3.3 · Verificación empresa persistente | **PASS** | phase3-neon-persistent-company-verify.php: **2.966 cuentas / 1.214 operativas**, auditoría y aislamiento; se ejecutó antes de persistir el asiento |
| **F3.4 · Primer asiento HTTP reversible** | **PASS** | phase3-neon-first-journal-http.php: período 201, POST 201, idempotencia 200/409, constraints, auditoría y **ROLLBACK sin escrituras persistidas**. No repetir sobre empresa 4 tras F3.5 |
| **F3.5 · Primer asiento HTTP persistente** | **PASS / COMMIT** | phase3-neon-first-journal-persist.php: **asiento ID 52, empresa 4**, un período octubre 2026, un FX, un recibo, dos auditorías; HTTP 201/200/409, POST-COMMIT PASS. No reejecutar |

**Puerta F3 global aún abierta:** el primer asiento está persistido y comprobado; faltan reconciliación fiscal sector/CIIU, maestros y escenarios sectoriales integrales. Estos PASS no elevan por sí solos la cuenta de actividades completas.

### Fase 4 — Interfaz
- [ ] **F4.1** Separar layouts, navegación y selector global de empresa. *Implementación avanzada; revisar UX y flujos de navegación integrados.*
- [ ] **F4.2** Sustituir formularios repetidos por editor único de asientos. *Editor único presente en Vue; pendiente aceptación visual y de edición de varias partidas.*
- [ ] **F4.3** Mejorar tablas, validaciones, accesibilidad y responsive.

### Fase 5 — Gestión contable
- [ ] **F5.1** Consolidar Diario, Mayor y Balance de Comprobación.
- [ ] **F5.2** Completar CxC, CxP, bancos y conciliación.
- [ ] **F5.3** Validar cierres, reconciliación y reportes PDF/Excel.

### Fase 6 — SUNAT
- [ ] **F6.1** Versionar catálogos SUNAT y generar archivos PLE aplicables. *Catálogos globales versionados; archivos y aceptación pendientes.*
- [ ] **F6.2** Incorporar registros RCE/RVIE y flujos de conciliación SIRE.
- [ ] **F6.3** Validar formatos, duplicidades, observaciones y conciliación.
- [ ] **F6.4** Implementar y probar SFE/SFMB (exportaciones bienes/servicios y empresas mixtas): origen del crédito, aplicación a IGV local, límites/arrastres, PDB, compensación/devolución y trazabilidad. [Documento de diseño](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md).

### Fase 7 — NIIF, NPIF, préstamos y activos fijos

> Las **siete** actividades F7.0–F7.6 **sí forman parte** del denominador vigente de 32 actividades. [Especificación](docs/F7-PLAN-INTEGRAL-NIIF-NPIF-PRESTAMOS-ACTIVOS-FIJOS.md).

- [ ] **F7.0** Motor de selección de marco: NIIF completas / NIIF PYMES 2015-2025 / NPIF, por empresa, ejercicio y norma peruana aplicable.
- [ ] **F7.1** Activos fijos: altas, expedientes SUNAT, componentes, depreciación financiera NIC 16 y tributaria independiente.
- [ ] **F7.2** Construcciones en curso: contratos, costos, mano de obra, NIC 23 cuando corresponda y puesta en uso.
- [ ] **F7.3** Mejoras, cambios de estimaciones, bajas, ventas, deterioro, revaluación, arrendamiento y normas conexas.
- [ ] **F7.4** NIC 12 y fiscalidad peruana: diferencias temporarias, impuesto diferido, Registro de Activos Fijos y conciliaciones.
- [ ] **F7.5** Préstamos NIIF 9: costo amortizado, TIR/TIE, comisión, gracia, cronograma, devengos y asientos.
- [ ] **F7.6** EEFF NIIF 18 con categorías y subtotales, revelaciones y reportes alternativos NIIF PYMES/NPIF.

### Fase 8 — Liberación
- [ ] **F8.1** Completar pruebas y optimización de rendimiento/seguridad.
- [ ] **F8.2** Ejecutar verificación **manual** de pruebas, compilación, migraciones y recuperación; **sin GitHub Actions**. [Procedimiento](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md).
- [ ] **F8.3** Validar preview, documentación y aceptación final.

### Resumen vigente de seguimiento

| Indicador | Estado real (23/10/2026) | Evidencia / siguiente control |
|---|---|---|
| Actividades aprobadas | **7/32 (22%)** | Contar únicamente `[x]` de esta sección |
| Actividades pendientes | **25/32 (78%)** | Incluyen subtareas parcialmente desarrolladas |
| Fase de seguridad F1 | **3/3 con validación técnica en DEV** | Pendiente aprobación formal del hito H1 |
| Fase del motor F2 | **3/3 completas**; **F2.3 aprobado** | HTTP, concurrencia Docker, RLS y apertura real con auditoría verificados |
| Fase de empresas F3 | **1/3 completa** | F3.2 PCGE aprobada; alta funcional y F3.4/F3.5 pruebas PASS; F3.1 aún espera corregir sector/CIIU y F3.3 maestros pendientes |
| Fases formalmente aprobadas | **0/9** | Hitos y aceptación explícita de sección 8 |
| Hitos aprobados | **0/4** | H1–H4 requieren puertas completas |
| Base de desarrollo | **Neon PG18**; 24 migraciones; 4 empresas; 9 membresías; 3 DRAFT; 0 ACTIVE; enero DEMO A OPEN, octubre empresa 4 OPEN, **asiento 52 persistido** | Evidencia F3.5 POST-COMMIT del 23/10/2026 |
| Modalidad de pruebas | **Manual exclusivamente** | [Guía y evidencias](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md) |
| Próximo trabajo funcional | **Corregir macrosector/CIIU con auditoría → maestros F3.3 → UX/UI** | F3.5 persistente PASS; optimización F3.1 17,43 s. No repetir ensayos de primer asiento en empresa 4 |

### Evidencias que justifican las siete actividades aprobadas

| Actividad | Validación |
|---|---|
| F1.1 | ACL/rol de aplicación, RLS y aislamiento: [ensayos manuales](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md) |
| F1.2 | Aislamiento transaccional tenant, conexión de roles y FORCE RLS: [F2.3c/d/h](docs/F2-3B-MOTOR-ANALITICO-POST.md) |
| F1.3 | HTTP 403 y consultas multiempresa protegidas: [F2.3d/h](docs/F2-3B-MOTOR-ANALITICO-POST.md) |
| F2.1 | Matemática exacta bimoneda mediante Decimal/BigInt y triggers: [F2.3b/h](docs/F2-3B-MOTOR-ANALITICO-POST.md) |
| F2.2 | Numeración segura, idempotencia HTTP 201/200/409: [F2.2](docs/F2-2-IDEMPOTENCIA-POST.md) |
| F2.3 | POST, extornos, períodos y bloqueos con pruebas de concurrencia: [F2.3](docs/F2-3-CIERRE-PERIODOS.md) |
| F3.2 | PCGE global 3.401; por compañía 2.966, de ellas 1.214 operativas; 3 matrices de 454 rutas: [preflight F2.3f](docs/VALIDACION-MANUAL-Y-EVIDENCIAS.md) |

**Política documental:** actualizar la casilla, evidencia, conteos y estado en **una sola edición**. Los ensayos F3.4/F3.5 figuran en una tabla de subhitos pero no se suman a las 32 casillas. Los avances cronológicos antiguos están en [el archivo histórico](docs/HISTORIAL-PLAN-MAESTRO-2026-10.md) y no indican el estado actual.

---

## 11. Referencias y control documental

- Código y arquitectura actual: `backend/`, `frontend/`, `backend/database/migrations/`, `backend/tests/`, `README.md`.
- **Política obligatoria de esquema Laravel:** [docs/ARQUITECTURA-BD-LARAVEL.md](docs/ARQUITECTURA-BD-LARAVEL.md).
- **Matriz funcional F0:** [docs/FASE-0-MATRIZ-FUNCIONAL.md](docs/FASE-0-MATRIZ-FUNCIONAL.md).
- **Requerimiento vinculante empresa/sector/libros SUNAT:** [docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md](docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md).
- **Preflight y respaldo F0:** `php backend/scripts/phase0-preflight.php` y `backend/scripts/phase0-backup.sh` (este último no ejecutado aún).
- **Comando de diagnóstico F0:** `php artisan contabilidad:auditar-fase0` (solo lectura; sin mutaciones).
- Referencias internas: `plan-contable-2019.md`, `plan-contable-2026.md`, `tablas-sunat.md`.
- Guías oficiales a comprobar en F0: [SUNAT](https://www.sunat.gob.pe/), [MEF — Consejo Normativo de Contabilidad](https://www.gob.pe/mef), [Supabase RLS](https://supabase.com/docs/guides/database/postgres/row-level-security), [Laravel](https://laravel.com/docs/11.x).
- **Control de versiones:** **v2.1 — 2026-10-23 — reconciliación checklist 7/32, pruebas F3.4/F3.5 y asiento ID 52 persistido, evidencia de rendimiento 17,43 s**; **v2.0 — 2026-10-22 — checklist reconciliado 5/32, F2.3h certificado, prueba manual sin GitHub Actions, archivo histórico**; v1.7 — 2026-10-19 — Control estricto de decimales y credenciales de migración; v1.6 — 2026-10-19 — Cambios funcionales de seguridad y precisión en Laravel/Vue; v1.5 — 2026-10-19 — Preflight, respaldo no destructivo y matriz F0; v1.4 — 2026-10-19 — Laravel como único gestor de esquema y auditoría F0 read-only; v1.3 — 2026-10-19 — Workflow CI estático y riesgo runner; v1.2 — 2026-10-19 — Política de base única y F0 SQL; v1.1 — 2026-10-19 — Inicio F0 y enlace de diagnóstico; v1.0 — 2026-10-19 — plan aprobado para documentación previa a Fase 0. Los ajustes futuros deben registrar fecha, motivo y relación con decisiones/hallazgos.

**Estado vigente 2026-10-23:** ver [sección 10](#10-checklist-de-seguimiento): **7/32 (22%)**, asiento de prueba ID 52 persistido en Neon DEV; F3.4/F3.5 PASS, modalidad de pruebas manual y 0/4 hitos formalmente aprobados. [Historial del estado al 19/10](docs/HISTORIAL-PLAN-MAESTRO-2026-10.md).
