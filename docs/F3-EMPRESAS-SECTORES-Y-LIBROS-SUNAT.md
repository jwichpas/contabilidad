# Fases 3, 5 y 6 — Perfil de empresa y determinación de libros SUNAT (Perú)

> **Documento de requerimientos aprobado para planificación; implementación PENDIENTE.**
> Proyecto: `jwichpas/contabilidad` · BD: `contabilidad-database` · Rama: `main`.
> Fuente rectora: [README-PLAN-MAESTRO.md](../README-PLAN-MAESTRO.md).
> **No se ejecutó ninguna migración ni se alteró la base al redactar este documento.**

## 1. Diagnóstico verificado en el código y PostgreSQL (2026-10-19)

- `company_settings.tax_regime` **ya existe** en Laravel, Vue y la base PostgreSQL, con opciones actuales `GENERAL`, `MYPE_TRIBUTARIO`, `RER` y `ESPECIAL`. La última opción es ambigua y requiere normalización; `RER` ya significa Régimen Especial de Renta.
- `company_settings.is_sire_obliged` e `is_ple_obliged` existen como **booleanos manuales**, no como determinación legal verificable por periodo.
- `companies` contiene RUC, razón social, domicilio y estado. **No tiene** naturaleza legal, rubro económico, sector, CIIU ni actividad económica principal/secundaria.
- `frontend/src/modules/company/views/CompanySettingsView.vue` incluye selector de régimen en los parámetros tributarios, pero **no hay un wizard completo** de actividad económica y obligaciones por ejercicio.
- **No hay un motor identificado** que determine automáticamente libros contables por combinación de ingresos anuales, régimen y ejercicio, ni una tabla histórica de sus obligaciones.

Esta evidencia no equivale a aprobación funcional del módulo.

## 2. Cuatro dimensiones independientes del perfil

| Dimensión | Campo o concepto | Ejemplos y reglas |
|---|---|---|
| Forma/naturaleza jurídica | `legal_person_type` / `legal_form` | Persona natural con negocio, EIRL, S.A.C., S.R.L., S.A. y otras; el NRUS no corresponde a personas jurídicas |
| Régimen tributario | `tax_regime` con historia de vigencias | Nuevo RUS (NRUS, solo cuando sea legalmente elegible), RER, Régimen MYPE Tributario, Régimen General |
| Segmento/actividad | sector macro, subsegmento, CIIU Rev. 4 | Comercial, servicios, industrial/manufactura, agricultura, ganadería, pesca, construcción, minería/extractiva, transporte/logística, otras |
| Perfil contable y obligaciones | ejercicio, ingresos, UIT anual, registros y fechas | Libros obligatorios, SIRE/PLE, presentación, contabilidad financiera, NIIF y reglas sectoriales |

**Nunca inferir el régimen tributario del sector**; por ejemplo, una empresa comercial e industrial podría estar en RMT o Régimen General dependiendo de su situación legal y tributaria. **Nunca inferir obligaciones contables solo del régimen**, pues importan ingresos, fecha y actividad.

## 3. Sectores y segmentación funcional

Crear un catálogo versionado de sectores con identificador estable, descripción y subsegmentos; no usar texto libre como único identificador:

- **COMERCIAL:** minorista, mayorista, importación/exportación, distribución.
- **SERVICIOS:** profesionales, consultoría, mantenimiento, tecnología, otros.
- **INDUSTRIAL:** manufactura, transformación, fabricación, costos por procesos/órdenes (integración futura).
- **AGROPECUARIO:** agrícola, **ganadería**, avicultura y otros; evaluar NIC 41/NIIF para PYMES según aplicabilidad.
- **CONSTRUCCIÓN:** contratistas, inmobiliario y proyectos de obra.
- **EXTRACTIVO:** minería, hidrocarburos, pesca y otros.
- **TRANSPORTE_LOGISTICA:** transporte de carga, pasajeros, logística.
- **OTROS / ACTIVIDAD_MIXTA:** permitir una o varias actividades CIIU; distinguir actividad principal de secundarias.

