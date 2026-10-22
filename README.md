> ✅ **Plataforma ERP objetivo (21/10/2026): PHP 8.5.12, Laravel 13.38.0, Pest 4.7.8 y PostgreSQL 18.** El usuario validó PostgreSQL **18.6** sobre Docker desechable: migraciones, RLS, funciones de auditoría y **12 pruebas / 37 aserciones PASS**. PostgreSQL 18 quedó como versión predeterminada en Docker y GitHub Actions (CI real todavía pendiente). **Supabase `contabilidad-database` permanece en PostgreSQL 17.11 exclusivamente para desarrollo**, sin upgrade ejecutado; PostgreSQL 18 es el objetivo para la base productiva futura. El bloqueo anterior de migraciones Dev por `sslmode`/configuración administrativa está pendiente de resolver. [Decisión de arquitectura](docs/POSTGRESQL-18-ARQUITECTURA.md) · [Plan PHP/Laravel](docs/ACTUALIZACION-PHP-85-LARAVEL-13.md).

> ✅ **Fase 0 — restauración de desarrollo comprobada (20/10/2026):** se verificó SHA256 y restauración aislada de `public` sobre PostgreSQL 17, conteos de 7 entidades, 14 tablas con RLS forzado, 5 funciones y eliminación del contenedor. **No es una recuperación completa de Supabase ni una certificación de producción.** El usuario aplazó el cifrado de respaldos hasta producción (disco C: sin BitLocker). [README de cierre condicionado y tareas previas a producción](docs/README-FASE-0-CIERRE-Y-PRODUCCION.md). Se puede comenzar Fase 1 mientras se atienden las observaciones de seguridad restantes.

<div align="center">

# Sistema de Gestión Contable y Financiero Bimonetario (ERP Clean Room)

### Transaccionalidad de Alta Precisión · Normativa Oficial SUNAT, PCGE 2026 y NIIF 18 / NIIF 19
**Aislamiento Multi-Tenant de Dos Capas con PostgreSQL Row Level Security (RLS) y Tipado Numérico Fuerte Anti-Flotantes.**

<br />

<p align="center">
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-13.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 13" /></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.5-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.5" /></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue.js-3.5-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3" /></a>
  <a href="https://www.typescriptlang.org"><img src="https://img.shields.io/badge/TypeScript-5.8-3178C6?style=for-the-badge&logo=typescript&logoColor=white" alt="TypeScript" /></a>
  <a href="https://supabase.com"><img src="https://img.shields.io/badge/Supabase-PostgreSQL_15+-3ECF8E?style=for-the-badge&logo=supabase&logoColor=white" alt="Supabase PostgreSQL" /></a>
  <a href="https://tailwindcss.com"><img src="https://img.shields.io/badge/Tailwind_CSS-v4-38B2AC?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS" /></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/SUNAT-PCGE_2026_%2F_2019-003366?style=flat-square" alt="PCGE 2026" />
  <img src="https://img.shields.io/badge/Normativa-NIIF_18_%2F_NIC_7-023e55?style=flat-square" alt="NIIF 18" />
  <img src="https://img.shields.io/badge/Seguridad-ISO%2FIEC_27001-blueviolet?style=flat-square" alt="ISO 27001" />
  <img src="https://img.shields.io/badge/Aislamiento-PostgreSQL_RLS-10b981?style=flat-square" alt="PostgreSQL RLS" />
  <img src="https://img.shields.io/badge/Precisión-BCMath_/_NUMERIC(14,4)-f59e0b?style=flat-square" alt="Precisión Numérica" />
  <img src="https://img.shields.io/badge/Licencia-MIT-22c55e?style=flat-square" alt="Licencia MIT" />
</p>

