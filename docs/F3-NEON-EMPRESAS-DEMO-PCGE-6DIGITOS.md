# F3 — Inicialización reproducible de empresas DEMO y cuentas de seis dígitos

**Fecha de comprobación:** 2026-10-22 UTC. **Entorno:** Neon PostgreSQL 18, proyecto `soft-bonus-52215969`, rama EXISTENTE `contabilidad-dev` (`br-odd-lake-b6wshqv3`), base `neondb`. No se abrió rama adicional.

## Puerta F2 cerrada

El usuario ejecutó correctamente el instalador F2 y obtuvo `GLOBAL_REFERENCE_OK` con:
- 18 migraciones; 1 registro `reference_data_versions`
- 3 monedas, 163 códigos de 9 tablas SUNAT, 43 normas, 48 rubros
- 3.401 plantillas PCGE (1.764 del PCGE 2019 + 1.637 del PCGE 2026)
- SHA256 por tabla y fuentes verificados por `--verify`

## Parche F3 DEV ejecutado y comprobado

Fuente: [`backend/database/data_patches/2026_10_22_080000_init_three_demo_pcge_6digits.sql`](../backend/database/data_patches/2026_10_22_080000_init_three_demo_pcge_6digits.sql).

**No volver a ejecutar.** El parche tiene precondiciones estrictas de base vacía y solo se aplicó una vez con el conector Neon, explícitamente a `contabilidad-dev`. Es una operación transaccional de datos, no una migración DDL. A la siguiente ejecución detectará cuentas/configuraciones existentes y fallará cerrada.

Cada una de las tres empresas DEMO recibió:
- 1.764 cuentas PCGE 2019 de referencia clonadas de manera independiente (sin cambiar el maestro)
- 1.191 subcuentas nuevas de seis dígitos bajo hojas de longitud 3 a 5
- **2.955 cuentas** por empresa; **1.212 cuentas operativas de exactamente seis dígitos**, ninguna operativa más corta
- 1 fila `company_settings` de demostración y 2 filas `company_currencies` (PEN base y USD secundaria)
- Referencias de cuentas `401110` (IGV), `776000` (ganancias por diferencia), `676000` (pérdidas por diferencia), `791000` (cargas imputables) y `591100` (resultados acumulados)
- Régimen GENERAL, tasa 18% y obligaciones SIRE/PLE desactivadas como **valores puramente de desarrollo**, no determinada situación tributaria real
- Áreas previamente creadas (6 organizacionales, 9 de flujo por empresa), sin alteración

**Totales verificados:** 8.865 cuentas por tenant combinadas; 3.636 operativas; 3 settings; 6 asociaciones de moneda; 3 empresas, 3 usuarios y 8 membresías sin cambios; 3.401 plantillas globales intactas; cero asientos y cero centros de costos.

**Validación de integridad por SQL:** cero jerarquías rotas; cero cuentas operativas con hijos; cero cuentas operativas de menos de seis dígitos. Al conectarse como `app_backend` dentro de la transacción con tenant DEMO A, se vieron 2.955 cuentas propias, 0 de DEMO B, una configuración y 2 monedas. Sin tenant se vieron cero cuentas/configuraciones. No se ejecutaron pruebas HTTP del frontend ni pruebas completas de escritura POST.

## Política replicada para empresas futuras

La clase [`SixDigitChartExpander`](../backend/app/Support/Accounting/SixDigitChartExpander.php) produce las subcuentas de seis dígitos SIN modificar las plantillas globales ni sus instancias originales; detecta códigos duplicados. `InitializeCompanyChartOfAccountsService` utiliza esta transformación antes de clonar y asigna las referencias predeterminadas a códigos operativos de seis dígitos. `SixDigitChartExpanderTest.php` cubre hojas breves, hojas ya de seis dígitos, preservación del origen y colisiones.

PCGE 2026 también supera la verificación de códigos (1.116 alias requeridos, 0 colisiones), **pero no se ha instalado como plan de estas empresas** y la integración de su clonación debe probarse con PHP/Pest/HTTP.

## Pendientes que NO deben marcarse completos

1. **Centros de costo:** todavía no hay cuentas analíticas clase 9 (94/95) específicas de empresa. Nunca asignar una cuenta destino inexistente ni fabricar una contrapartida; definir plan y amarres 79 con cuentas operativas de seis dígitos primero.
2. **Wizard F3 Neon:** faltan las columnas del perfil económico `company_settings.legal_form`, `initial_fiscal_year`, `income_reference_year`, `annual_income` y `initial_pcge_version` junto a otras estructuras sectoriales. El guard lo bloqueará hasta migración portable. No declarar el alta de empresas desde UI completamente operativa.
3. **Pruebas PHP/Pest e2e:** validar en la instalación local tras `git pull --ff-only origin main`:
   ```bash
   cd backend
   php -l app/Support/Accounting/SixDigitChartExpander.php
   php -l app/Services/Accounting/InitializeCompanyChartOfAccountsService.php
   php -l tests/Unit/SixDigitChartExpanderTest.php
   php artisan test --filter=SixDigitChartExpanderTest
   ```
4. **Motor POST y período fiscal:** no hay asientos de demostración generados por F3. No mezclar prueba de RLS lectura con éxito de POST. Crear períodos y practicar asientos balanceados solo después de validar controles de empresa/moneda, correlativos, extornos e idempotencia.
5. **SUNAT:** los archivos PLE históricos no verificados y los 23 códigos DRAFT no están promovidos como catálogo normativo; CLP y cronogramas no verificados quedan fuera del dataset F2.
6. **Portabilidad de desarrollo:** el parche de datos DEMO es para rama de desarrollo, no para nuevas empresas reales. Las instalaciones nuevas deben usar `InitializeCompanyChartOfAccountsService` y seguir una instalación global F2 versionada. Evitar `migrate:fresh`, `db:seed` global y cualquier operación en Neon production o Supabase.

## Consultas de comprobación seguras

```sql
SELECT c.id,c.business_name,count(a.id) AS accounts,
       count(a.id) FILTER (WHERE a.is_operational AND length(a.code)=6) AS posting_accounts
FROM public.companies c
LEFT JOIN public.accounting_accounts a ON a.company_id=c.id
GROUP BY c.id,c.business_name
ORDER BY c.id;
```

## Validación local informada por usuario (22/10/2026)

Tras corregir la creación de fixtures Eloquent para la suite unitaria, el usuario ejecutó
`php artisan test --filter=SixDigitChartExpanderTest`: **2 tests PASS, 13 assertions**.
Esto valida únicamente el expander en memoria, **no** la clonación integral sobre PostgreSQL,
el asistente de empresa ni POST.

En la revisión del mismo turno se detectó y corrigió un error de captura de variables
en `InitializeCompanyChartOfAccountsService`: la transacción interna capturaba
`$templates` en lugar de `$expandedTemplates`. El fix ya está en `main`;
su prueba de integración sobre una empresa nueva sigue pendiente. Las empresas existentes
de F3 fueron inicializadas por parche SQL reproducible, no por este servicio.

Una consulta de solo lectura en Neon dev para DEMO A confirmó que existen cuentas de la
familia `79` (incluidas 2 imputables a 6 dígitos) pero **no hay cuentas `94` ni `95`**.
Los centros de costo siguen vacíos a propósito: no se deben enlazar a destinos que no existen.
La etapa F4 debe construir la contabilidad analítica por empresa, hacer configurables los
destinos de seis dígitos y probar la generación/posteo sin modificar la matriz PCGE global.