El sector **personaliza sugerencias** (centros de costos, cuentas auxiliares, ejemplos, libros complementarios y reglas de costos) pero **no crea automáticamente un tratamiento fiscal o contable incorrecto**. NIIF completas versus NIIF para PYMES se determina por elegibilidad/obligación, no solamente por segmento.

## 4. Matriz inicial de libros tributarios — para parametrizar, no hardcodear

Resumen operativo de las guías oficiales SUNAT; comprobar fecha de obligatoriedad, monto de UIT del ejercicio, clases de ingresos y normas especiales antes de activar una regla.

| Régimen | Tramo de ingresos anuales | Libros y registros generales mínimos |
|---|---|---|
| NRUS | Contribuyente elegible | **No** obligación de libros contables; conservar comprobantes según ley |
| RER | Dentro de sus límites y exclusiones | Registro de Compras; Registro de Ventas e Ingresos |
| RMT | Hasta 300 UIT | Registro de Compras; Registro de Ventas; Libro Diario de Formato Simplificado |
| RMT | Más de 300 hasta 500 UIT | Registro de Compras; Registro de Ventas; Libro Diario; Libro Mayor |
| RMT | Más de 500 hasta 1,700 UIT | Registro de Compras; Registro de Ventas; Libro Diario; Libro Mayor; Libro de Inventarios y Balances |
| Régimen General | Hasta 300 UIT | Registro de Compras; Registro de Ventas; Libro Diario de Formato Simplificado |
| Régimen General | Más de 300 hasta 500 UIT | Registro de Compras; Registro de Ventas; Libro Diario; Libro Mayor |
| Régimen General | Más de 500 hasta 1,700 UIT | Registro de Compras; Registro de Ventas; Libro Diario; Libro Mayor; Libro de Inventarios y Balances |
| Régimen General | Más de 1,700 UIT | **Contabilidad completa** (incluye, entre otros, Caja y Bancos, Inventarios y Balances, Diario, Mayor, Compras y Ventas) |

**Advertencias legales:**
- El RMT está dirigido a contribuyentes cuyos **ingresos netos anuales** no superen 1,700 UIT y tiene exclusiones (entre otras, por vinculación); no es una simple selección libre.
- Las normas y guías de libros hacen referencia a **ingresos brutos** para determinados tramos; registrar por separado métricas de ingresos brutos/netos y el año de referencia.
- La frontera de 300 UIT se describe con formulaciones diferentes en algunas guías; implementar límites exactos contrastando normas vigentes en vez de copiar un rótulo de una imagen.
- Además de los libros básicos, pueden corresponder **Registro de Activos Fijos, Registro de Costos, inventarios y otros auxiliares** de acuerdo con operaciones, requisitos y vigencia.
- **SIRE (RCE/RVIE) y PLE** son medios/obligaciones con calendarios propios: no equivalen a tipos de libros diferentes. No marcar automáticamente SIRE para toda empresa desde su creación.
- Permitir voluntariamente reportes más completos que el mínimo tributario, sin confundirlos con obligaciones legales.
- No utilizar la imagen compartida como fuente normativa; se contrastaron normas oficiales SUNAT.