<p align="center">
  <a href="#-quick-start--instalación-rápida"><b>Quick Start</b></a> •
  <a href="#1-stack-tecnológico-y-arquitectura"><b>Arquitectura</b></a> •
  <a href="#2-principios-contables-inmutables-del-sistema"><b>Principios Contables</b></a> •
  <a href="#3-catálogos-y-parámetros-sembrados-en-base-de-datos"><b>Catálogos SUNAT</b></a> •
  <a href="#6-endpoints-principales-de-la-api-restful-apiv1"><b>API RESTful</b></a> •
  <a href="#7-sistema-de-gestión-de-seguridad-de-la-información-isoiec-270012023"><b>Seguridad ISO 27001</b></a>
</p>

<p align="center">
  <code>git clone https://github.com/jwichpas/contabilidad.git</code>
</p>

</div>

> 🗄️ **Política de base de datos:** [Laravel es el único gestor de migraciones y cambios de estructura](docs/ARQUITECTURA-BD-LARAVEL.md). Base única de desarrollo: `contabilidad-database`. Para diagnóstico sin escrituras: `php artisan contabilidad:auditar-fase0` desde `backend/`.

> 🔎 **Fase 0:** [Matriz funcional y módulos ya existentes](docs/FASE-0-MATRIZ-FUNCIONAL.md) · [Preflight estático](backend/scripts/phase0-preflight.php) · [Respaldo de desarrollo no destructivo](backend/scripts/phase0-backup.sh). **Las pruebas y el respaldo real continúan pendientes.**

> 📊 **Tributación de exportadores (diseño F6.4, no implementado):** [SFE y Saldo a Favor Materia del Beneficio — normativa SUNAT, empresas mixtas, límites, devoluciones y pruebas](docs/F6-IGV-SFE-SFMB-EXPORTADORES.md). El motor automático se desarrollará cuando existan documentos tributarios y conciliación de IGV.

> 🏢 **Fase 3 — Asistente Vue de alta implementado en código:** [cinco pasos para crear una empresa](frontend/src/modules/company/views/CompanyOnboardingWizard.vue), con API Laravel, revisión de RUC/CIIU, monedas y copia privada del PCGE. [Especificación y estado](docs/F3-EMPRESAS-SECTORES-Y-LIBROS-SUNAT.md). **Las migraciones y pruebas E2E siguen pendientes**; el cálculo automático de libros SUNAT aún no está implementado.

> **Estado Neon F2 verificado (22/10/2026 UTC):** 17 migraciones y 3 empresas DEMO; 6 áreas y 9 flujos por empresa probados con RLS. Las 3.401 plantillas PCGE y 13 conjuntos globales permanecen sin instalar. [Diagnóstico, comandos seguros y pendientes](docs/F2-CATALOGOS-NEON-ESTADO-VERIFICADO.md). No usar `db:seed` general.\n\n> 📌 **Plan maestro y seguimiento del proyecto:** [README-PLAN-MAESTRO.md](README-PLAN-MAESTRO.md) — 9 fases (F0–F8), hoja de ruta estimada, 4 hitos, criterios de aprobación y checklist de 27 actividades. **Fase 0 EN PROGRESO (sin tareas aprobadas).** Desarrollo exclusivamente sobre la base `contabilidad-database`, sin base aislada. [Diagnóstico y riesgos](docs/FASE-0-DIAGNOSTICO.md).

> ⚠️ **Seguridad de desarrollo:** La contraseña literal incluida anteriormente en `backend/phpunit.xml` fue retirada del archivo actual. **Falta rotarla** en Supabase y actualizar las conexiones; la contraseña anterior permanece potencialmente visible en el historial Git. No guardar contraseñas en el repositorio.

---

## ⚡ Quick Start / Instalación Rápida

### 1. Clonar el repositorio
```bash
git clone https://github.com/jwichpas/contabilidad.git
cd contabilidad
```

