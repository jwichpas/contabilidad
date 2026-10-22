# F2.2 — Enrutamiento automático por centro de costos (matriz v2, DRAFT)

**22/10/2026.** GitHub `main`. Objetivo: Neon existente **contabilidad-dev**, nunca production ni Supabase.
**Estado:** migración e instalador versionados en Git, **NO aplicados a Neon**. El motor POST automático permanece desactivado.

## Hallazgo confirmado en Neon PG18

En cada una de las tres empresas DEMO:

- 2.966 cuentas, 1.214 hojas operativas de seis dígitos.
- 433 cuentas marcadas `has_automatic_destination`, heredadas del maestro PCGE: **206 estructurales** y **227 hojas operativas**.
- Las referencias antiguas `9411/7911` no forman una ruta operativa válida. **No reemplazarlas globalmente**, ni reimportar la matriz PCGE v1.
- Dos centros activos por empresa con claves intra-tenant ya correctas:
  - `CC-ADM`: Debe `941100`; Haber `791000`.
  - `CC-VTA`: Debe `951100`; Haber `791000`.
- El motor POST actual no guarda `journal_entry_lines.cost_center_id` ni genera partidas de destino. Por ello, ninguna versión DRAFT significa que esté operativa la automatización.

## Contrato de datos v2

La migración portable `2026_10_22_110000_create_accounting_destination_rules_v2.php` añade:

- `accounting_destination_rule_versions`: una versión **por empresa** con estrategia `COST_CENTER`, estado `DRAFT`, número de fuentes/rutas y huella SHA-256 basada en códigos naturales.
- `accounting_destination_routes`: referencias concretas a la cuenta origen operativa, centro de costo y cuentas destino Debe/Haber.
- Foreign keys compuestas `company_id` para impedir mezcla de tenants.
- RLS `ENABLE + FORCE`, política SELECT exclusivamente por empresa activa para `app_backend`. Las escrituras de versiones/rutas quedan restringidas a migraciones/instaladores administrativos.

La versión `PCGE_2019_CC_V2` materializa **227 × 2 = 454 rutas por empresa** (1.362 en total), todas en `DRAFT`, jamás como reglas POST activas.

La plantilla global (3.401 PCGE), ledger histórico y las cuentas empresariales existentes se preservan. Las 206 cuentas estructurales marcadas por herencia no generan rutas y nunca deben registrar asientos.

## Instalación deliberada y verificación

Ejecutar solo con `backend/.env` que ya apunta a Neon `contabilidad-dev` (host directo validado por el script), sin caché de configuración:

```bash
cd ~/contabilidad
git pull --ff-only origin main
cd backend
php -l database/portable_migrations/2026_10_22_110000_create_accounting_destination_rules_v2.php
php -l scripts/phase2-neon-destination-v2.php
php scripts/phase2-neon-destination-v2.php --plan
php scripts/phase2-neon-destination-v2.php --verify
# Tras confirmar que la base es contabilidad-dev y aceptar la instalación DRAFT:
php scripts/phase2-neon-destination-v2.php --apply=contabilidad-dev
php scripts/phase2-neon-destination-v2.php --verify
```

Antes de instalar, el `--verify` nuevo debe fallar cerrado por ausencia de versión v2. El `--apply` es idempotente si la instalación coincide exactamente; no ejecuta migraciones genéricas ni afecta el ledger global. Al aplicar, la base pasará **18 → 19 migraciones**. Una falla después de DDL requiere inspeccionar el estado parcial, nunca `migrate:fresh`.

**Atención:** el verificador antiguo `phase2-neon-tenant-integrity.php --verify` sigue señalando referencias legacy `9411/7911` y seguirá retornando bloqueo hasta que su contrato se actualice para comprobar rutas v2 en vez de interpretar metadatos históricos como destinos activos. No confundir esa salida con pérdida de datos.

## Pendientes antes de activar POST

1. Agregar `cost_center_id` a `journal_entry_lines`, con FK intraempresa, auditoría y copiado correcto durante extornos; solo para líneas en las que proceda.
2. Exigir un centro de costos válido cuando el usuario posteé una hoja de gasto elegible; resolver la ruta **desde su versión aprobada**, no desde `destination_debit_code/credit_code` históricos.
3. Generar en la misma transacción las partidas analíticas Debe 94/95 y Haber 79, sin duplicar el asiento de origen 6xx ni romper partida doble en moneda local/extranjera; distinguir claramente asientos por naturaleza y función.
4. Probar cierre/apertura de períodos, extorno exacto, permisos/RLS negativos, multiempresa, redondeos con Decimal, concurrencia y doble ejecución antes de pasar una versión a `ACTIVE`.
5. Actualizar verificación integral/instalador `nexus:install` y documentar cambio v1 → v2 en nuevas empresas.

Las operaciones anteriores son **desarrollo pendiente**, no funcionalidades entregadas o certificadas por esta primera migración.