**Fuentes oficiales (verificar versión antes de publicar reglas a producción):**
- [SUNAT — Libros y registros contables obligatorios](https://emprender.sunat.gob.pe/comprobantes-libros/registros-libros-electronicos/libros-registros-contables-obligatorios).
- [SUNAT — Régimen General](https://emprender.sunat.gob.pe/ruc/regimenes-tributarios-mype/regimen-general-renta-rg).
- [SUNAT — Régimen MYPE Tributario](https://emprender.sunat.gob.pe/ruc/regimenes-tributarios-mype/regimen-mype-tributario).
- [SUNAT — Comparación de regímenes](https://emprender.sunat.gob.pe/ruc/regimenes-tributarios-mype/regimenes-tributarios).
- [Decreto Legislativo N.º 1269](https://www.sunat.gob.pe/legislacion/mypeIR/dl1269.pdf).

## 5. Arquitectura propuesta para Laravel (a implementar en F3/F5/F6)

**F3 — Datos maestros y wizard:**
1. Agregar mediante **nueva migración Laravel** los datos legales que faltan, preservando `company_settings.tax_regime` existente para retrocompatibilidad.
2. Catálogo `economic_sectors` y asociación `company_economic_activities`: `company_id`, sector/subsector, CIIU Rev. 4, principal/secundario, vigencia.
3. Historial de regímenes `company_tax_regime_periods`, con empresa, código normalizado, `valid_from`, `valid_until`, motivos y auditoría. Reconciliar `RER` y el alias `ESPECIAL`; no duplicar regímenes.
4. Perfil anual `company_fiscal_obligation_profiles`: ejercicio, ingresos brutos y netos **decimales exactos**, UIT oficial del ejercicio, fuente, fecha de cálculo y versión normativa.
5. Formularios Laravel/Vue que validen elegibilidad del régimen y completen: identificación → forma jurídica → sector/CIIU → régimen → ejercicio/ingresos → sugerencia de libros → monedas/PCGE → confirmación.
6. Mantener `company_id`, RLS y permisos de `app_backend` en nuevas tablas mediante **migraciones Laravel**, nunca con DDL manual en Supabase.

**F5/F6 — Motor de obligaciones y libros:**
1. Catálogo `accounting_book_types`: diario simplificado, diario, mayor, inventarios/balances, compras, ventas, caja/bancos, AF, costos, etc.; formato SUNAT cuando aplique.
2. `tax_book_obligation_rules` **versionada** por vigencia, régimen, ingresos, actividad/casos adicionales y fuente legal.
3. `company_book_obligations` con periodo, origen automático/manual justificado, obligatoriedad, formato y medio (físico/electrónico, PLE, SIRE), fecha desde/hasta, estado y trazabilidad.
4. Motor que devuelve **obligatorio / opcional / no aplica / pendiente de validar**, explicando por qué y citando resolución/regla.
5. SIRE/PLE con obligatoriedad desde fecha y procedimiento correspondientes, **sin un mero checkbox como fuente única de verdad**.
6. Reportes contables completos que puedan generarse aunque no todos sean obligatorios para un contribuyente específico.

**Reglas de seguridad/migración:** migraciones de Laravel mediante `php artisan migrate --database=pgsql_migrations`, comprobación de los datos previos, respaldo verificable, compatibilidad de API y pruebas tenant. No modificar tablas directamente desde Supabase.

## 6. Ejemplos de aceptación obligatorios

- Persona natural NRUS elegible: **sin libros tributarios obligatorios**, con advertencia de que puede no requerir el sistema de contabilidad completo.
- Empresa comercial RER: Compras + Ventas, sin forzar Libro Mayor como requisito tributario.
- Empresa de servicios RMT con ingresos hasta 300 UIT: Diario Simplificado y Compras/Ventas según las reglas aplicables.
- Empresa industrial RG entre 500 y 1,700 UIT: los cinco libros generales del tramo **más** validación de obligación adicional del Registro de Costos si corresponde.
- Empresa ganadera RG: misma base tributaria que otros sectores comparables, con configuración ganadera/NIIF/NIC 41 que corresponda, sin inferir automáticamente una exoneración.
- Empresa RG con ingresos superiores a 1,700 UIT: contabilidad completa y comprobación de obligaciones adicionales.
- Cambio de régimen a mitad de ejercicio o nueva obligación SIRE: **preservar historial y fecha efectiva**, sin reescribir ejercicios cerrados.
- Empresa con actividades comercial y transporte: conservar sector principal y actividad secundaria, sin forzar una única actividad.
- Pruebas en **dos empresas**: no mezclar reglas, ingresos ni libros y confirmar aislamiento tenant.

## 7. Estado de ejecución

**Implementado hoy:** columna `tax_regime`, controles manuales SIRE/PLE y configuración tributaria básica.  
**Pendiente:** forma jurídica, sectores/CIIU, perfiles anuales, históricos tributarios, reglas versionadas de libros, detección de obligatoriedad, wizard y pruebas.  
**Planificación:** F3 para clasificación y perfil; F5 para generar libros; F6 para libros electrónicos/SUNAT, conciliaciones y vigencias.  
**No se han creado ni modificado tablas al incorporar esta especificación.**