### 2. Configurar y levantar el Backend (Laravel 11)
```bash
cd backend
composer install
cp .env.example .env
# Configura las credenciales privadas de Neon contabilidad-dev en .env.
# NO ejecutar migrate general ni db:seed sobre la rama Neon ya inicializada.
# Antes de crear empresas: instalar los 13 catálogos globales versionados de F2.
php scripts/phase2-neon-global-reference.php --plan
php scripts/phase2-neon-global-reference.php --verify
# Únicamente después del preflight y con el entorno dev comprobado:
# php scripts/phase2-neon-global-reference.php --apply=contabilidad-dev
php artisan serve --port=8000
```

### 3. Configurar y levantar el Frontend (Vue 3 + Vite)
```bash
cd ../frontend
pnpm install
pnpm dev
# Accede a http://localhost:5181
```

---

## 1. Stack Tecnológico y Arquitectura

```
                        ┌────────────────────────────────────────────────────────┐
                        │      FRONTEND (Vue 3 + TypeScript + Tailwind CSS)     │
                        │  - Composition API (<script setup lang="ts">)          │
                        │  - Validación en tiempo real y contratos con Zod       │
                        │  - Paleta semántica Aiko (Reducción de fatiga visual)  │
                        │  - Tipado numérico C# (SystemDecimal, Int64, Int16)    │
                        └───────────────────────────┬────────────────────────────┘
                                                    │ REST API / JSON (Stateless)
                                                    ▼
                        ┌────────────────────────────────────────────────────────┐
                        │              BACKEND (Laravel 11 + PHP 8.3+)           │
                        │  - Single Action Controllers (Invokables)              │
                        │  - DTOs inmutables (final readonly class)              │
                        │  - Value Objects de Dominio (Decimal con BCMath)       │
                        │  - Autenticación Stateless con Laravel Sanctum         │
                        │  - Aislamiento Multi-Tenant: TenantScope (Eloquent)    │
                        └───────────────────────────┬────────────────────────────┘
                                                    │ Conexión PostgreSQL (PDO)
                                                    ▼
                        ┌────────────────────────────────────────────────────────┐
                        │             BASE DE DATOS (Supabase PostgreSQL 15+)    │
                        │  - Row Level Security (RLS) FORZADO por tenant         │
                        │  - Rol sin privilegios de bypass: app_backend          │
                        │  - Claves foráneas compuestas: (company_id, id)        │
                        │  - Disparadores e integridad jerárquica de 4 niveles   │
                        │  - Precisión exacta: NUMERIC(14,4) y NUMERIC(14,6)     │
                        └────────────────────────────────────────────────────────┘
```

### Base de Datos (PostgreSQL 15+ / Supabase)
- **Supabase como PostgreSQL administrado**: Laravel se conecta mediante el driver nativo `pgsql` (sin Edge Functions ni cliente JS en la lógica contable).
- **Aislamiento Multi-Tenant en Dos Capas**:
  1. *Capa Eloquent*: `TenantScope` (Global Scope) que inyecta automáticamente `WHERE company_id = ?` y falla cerrado (*fail-closed*) ante la ausencia de contexto de tenant activo.
  2. *Capa PostgreSQL (RLS)*: `ENABLE` y `FORCE ROW LEVEL SECURITY` en todas las tablas transaccionales y maestras discriminadas por `company_id`.
- **Rol de Aplicación Restringido (`app_backend`)**:
  - Creado explícitamente con `NOBYPASSRLS` y privilegios mínimos (`SELECT`, `INSERT`, `UPDATE`).
  - Prohibido el privilegio `DELETE` sobre tablas de journaling e historial contable inmutable.
  - La función SQL optimizada `public.app_current_company_id()` evalúa `current_setting('app.current_company_id', true)` y retorna `NULL` ante cualquier anomalía, garantizando denegación por defecto.
  - Todas las políticas RLS utilizan subplan estático `(SELECT app_current_company_id())` según directrices oficiales de Supabase Postgres Best Practices.
- **Integridad entre Tenants**: Todas las relaciones foráneas entre tablas con tenant son compuestas `(company_id, parent_id) → (company_id, id)`, imposibilitando que una empresa referencie registros de otra a nivel de motor.

