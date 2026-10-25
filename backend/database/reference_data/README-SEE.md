# Referencias SEE y ubigeo

Este conjunto es **global y de solo lectura** para todas las empresas. Lo instala la migración
`2026_10_24_150000_create_see_reference_catalogs.php` desde los dos JSON versionados, con SHA-256
fijo y recuentos esperados. No requiere `db:seed`, no actualiza los 13 conjuntos del instalador
global previo y no modifica ninguna tabla PLE/SIRE.

| Tabla | Contenido | Filas |
| --- | --- | ---: |
| `see_catalogs` | Catálogos 01–25 y 51–58; 13 y 25 solo remiten a fuentes externas | 33 |
| `see_catalog_items` | Códigos SEE del archivo suministrado | 1,792 |
| `see_ubigeos` | Distritos del archivo INEI suministrado | 1,874 |
| `see_catalog_versions` | Ediciones cotejadas de catálogos SEE, separadas por número y versión | 4 |
| `see_catalog_version_items` | Códigos de las ediciones cotejadas | 178 |

Las referencias provienen de `tablas-sunat-fac.md` y `ubigeo.md` suministrados el 24/10/2026.
La clasificación `SUPPLIED_*_SNAPSHOT_UNVERIFIED` expresa que **no se ha comprobado su vigencia**.
Por ejemplo, el archivo SEE incluye códigos de países históricos y llama “Nuevo Sol” a PEN.
El catálogo 03 incluye dos filas con el código `A11` (“5 C calorie” y “Ångstråm”); ambas se
conservan y llevan `is_ambiguous = true`. No utilizar esa clave como validación única mientras
no se concilie con la versión oficial correspondiente.

Consulta autenticada: `GET /api/v1/catalogs/sunat-see/{number}` (filtros `q`, `per_page`)
y `GET /api/v1/catalogs/sunat-see/ubigeos` (filtros `q`, `department`, `province`, `per_page`).
`/api/v1/catalogs/sunat/{tableNumber}` sigue siendo exclusivamente el catálogo anterior PLE/SIRE.
Para el ubigeo usar el endpoint específico: el catálogo SEE 13 es solo una referencia.

Las ediciones cotejadas son: 15 (41) y 19 (4) del [Anexo E SUNAT de 2017](https://www.sunat.gob.pe/legislacion/superin/2017/anexoE-245-2017.pdf),
20 (14) del [Anexo I SUNAT de 2024](https://www.sunat.gob.pe/legislacion/superin/2024/anexo1-000240-2024.pdf)
y 55 (119) del [Anexo I SUNAT de 2026](https://www.sunat.gob.pe/legislacion/superin/2026/anexo-000108-2026.pdf).
Son las publicaciones verificadas e incorporadas, sin afirmar vigencia normativa absoluta de las
ediciones 2017 o 2024. La API sin `version` devuelve la edición más reciente **registrada** para
cada catálogo; `version=latest` equivale a omitir el parámetro. `version=historical` consulta el
archivo recibido, también cuando existe una versión cotejada. Los catálogos sin edición cotejada
siguen mostrando el archivo recibido y su clasificación de no verificado. El índice de catálogos
incluye `latest_version`, `historical_item_count` y `available_versions`. Todos son globales de
solo lectura y no alteran PLE/SIRE. Instalar las migraciones en orden:

```bash
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_160000_add_see_catalog_20_sunat_2024_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_170000_add_see_catalog_15_19_55_versions.php --force
```

Instalación en la rama DEV actual, usando el rol propietario y conexión directa:

```bash
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_150000_create_see_reference_catalogs.php --force
php artisan test tests/Unit/SeeReferenceSourceTest.php
php artisan test tests/Feature/SeeReferenceCatalogTest.php
```

Si SUNAT/INEI publican cambios, agregar un nuevo conjunto versionado y una migración correctiva
con trazabilidad. Evitar modificar silenciosamente los códigos usados por documentos históricos.
