# Anexos demo por empresa en Neon DEV

**26/10/2026 (Lima).** Carga de prueba en `contabilidad-dev` para DEMO A, B y C. El archivo reproducible es [`backend/database/data_patches/2026_10_26_000000_seed_demo_entities.sql`](../backend/database/data_patches/2026_10_26_000000_seed_demo_entities.sql). Es una carga de **datos privados por empresa**, no un catálogo global ni una migración normativa. No se ejecuta automáticamente en producción.

| Documento | Nombre visible | Rol de prueba | Fuente del RUC |
|---|---|---|---|
| RUC 20100047218 | [DEMO] BANCO DE CREDITO DEL PERU S.A. | Institución financiera | [BCP](https://www.viabcp.com/) |
| RUC 20100053455 | [DEMO] BANCO INTERNACIONAL DEL PERU S.A.A. | Institución financiera | [Interbank](https://interbank.pe/tratamiento-opcional-de-los-datos-personales) |
| RUC 20100055237 | [DEMO] ALICORP S.A.A. | Proveedor | [Alicorp](https://www.alicorp.com.pe/pe/es/promociones/celebra-al-peru-con-alicorp-2026-julio-2026) |
| RUC 20100070970 | [DEMO] SUPERMERCADOS PERUANOS S.A. | Cliente | [Plaza Vea](https://www.plazavea.com.pe/campanas-y-suscripciones) |
| DNI sintético 00000000 | PERSONA FICTICIA DEMO 01 | Cliente | Identidad ficticia |
| DNI sintético 00000001 | PERSONA FICTICIA DEMO 02 | Trabajador | Identidad ficticia |
| DNI sintético 00000002 | PERSONA FICTICIA DEMO 03 | Accionista | Identidad ficticia |

Los nombres y RUC de las sociedades provienen de páginas de las propias empresas. **Los roles son solo escenarios de prueba**; no afirman una relación comercial con las compañías DEMO. No se registran nombres ni DNI reales de personajes públicos. Los DNI de ocho dígitos anteriores son valores sintéticos admitidos por la validación de formato actual; no se han verificado ante RENIEC y no se deben usar en comprobantes o documentos reales.

El parche exige la identidad exacta de los tres tenants y los tipos de documento SUNAT `1` y `6`, inserta siete anexos en cada uno, no modifica entidades existentes y rehúsa una carga parcial o alterada. Puede reejecutarse sin duplicados. Cada fila obtiene un evento `DEMO_ENTITY_SEEDED` en `audit_logs`, con el documento enmascarado.

## Verificación en DEV

Aplicado a Neon `contabilidad-dev` el 26/10/2026 (Lima). Consultas posteriores: DEMO A, B y C tienen **7 anexos y 7 eventos de auditoría por empresa**; cada una tiene cuatro sociedades, tres personas ficticias, dos bancos, un proveedor, dos clientes, un trabajador y un accionista. La segunda ejecución del mismo parche terminó sin duplicar filas. La API y la interfaz siguen sujetas al selector global de empresa y a RLS.

Para repetirlo en otro entorno de desarrollo con las mismas tres empresas DEMO, ejecute el archivo completo como un único statement usando la conexión administrativa directa de esa rama, después de comprobar que sus datos son descartables. No lo incluya en la carga de catálogos SUNAT ni en la base de producción.