### Backend (Laravel 11+)
- **PHP 8.2+ con tipado estricto**: `declare(strict_types=1);` en todos los archivos.
- **Controladores Invocables**: Arquitectura de *Single Action Controllers* orientados a casos de uso de dominio.
- **Fuerte Tipado Estilo C# (`System.ValueType`)**:
  - `StronglyTypedLongId` / `AccountId` / `CompanyId`: Identificadores nominales inmutables de 64 bits (`Int64`).
  - `AccountLevelShort`: Estructura nominal de 16 bits (`Int16` / `short`) restringida a los niveles jerárquicos 1..4 del PCGE.
  - `Decimal`: Value object basado puramente en **BCMath**, eliminando de raíz cualquier error de redondeo de punto flotante.
- **Autenticación**: Laravel Sanctum con tokens personales stateless.

### Frontend (Vue 3 + Vite + Tailwind CSS)
- **Modularidad SPA**: Vue 3 con Composition API, `<script setup lang="ts">` y TypeScript estricto.
- **Contratos Zod**: Doble validación en cliente (tiempo real en formularios interactivos y validación estricta de esquemas de respuesta de la API).
- **Sistema de Diseño Aiko**: Paleta cromática semántica corporativa (`#2ba5b2`, `#023e55`, `#3b4e73`, `#f7af02`) con variables CSS para modo claro y oscuro, reduciendo la fatiga visual de contadores y operadores.
- **Atajos de Teclado**: Soporte para digitación intensiva rápida (`Alt+N` para nueva subcuenta, `Enter` para configuración, flechas de cursor para navegación en árbol).

---

## 2. Principios Contables Inmutables del Sistema

1. **Precisión Matemática Absoluta (Anti-Flotantes)**:
   - Prohibido el uso de `float` o `double` en cualquier capa del sistema.
   - En base de datos: montos monetarios en `NUMERIC(14,4)` y tipos de cambio en `NUMERIC(14,6)`.
   - En backend (PHP): exclusivamente la extensión **BCMath** a través del Value Object `Decimal`.
   - En frontend: los montos viajan como strings numéricos validados mediante expresiones regulares exactas con Zod.
2. **Partida Doble Estricta**:
   - Todo asiento contable cumple matemáticamente `SUM(debe) - SUM(haber) = 0.0000` en moneda nacional y moneda extranjera.
   - Validación multinivel: en el DTO/Servicio, mediante transacciones con aislamiento y `CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` en PostgreSQL.
3. **Bimoneda Simultánea (PEN / USD / EUR)**:
   - Registro simultáneo de cada línea de asiento en moneda local y extranjera, con almacenamiento inmutable del tipo de cambio histórico aplicado a la fecha de emisión (compra o venta).
4. **Inmutabilidad Contable (Journaling Puro)**:
   - Todo asiento aprobado y cerrado es inalterable. No existen operaciones destructivas `DELETE` o `UPDATE` sobre saldos asentados; toda rectificación se procesa mediante asientos de extorno o notas de débito/crédito.

---

## 3. Catálogos y Parámetros Sembrados en Base de Datos

