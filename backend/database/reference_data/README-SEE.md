# Referencias SEE y ubigeo

Este conjunto es **global y de solo lectura** para todas las empresas. Lo instala la migración
`2026_10_24_150000_create_see_reference_catalogs.php` desde los dos JSON versionados, con SHA-256
fijo y recuentos esperados. No requiere `db:seed`, no actualiza los 13 conjuntos del instalador
global previo y no modifica ninguna tabla PLE/SIRE.

| Tabla | Contenido | Filas |
| --- | --- | ---: |
| `see_catalogs` | Los 33 catálogos recibidos, más 26, 27 y 59 de otro anexo SEE | 36 |
| `see_catalog_items` | Códigos SEE del archivo suministrado | 1,792 |
| `see_ubigeos` | Distritos del archivo INEI suministrado | 1,874 |
| `see_catalog_versions` | Ediciones cotejadas de catálogos SEE, separadas por número y versión | 11 |
| `see_catalog_version_items` | Códigos de las ediciones cotejadas | 2,783 |
| `see_ubigeo_versions` | Ediciones INEI cotejadas | 1 |
| `see_ubigeo_version_items` | Distritos INEI publicados en la edición 2026 | 1,893 |

Las referencias provienen de `tablas-sunat-fac.md` y `ubigeo.md` suministrados el 24/10/2026.
La clasificación `SUPPLIED_*_SNAPSHOT_UNVERIFIED` expresa que **no se ha comprobado su vigencia**.
Por ejemplo, el archivo SEE incluye códigos de países históricos y llama “Nuevo Sol” a PEN.
El catálogo 03 incluye dos filas con el código `A11` (“5 C calorie” y “Ångstråm”); ambas se
conservan y llevan `is_ambiguous = true`. No utilizar esa clave como validación única mientras
no se concilie con la versión oficial correspondiente.

Consulta autenticada: `GET /api/v1/catalogs/sunat-see/{number}` (filtros `q`, `per_page`)
y `GET /api/v1/catalogs/sunat-see/ubigeos` (filtros `q`, `department`, `province`, `per_page`).
`/api/v1/catalogs/sunat/{tableNumber}` consulta las tablas PLE; SIRE utiliza referencias distintas.
Para el ubigeo usar el endpoint específico: el catálogo SEE 13 es solo una referencia.
El endpoint de ubigeos devuelve por defecto la última edición incorporada, la descarga INEI
2026 (`2026-inei`, 1,893 distritos); `?version=historical` conserva los 1,874 recibidos. La
respuesta incluye las ediciones disponibles. La nueva edición solo proporciona código,
departamento, provincia y distrito: población, área y coordenadas se devuelven como `null`.

