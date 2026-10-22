# F2 — Estado real de datos maestros en Neon (22/10/2026, UTC)

## Destinos y protección

- Repositorio: `jwichpas/contabilidad`, rama existente `main` (no se creó rama adicional).
- Proyecto Neon `soft-bonus-52215969` PostgreSQL 18, rama SOLO desarrollo `contabilidad-dev` (`br-odd-lake-b6wshqv3`), base `neondb`.
- Neon `production` y las dos bases Supabase no recibieron escrituras.
- PostgreSQL de desarrollo tiene 17 migraciones, 3 empresas DEMO, 3 usuarios y 8 membresías. No borrar ni re-crear esos objetos.

## Distinción obligatoria

1. **DDL migrado**: estructura y restricciones (17 migraciones existentes).
2. **Datos globales congelados**: 13 conjuntos, en `database/reference_data/manifest.json`. Instalador controlado `scripts/phase2-neon-global-reference.php`, con `--plan`, `--verify` y `--apply=contabilidad-dev` (requiere `.env` privado). No usar `db:seed`, `migrate:fresh`, ni pegar secretos.
3. **Datos por empresa**: las 6 áreas organizacionales y 9 áreas de flujo por tenant se inicializan en `CompanyOperationalDefaultsService` al crear la empresa. No son catálogos globales y no se deben mezclar entre compañías.

## Verificación real de Neon después de este cambio

| Componente | Estado de desarrollo |
| --- | --- |
| `companies`, `users`, `company_user` | 3 / 3 / 8 |
| `migrations` | 17 |
| `currencies`, `accounting_standards`, `financial_statement_rubrics` | 0 / 0 / 0 |
| Catálogos SUNAT listados en el manifiesto | Tablas creadas; carga F2 aún no aplicada |
| `accounting_account_templates` | 0 de 3.401 |
| `reference_data_versions` | No existe hasta aplicar la migración portable 18 |
| `company_areas` | 6 por empresa DEMO, total 18 |
| `cash_flow_areas` | 9 por empresa DEMO, total 27 |
| `cost_centers`, `accounting_accounts` | 0 / 0: no inventar cuentas de clase 9 |

Se ejecutó solamente el parche dev versionado `database/data_patches/2026_10_21_060000_backfill_demo_company_areas.sql`, en una transacción con precondiciones explícitas; no se actualizaron datos de terceros ni usuarios. Una lectura bajo el rol `app_backend` con `app.current_company_id=3000000000101` confirmó 6 áreas y 9 flujos visibles, y **0 áreas de la otra empresa**. Es prueba parcial de aislamiento de lectura, no prueba integral de la API.

Las seis fuentes maestras y dos archivos históricos conservan **exactamente** los SHA de Git declarados en el manifiesto. Los archivos históricos (247 filas PLE y 23 códigos borrador) siguen no aprobados, **no se incorporan al catálogo regulatorio operativo**.

## Flujo pendiente de instalación global F2

Desde el equipo local, tras `git pull --ff-only origin main` y comprobar que `backend/.env` apunta únicamente a `contabilidad-dev`:

```powershell
cd C:\Proyectos\contabilidad\backend
php -l scripts/phase2-neon-global-reference.php
php scripts/phase2-neon-global-reference.php --plan
php scripts/phase2-neon-global-reference.php --verify
php scripts/phase2-neon-global-reference.php --apply=contabilidad-dev
php scripts/phase2-neon-global-reference.php --verify
```

Este procedimiento escribe 3 monedas, 163 entradas SUNAT del conjunto congelado, 43 normas, 48 rubros y 3.401 plantillas PCGE; documenta SHA256 y cantidades en `reference_data_versions`. **No se ha ejecutado aquí**, porque el instalador requiere el contexto Laravel y las credenciales privadas del equipo; las credenciales no deben enviarse al chat.

## Protecciones nuevas

- `GlobalReferenceReadiness` bloquea nuevas empresas si los 13 conjuntos no están completos; solo consulta tablas para las que `app_backend` tiene SELECT. No intenta leer el ledger administrativo privado.
- `CompanyOperationalDefaultsService` inserta áreas de la empresa de forma idempotente dentro de la transacción de onboarding, sin copiar bancos, personas ni deudas ficticias.
- Los centros de costos deben asociarse a cuentas de destino realmente configuradas dentro del tenant, idealmente a nivel operativo de 6 dígitos. El PCGE base no contiene cuentas analíticas 94/95 propias de cada empresa.
- Pruebas Pest unitarias nuevas para manifiesto y defaults; **ejecución PHP/Pest todavía pendiente**.

## Próxima puerta de calidad

1. Completar F2 con instalador local y `GLOBAL_REFERENCE_OK`, sin copiar datos no autorizados de Supabase.
2. Clonar PCGE para las tres empresas DEMO; validar jerarquía y permisos multiempresa antes de permitir asientos.
3. Configurar cuentas analíticas clase 9 y sus amarres al 79 para habilitar centros de costos; no fabricar cuentas/reglas inexistentes.
4. Certificar calendarios tributarios SUNAT, catálogos PLE/SIRE históricos por fuente oficial y CLP como versiones posteriores; no mezclar con los catálogos electrónicos ya congelados.