### Planes Contables Matrices Oficiales (`accounting_account_templates`)
El sistema almacena como catálogos maestros inmutables las dos matrices oficiales del Plan Contable General Empresarial peruano:
- **PCGE 2019 (Vigente)**: 1,764 cuentas oficiales desde Elemento (Nivel 1) hasta Divisionarias y Subdivisionarias (Niveles 4 a 6). Es la plantilla predeterminada con la que inician todas las empresas.
- **PCGE 2026 (NIIF 18 / NIIF 19)**: 1,637 cuentas cargadas conforme a la documentación del repositorio. Aprobado mediante **Resolución CNC N.° 002-2026-EF/30** (publicada el 04/09/2026); **obligatorio desde el 01/01/2028**, con aplicación anticipada permitida. La adopción debe quedar parametrizada por empresa y ejercicio. [Norma oficial](https://busquedas.elperuano.pe/dispositivo/NL/2550786-1).
- **Patrón Matriz → Tenant**: Cada empresa clona su propio árbol contable en `accounting_accounts` bajo su `company_id`. Cada empresa es libre de renombrar, crear subdivisionarias (5 y 6 dígitos) o reconfigurar amarres y centros de costos sin afectar a las demás empresas ni a la matriz central del sistema.

### Tablas Oficiales SUNAT (Resolución de Superintendencia)
- **Tabla 01**: Medios de Pago (Depósito en cuenta, Giro, Transferencia de fondos, Orden de pago, etc.).
- **Tabla 02 / 10**: Tipos de Comprobante de Pago o Documento (01 Factura, 03 Boleta de Venta, 07 Nota de Crédito, 08 Nota de Débito, etc.).
- **Tabla 03**: Entidades del Sistema Financiero con códigos oficiales de 2 caracteres (`02` BCP, `03` Interbank, `11` BBVA, `18` Banco de la Nación, etc.).
- **Tabla 05**: Aduanas de la República.
- **Tabla 06**: Tipos de Documento de Identidad (1 DNI, 4 Carné de Extranjería, 6 RUC, 7 Pasaporte).
- **Tabla 08**: Códigos de Monedas (ISO 4217).
- **Tabla 11**: Aduanas de Declaración Simplificada.
- **Tabla 12**: Tipo de Operación sujeta a Retención o Percepción.

### Normas Internacionales NIIF y NIC (Resolución CNC N.° 003-2026-EF/30)
- **18 Normas NIIF**: Incluyendo **NIIF 18** (*Presentación e Información a Revelar en los Estados Financieros*) y **NIIF 19** (*Subsidiarias sin Obligación Pública de Rendir Cuentas*), con **vigencia internacional general desde 2027**, salvo adopción anticipada; comprobar las disposiciones peruanas correspondientes antes de aplicarlas automáticamente.
- **24 Normas NIC**: Registro completo de NICs vigentes y registro histórico de la **NIC 1** retirada y sustituida por NIIF 18.

### Rubros Oficiales de Estados Financieros (SMV / NIIF 18 / PCGE)
- **ESF (Estado de Situación Financiera)**: Activo Corriente, No Corriente, Pasivo Corriente, No Corriente y Patrimonio Neto.
- **ERF (Estado de Resultados por Función)**: Ventas, Costo de Ventas, Gastos Administrativos (94), Gastos de Ventas (95).
- **ERN (Estado de Resultados por Naturaleza)**: Compras, Gastos de Personal, Servicios de Terceros, Cargas Imputables (79).
- **EFE (Estado de Flujos de Efectivo - NIC 7)**: Actividades de Operación, Inversión y Financiamiento.

---

## 4. Estructura del Repositorio

```text
contabilidad/
├── backend/                               # API RESTful en Laravel 11 (PHP 8.3+)
│   ├── app/
│   │   ├── Enums/                         # Backed Enums nativos de dominio contable
│   │   ├── Exceptions/                    # Excepciones de dominio (Fail-Closed, TenantIntegrity)
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/        # Single Action Controllers invokables
│   │   │   │   ├── Accounting/            # Cuentas contables y árbol jerárquico
│   │   │   │   ├── Catalogs/              # Monedas, SUNAT, NIIF/NIC, Rubros EEFF, Cronograma
│   │   │   │   └── Company/               # Settings, Áreas, Centros de Costo, Entidades
│   │   │   ├── Middleware/                # TenantMiddleware (inyección de X-Company-Id y set_config)
│   │   │   └── Requests/Api/V1/           # FormRequests con validación estricta
│   │   ├── Models/                        # Modelos Eloquent con BelongsToTenant y TenantScope
│   │   └── Support/
│   │       ├── Math/                      # Decimal Value Object basado en BCMath
│   │       ├── Tenant/                    # TenantContext (coordinador de tenant seguro)
│   │       └── Types/                     # Tipos fuertemente tipados (Int64, Int16)
│   ├── config/database.php                # Configuración de base de datos PostgreSQL y RLS
│   ├── config/sanctum.php                 # Autenticación stateless y optimización de latencia
│   ├── database/
│   │   ├── migrations/                    # Migraciones con RLS, triggers y constraints nativos
│   │   └── seeders/                       # Seeders oficiales de catálogos y empresa demo
│   └── tests/
│       └── Feature/                       # Pruebas con Pest (14 pruebas / 140 aserciones)
│           ├── Accounting/                # Pruebas de tipado C#, jerarquía y catálogo
│           ├── CompanySetupAndCatalogsTest.php # Pruebas de catálogos SUNAT y NIIF 2026
│           └── Tenant/                    # Pruebas de aislamiento multi-tenant y RLS
│
├── frontend/                              # SPA Modular en Vue 3 + TypeScript
│   ├── src/
│   │   ├── assets/main.css                # Tokens de diseño Tailwind CSS y paleta Aiko
│   │   ├── modules/accounting/
│   │   │   ├── api/                       # AccountingApiClient tipado con fetch nativo
│   │   │   ├── components/                # AccountTreeNodeRow, AccountDetailInspector, AccountFormModal
│   │   │   ├── composables/               # useAccountTree, useAccountingCatalogs
│   │   │   ├── schemas/                   # Esquemas de validación Zod con tipado C#
│   │   │   ├── types/                     # Tipos e interfaces de dominio contable
│   │   │   └── views/                     # AccountingCatalogView, JournalEntryView, ExchangeRatesView
│   │   ├── modules/company/               # CompanySettingsView (parámetros y monedas)
│   │   └── shared/
│   │       ├── layout/                    # AppHeader, AppSidebar con navegación por módulos
│   │       ├── types/numeric.types.ts     # SystemDecimal, Int64, Int16 con branding nominal
│   │       └── ui/UiSelect.vue            # Select corporativo accesible y personalizado
│   └── vite.config.ts                     # Configuración de Vite con Tailwind CSS v4
│
├── plan-contable-2026.md                  # Especificación técnica del PCGE 2026
├── plan-contable-2019.md                  # Especificación de referencia histórica PCGE 2019
├── tablas-sunat.md                        # Diccionario de catálogos oficiales SUNAT
└── README.md                              # Documentación general de arquitectura
```

---

## 5. Endpoints Principales de la API RESTful (`/api/v1`)

| Método | Endpoint | Descripción | Requiere Tenant |
|---|---|---|:---:|
| `GET` | `/api/v1/accounting/accounts/tree` | Árbol jerárquico del Plan Contable (PCGE) | Sí (`X-Company-Id`) |
| `POST` | `/api/v1/accounting/accounts` | Crea una cuenta contable validada (niveles 1..4) | Sí (`X-Company-Id`) |
| `PUT` | `/api/v1/accounting/accounts/{id}` | Actualiza configuración operativa de una cuenta | Sí (`X-Company-Id`) |
| `PATCH` | `/api/v1/accounting/accounts/{id}/deactivate` | Desactiva una cuenta (valida que no tenga hijas activas) | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/accounting/accounts/operational` | Catálogo de cuentas operativas imputables (Nivel 4+) | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/accounting/journal-entries/next-number` | Precalcula el siguiente correlativo del voucher en el periodo | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/accounting/journal-entries` | Lista asientos del Libro Diario bimoneda con líneas y cuentas | Sí (`X-Company-Id`) |
| `POST` | `/api/v1/accounting/journal-entries` | Registra y asienta un voucher contable bimoneda (Partida Doble) | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/company/exchange-rates` | Tipos de cambio diarios obligatorios según régimen de monedas | Sí (`X-Company-Id`) |
| `POST` | `/api/v1/company/exchange-rates` | Guarda o actualiza tipos de cambio de compra y venta | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/catalogs/currencies` | Catálogo oficial de monedas (PEN, USD, EUR) | No |
| `GET` | `/api/v1/catalogs/sunat/{tableNumber}` | Tablas oficiales de SUNAT (01, 02, 03, 05, 06, etc.) | No |
| `GET` | `/api/v1/catalogs/accounting-standards` | Normas contables vigentes (NIIF 18, NIIF 19, NICs) | No |
| `GET` | `/api/v1/catalogs/financial-statement-rubrics` | Rubros oficiales para EEFF (ESF, ERF, ERN, EFE) | No |
| `GET` | `/api/v1/catalogs/tax-calendar-deadlines/{year?}` | Cronograma oficial de vencimientos tributarios SUNAT | No |
| `GET` | `/api/v1/catalogs/chart-of-accounts-templates` | Catálogo maestro de planes contables matrices (PCGE 2019 y PCGE 2026) | No |
| `POST` | `/api/v1/company/chart-of-accounts/initialize` | Inicializa o clona el plan contable para la empresa activa desde la matriz | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/company/settings` | Obtiene la configuración contable de la empresa | Sí (`X-Company-Id`) |
| `PUT` | `/api/v1/company/settings` | Actualiza la configuración contable de la empresa | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/company/areas` | Áreas funcionales operativas de la empresa | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/company/cost-centers` | Centros de costos con amarre automático a 94/95 vs 79 | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/company/cash-flow-areas` | Áreas de flujo de efectivo según clasificación NIC 7 | Sí (`X-Company-Id`) |
| `GET` | `/api/v1/company/entities` | Directorio maestro de anexos y entidades de la empresa | Sí (`X-Company-Id`) |

---

## 6. Sistema de Gestión de Seguridad de la Información (ISO/IEC 27001:2023)

El sistema ha sido diseñado e implementado incorporando controles normativos de **ISO/IEC 27001:2023** para garantizar confidencialidad, integridad y trazabilidad inmutable en todas las capas:

| Control ISO 27001 | Medida Técnica Implementada | Capa de Aplicación |
|---|---|---|
| **Control 8.15 (Registro y Auditoría)** | Tabla `audit_logs` inmutable. El rol `app_backend` únicamente cuenta con permisos `INSERT` y `SELECT`, con revocación estricta de `UPDATE` y `DELETE` a nivel de motor PostgreSQL. RLS forzado por tenant. Servicio `AuditLoggerService` que audita inicios de sesión (exitosos y fallidos), cambios de tenant, altas, modificaciones y desactivaciones de cuentas contables. | PostgreSQL / Laravel |
| **Control 8.11 (Enmascaramiento de Datos)** | Método `maskSensitiveData()` que intercepta y sanitiza recursivamente cualquier payload antes de registrar eventos, reemplazando contraseñas, tokens de acceso, secretos y números bancarios por `***MASKED_CONFIDENTIAL***`. | Backend (`AuditLoggerService`) |
| **Control 8.24 y 8.26 (Seguridad en Aplicaciones y Criptografía)** | `SecurityHeadersMiddleware` inyecta de forma global encabezados HTTP estrictos: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `X-XSS-Protection: 1; mode=block`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, y `Cache-Control: no-store, no-cache, must-revalidate` para endpoints API. | Middleware Global |
| **Control 8.5 (Autenticación Segura y Mitigación de Fuerza Bruta)** | Rate limiting estricto (`throttle:10,1`) en `/api/v1/auth/login` que bloquea intentos automatizados de fuerza bruta con HTTP 429 tras 10 intentos. Tokens personales stateless emitidos por Laravel Sanctum almacenados en `sessionStorage` (destrucción automática al cerrar la sesión). | API / Frontend (`useAuthSession`) |

---

## 7. Licencia y Metodología
Desarrollado bajo principios de arquitectura **Clean Room**, con especificaciones abiertas, no propietarias y fundamentadas estrictamente en la normativa contable estándar y la legislación tributaria peruana vigente al ejercicio 2026.