Las ediciones cotejadas son: 02 (178) de la [Lista Uno ISO 4217 de SIX, publicada el 17/09/2026](https://www.six-group.com/en/products-services/financial-information/market-reference-data/data-standards.html),
03 (2,136) de la [Recomendación 20 Rev. 17 (2021) de UNECE](https://unece.org/trade/documents/revision-17-annexes-i-iii),
04 (249) de la [lista de países UN/CEFACT](https://test.uncefact.org/vocabulary/country-id) capturada el 24/10/2026,
15 (41) y 19 (4) del [Anexo E SUNAT de 2017](https://www.sunat.gob.pe/legislacion/superin/2017/anexoE-245-2017.pdf),
20 (14) del [Anexo I SUNAT de 2024](https://www.sunat.gob.pe/legislacion/superin/2024/anexo1-000240-2024.pdf)
y 55 (119) del [Anexo I SUNAT de 2026](https://www.sunat.gob.pe/legislacion/superin/2026/anexo-000108-2026.pdf).
Los catálogos 26 (3 códigos) y 27 (4 códigos) provienen del [Anexo V SUNAT de 2017](https://www.sunat.gob.pe/legislacion/superin/2017/anexosV-318-2017.pdf).
El catálogo SEE 59 (22 códigos) proviene del mismo anexo y permanece separado de la Tabla 01
PLE. La carga PLE original tenía 20 códigos y un error en `011`; la edición PLE cotejada
con el Anexo 3 de 2015 contiene los mismos 22 códigos y significados que SEE 59 en 2017.
`/api/v1/catalogs/sunat/01` presenta por defecto la edición `2015-06-anexo-3`, y
`?version=legacy-seed` permite consultar la carga original. Las fuentes, tablas y rutas no se
fusionan. El anexo SEE imprime «CATALOGO No. 57» en el encabezado
interno de 59; el título y el índice lo identifican como 59. Se conserva esa discrepancia como
nota de fuente, sin atribuir los códigos al catálogo 57.
El catálogo 06 agrega una edición de 13 códigos basada en la tabla de tipos de identidad
del [Anexo I de la R.S. 000049-2026/SUNAT](https://www.sunat.gob.pe/legislacion/superin/2026/anexo-000049-2026.pdf).
El anexo remite al catálogo 06 y enumera `E`, `F`, `G` y `H`, ausentes de los nueve códigos
recibidos. Se guardan las marcas de longitud y formato tal como aparecen en esa tabla;
son metadatos de la fuente, no reglas de validación universales para cada modalidad SEE.
No existían en el archivo suministrado: `historical_item_count = 0` y la consulta
`?version=historical` responde 404 para ellos. El anexo de 2017 es la edición oficial
incorporada, sin afirmar que sea la norma más reciente para todo comprobante.
Son las publicaciones verificadas e incorporadas, sin afirmar vigencia normativa absoluta de las
ediciones 2017 o 2024. La API sin `version` devuelve la edición más reciente **registrada** para
cada catálogo; `version=latest` equivale a omitir el parámetro. La edición 02 conserva también
el código numérico y la unidad menor de ISO. `version=historical` consulta el
archivo recibido, también cuando existe una versión cotejada. Los catálogos sin edición cotejada
siguen mostrando el archivo recibido y su clasificación de no verificado. El índice de catálogos
incluye `latest_version`, `historical_item_count` y `available_versions`. Todos son globales de
solo lectura y no alteran PLE/SIRE. Instalar las migraciones en orden:

```bash
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_160000_add_see_catalog_20_sunat_2024_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_170000_add_see_catalog_15_19_55_versions.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_180000_add_see_catalog_02_iso_4217_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_190000_add_see_ubigeo_inei_2026_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_200000_add_see_catalog_04_uncefact_country_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_210000_add_see_catalog_03_unece_rec20_rev17_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_220000_create_company_see_visibility_revisions.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_230000_add_see_catalogs_26_27_sunat_2017_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_240000_add_see_catalog_59_payment_methods_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_000000_add_see_catalog_06_sunat_2026_identity_version.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_010000_reconcile_ple_table_01_payment_methods.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_020000_reconcile_ple_table_02_identity_documents.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_030000_reconcile_ple_table_05_inventory_types.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_040000_version_ple_table_06_measurement_units.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_050000_version_ple_table_03_financial_institutions.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_060000_version_ple_table_12_inventory_operations.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_070000_version_ple_table_11_customs_dependencies.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_080000_version_ple_table_10_document_types.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_090000_version_sunat_table_08_book_codes.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_100000_add_ple_table_04_currency_codes.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_110000_add_ple_tables_13_14_inventory_references.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_120000_add_ple_tables_15_20_reference_editions.php --force
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_25_130000_add_ple_tables_21_22_reference_editions.php --force
```

Instalación en la rama DEV actual, usando el rol propietario y conexión directa:

```bash
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_10_24_150000_create_see_reference_catalogs.php --force
php artisan test tests/Unit/SeeReferenceSourceTest.php
php artisan test tests/Feature/SeeReferenceCatalogTest.php
```

La edición 02 es una instantánea del listado publicado el 17/09/2026; contiene también códigos
especiales (`XTS` para pruebas, `XXX` sin divisa). No equivale a permitir todos esos códigos para
facturación o para configurar monedas de empresas. La fecha de publicación de la lista no es la
fecha individual de alta o baja de cada moneda. No se sustituyen monedas en documentos históricos.
La edición 04 registra la fecha de captura del portal UN/CEFACT, que no es una fecha de
publicación ISO 3166 ni una declaración sobre la validez de cada país para un comprobante SUNAT.
El catálogo 03 muestra por defecto la tabla completa de la Recomendación 20 Rev. 17: 2,136
códigos únicos en los anexos II y III, que incluyen todos los códigos del Anexo I. La edición
recibida permanece con `version=historical`, incluidas las dos descripciones de `A11`. La edición
Rev. 17 identifica `D` como desaconsejado y `X` como marcado para eliminar; estos códigos siguen
visibles con su estado, pero no deben pasar una validación como si fueran códigos activos.
La tabla de envases de la Recomendación 21 es otro espacio de códigos: para expresar un envase
como unidad la Recomendación 20 requiere el prefijo `X`. El anexo SUNAT consultado remite a la
Revisión 4, por lo que la Rev. 17 no autoriza automáticamente un código para cualquier documento.

Si SUNAT/INEI/ISO publican cambios, agregar un nuevo conjunto versionado y una migración correctiva
con trazabilidad. Evitar modificar silenciosamente los códigos usados por documentos históricos.
La edición PLE 2015 proviene de [la R.S. 169-2015/SUNAT, Anexo 3](https://www.sunat.gob.pe/orientacion/amazon/ple/RS-169-2015-SUNAT-PLEver5.pdf).
La migración guarda las 20 filas anteriores bajo `legacy-seed` antes de corregir la tabla operativa;
el instalador global congelado conserva su hash y se ejecuta antes de esta migración. Los indicadores
auxiliares de entidad, número de operación y comercio exterior proceden del sistema, no del anexo;
los códigos nuevos llevan los valores predeterminados y requieren evaluación antes de validarlos.
La Tabla 02 PLE de [ese mismo Anexo 3](https://www.sunat.gob.pe/orientacion/amazon/ple/RS-169-2015-SUNAT-PLEver5.pdf)
incluye el código `A` (cédula diplomática), ausente de los cinco códigos originalmente cargados.
La migración `2026_10_25_020000` archiva esos cinco bajo `legacy-seed`, incorpora la edición de
seis y permite `GET /api/v1/catalogs/sunat/02?version=legacy-seed`. Longitudes, abreviaturas y
marcas numéricas son metadatos locales: el anexo citado solo fija código y descripción. Esta
Tabla 02 PLE no corresponde al Catálogo SEE 06 ni a la Tabla 01 de SIRE.
La Tabla 05 PLE cotejada en ese anexo incluye **19** tipos de existencia, frente a seis en el
instalador original. La migración `2026_10_25_030000` conserva el grupo de seis como `legacy-seed`,
incorpora `06` a `10` y `91` a `98`, y corrige las denominaciones de `01` a `05` y `99`.
`GET /api/v1/catalogs/sunat/05?version=legacy-seed` devuelve la carga anterior. Las sugerencias
de cuenta PCGE pertenecen al sistema y no a SUNAT; las filas nuevas no reciben una por defecto.
La Tabla 03 PLE de entidades financieras en el Anexo 3 de 2015 contiene 38 códigos, frente a 33
en la carga inicial. La migración `2026_10_25_050000` publica la edición cotejada como referencia
histórica en tablas propias, y archiva la carga anterior como `legacy-seed`. Deja intacta
`sunat_table_03_financial_institutions`: el anexo enumera bancos históricos y no establece
cuáles pueden elegirse como entidades operativas en 2026. El endpoint de consulta muestra 38
por defecto, con `operational_status_verified=false` y enlace a la fuente. No confundir estos
códigos de dos caracteres con códigos bancarios CCI de tres caracteres, ni con SIRE o SEE.
La Tabla 04 del mismo Anexo 3 enumera 178 códigos de moneda. La migración
`2026_10_25_100000` los incorpora en tablas PLE versionadas sin alterar SEE 02 ni las
monedas habilitadas por empresa. La extracción del PDF introducía errores OCR en algunas
descripciones, así que la edición solo conserva códigos cotejados y enlaza el PDF oficial;
`name` es nulo y `descriptions_verified=false`. Es una referencia histórica de 2015 que
incluye claves hoy obsoletas (por ejemplo `BGN` y `ZWL`), no una lista autorizada para
nuevos comprobantes. El catálogo SEE/ISO de 2026 tiene su propio ciclo de vida.
La Tabla 06 PLE del Anexo 3 enumera 62 claves como `KGM`, `LTR` y `NIU`, mientras la carga
original usa 16 claves numéricas (`01`–`15`, `99`) con un símbolo comercial asociado. Son dos
espacios de códigos: la migración `2026_10_25_040000` conserva las 16 filas sin modificarlas
y publica la edición de 62 en tablas PLE versionadas. La API muestra el anexo por defecto y
admite `?version=legacy-seed` para la carga numérica. Esta edición PLE es independiente del
Catálogo SEE 03 y de la revisión UNECE 2021; no confirma vigencia normativa en 2026.
La Tabla 12 PLE del Anexo 3 contiene 47 códigos para operaciones del inventario permanente
(`01`–`38` y `91`–`99`), frente a 17 en la carga inicial. En particular `07` corresponde a
BONIFICACIÓN en el anexo, mientras la carga inicial lo llama PROMOCIÓN. La migración
`2026_10_25_060000` conserva ambas ediciones en tablas versionadas y deja intacta la tabla
operativa para no reinterpretar movimientos del kardex. La API de consulta muestra el anexo por
defecto y `?version=legacy-seed` permite revisar el significado original; la edición PLE 12
es independiente de SEE 12 y de SIRE.
La Tabla 08 del sistema corresponde a la Tabla 8 «Código del libro o registro» de la
R.S. 234-2006/SUNAT, no al Anexo 3 PLE de 2015. La hoja XLS oficial enumera 31 códigos
(`1`–`31`); la R.S. 239-2008/SUNAT incorporó `5-A`, sumando 32. La migración
`2026_10_25_090000` publica esta edición `2008-rs239` en tablas de consulta independientes
y conserva los 20 registros de la carga original como `legacy-seed`. No cambia la tabla
operativa ni traslada su `ple_book_code` o la marca de obligatoriedad al conjunto oficial:
la Tabla 8 no define esas reglas. La vigencia individual en 2026 requiere cotejo normativo;
el espacio de claves tampoco corresponde a SEE 08 o SIRE 08.
La Tabla 11 PLE de aduanas tiene 30 códigos en el Anexo 3 de 2015, frente a 22 en la carga
original. La migración `2026_10_25_070000` conserva esas 22 filas como `legacy-seed` y publica
los 30 pares del anexo para consulta, sin alterar `sunat_table_11_customs_dependencies` ni
afirmar que las dependencias siguen operativas en 2026. Su clave `11` no corresponde a la
Tabla 4 de códigos aduaneros del Anexo 01 SIRE de 2022, que tiene otra edición y otro ámbito.
La Tabla 10 PLE del Anexo 3 de 2015 enumera 61 tipos de comprobante/documento frente a 24
en la carga inicial. La migración `2026_10_25_080000` conserva ambas ediciones y no cambia
`sunat_table_10_electronic_document_types`. La API de consulta ofrece los 61 por defecto con
`document_use_verified=false`: pertenecer a esta tabla no habilita emisión, ni basta para
deducir crédito fiscal. Los indicadores `allows_tax_credit` e `is_electronic_default` de la
carga inicial son metadatos del aplicativo y quedan nulos en las filas de la edición oficial.
El código 10 PLE no es el Catálogo SEE 10 ni la Tabla 11 de documentos RCE/SIRE.
Las Tablas 13 y 14 del Anexo 3 contienen, respectivamente, tres orígenes del catálogo de
existencias (`1` Naciones Unidas, `3` GTIN, `9` otros en el [Anexo I de 2020](https://www.sunat.gob.pe/legislacion/superin/2020/anexos-108-2020.pdf)) y seis métodos de valuación (`1`–`5`,
`9`). La migración `2026_10_25_110000` publica la Tabla 13 de 2020 por defecto y conserva la
de 2015 (`3` GS1/EAN-UCC) como edición consultable; la Tabla 14 conserva el anexo de 2015.
Son referencias versionadas de consulta. No agrega GTIN como tipo de inventario ni cambia
el método de costos operativo: la Tabla 13 no es la Tabla 5 y la Tabla 14 no define por sí
misma el método permitido para cada empresa en 2026.
Las Tablas 15 a 20 del [Anexo 3 SUNAT de 2015](https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf)
se publican con `2026_10_25_120000`: 11 tipos de título, 4 tipos de acciones o
participaciones, 8 tipos de plan de cuentas, 2 tipos de activo fijo, 3 estados de activo
fijo y 3 métodos de depreciación. Son ediciones PLE históricas de solo lectura y con
fuente fijada. El código `01` de la Tabla 17 identifica el tipo PCGE en un libro; no
elige la versión del PCGE de una empresa. Tampoco se asignan tipos de activo o métodos
de depreciación operativos a partir de esas referencias.
Las Tablas 21 y 22 de ese anexo contienen cinco códigos de agrupamiento del costo de
producción valorizado anual y nueve códigos de catálogo de estados financieros. Se
publican con `2026_10_25_130000` como referencias PLE globales y versionadas de 2015.
No configuran el costeo, el sector de la empresa ni sus estados financieros operativos.
