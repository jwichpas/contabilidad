# F2.3a — Dimensión de centro de costos en partidas del Libro Diario

**Fecha:** 22 de octubre de 2026. **Código en `main`, todavía sin ejecutar contra Neon.**

## Evidencia anterior (consultada en Neon contabilidad-dev)

- PostgreSQL 18, **19 migraciones**, **3 empresas y 3 versiones `PCGE_2019_CC_V2` DRAFT**.
- **1.362 rutas de destinos**; 227 hojas elegibles x CC-ADM/CC-VTA x 3 empresas.
- Cero asientos: todavía no hay datos de movimientos de producción.
- Consulta READ ONLY de auditoría F2.2 versionada: rutas válidas (0 parejas faltantes; 0 rutas inválidas). Se mantienen metadatos legacy `9411/7911` únicamente como referencia histórica.

## Implementación F2.3a (schema, backend, frontend)

1. `database/portable_migrations/2026_10_22_120000_add_journal_line_cost_centers.php`: añade `journal_entry_lines.cost_center_id NULL`, índice y FK compuesta `(company_id, cost_center_id)` → `cost_centers(company_id,id)` del mismo tenant. Sin `TRUNCATE`, ni cambios en los asientos.
2. `JournalEntryLine`: atributo, cast, relación con CostCenter.
3. `CreateJournalEntryController`: valida `lines.*.cost_center_id` como entero opcional y lo envía al servicio.
4. `PostJournalEntryService`: comprueba el centro **activo y de la misma empresa** dentro de la transacción del asiento, lo guarda junto a la partida y lo **preserva en los extornos**. No crea partidas 94/95/79.
5. `JournalEntryView.vue`: conserva **un editor y una sola tabla de partidas**; añade columna opcional «Centro de costo» alimentada desde `GET /company/cost-centers` (tenant activo) y visualiza el código en el historial.
6. CI: PHP lint, `--plan` sin SQL, migración real en PostgreSQL 18 efímero y comprobación de columna nullable + FK, sin crear asientos.
7. `phase2-neon-tenant-integrity.php --verify`: validador actualizado para matriz v2 DRAFT (19 o 20 migraciones), sin exigir que códigos heredados 9411/7911 sean operativos. La consulta del auditor se probó directamente contra Neon.

## Aplicación restringida SOLO a la rama Neon contabilidad-dev

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend

php -l database/portable_migrations/2026_10_22_120000_add_journal_line_cost_centers.php
php -l scripts/phase2-neon-journal-cost-centers.php
php -l app/Models/JournalEntryLine.php
php -l app/Services/Accounting/PostJournalEntryService.php
php -l app/Http/Controllers/Api/V1/Accounting/CreateJournalEntryController.php
php -l scripts/phase2-neon-tenant-integrity.php

php scripts/phase2-neon-journal-cost-centers.php --plan
php scripts/phase2-neon-journal-cost-centers.php --verify
# Antes de aplicar, --verify deberia devolver PENDIENTE.
php scripts/phase2-neon-journal-cost-centers.php --apply=contabilidad-dev
php scripts/phase2-neon-journal-cost-centers.php --verify
php scripts/phase2-neon-tenant-integrity.php --verify
```

Al completar, Neon debe pasar de 19 a **20 migraciones**; la migración es aditiva y la columna opcional. **No** ejecutar `php artisan migrate` global, `db:seed`, `migrate:fresh` ni recrear las 3 empresas.

**Despliegue**: aplicar la migración antes de dirigir tráfico al backend actualizado, que ya incluye la columna `cost_center_id` en cada INSERT de línea.

## Lo que todavía NO está implementado/activado

- No existe todavía un generador automático de partidas **Debe 941100 / 951100; Haber 791000**; la matriz sigue DRAFT.
- Falta definir el vínculo auditable de cada transferencia automática con su origen 6xx, versionado y mecanismo de prevención de doble imputación.
- Falta prueba HTTP real en base aislada: centro activo/inactivo, rechazo cruzado entre tenants, partida doble local y extranjera, bloqueo de período, extorno conservando cost_center_id, RLS, concurrencia.
- No marcar F2.3 motor POST como completo hasta obtener **pruebas exitosas reales** y aprobación/activación explícita de las reglas.

Neon production y ambos Supabase deben permanecer sin escrituras.
