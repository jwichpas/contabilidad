# Cierre tributario anual

El módulo **Contabilidad → Cierre tributario anual** implementa una liquidación asistida de Régimen General 2025 en PEN. Calcula y conserva cada revisión por empresa y ejercicio. Usa referencias al Diario, auxiliares y constancias para sustentar importes capturados.

## Operación

1. Registrar el resultado antes de participación e impuesto según el corte de trabajo. Cargar adiciones y deducciones con bases contable y fiscal, clasificación, cuenta opcional y sustento.
2. Ingresar pérdidas aplicadas, tasa y participación deducible verificadas, pagos a cuenta mensuales, ITAN pagado y aplicado, y el saldo disponible y uso del CIPRL. Si corresponde presentar el destino analítico, registrar los importes de participación para 94 y 95.
3. Registrar diferencias temporarias con efecto en resultados, impuesto inicial, diferencia final, tasa y sustento. El reconocimiento del activo requiere confirmación de su recuperabilidad. Los saldos reconocidos al inicio y al cierre permiten reflejar una reversión.
4. Guardar para calcular. La revisión conserva las entradas, los resultados, la versión de cálculo y el responsable. ADMIN, CHIEF_ACCOUNTANT y ACCOUNTANT pueden editar y enviar a revisión. ADMIN y CHIEF_ACCOUNTANT pueden devolver, aprobar o abrir una rectificación con motivo. Las revisiones previas se consultan desde el historial y permanecen inmutables.
5. Descargar el papel de trabajo CSV o imprimirlo. Usar el mapeo de casillas 103 y 105 para revisión y traslado manual. Desde la revisión aprobada vigente, **Preparar en el Diario** abre una propuesta con fecha, tipo, glosa de revisión, referencias de cuenta e importes. El contador selecciona el subdiario, las subcuentas y las dimensiones antes de registrar. Los traslados a resultado y acumulados usan CLOSING. El expediente conserva la propuesta y el Diario conserva el asiento registrado.

El cálculo monetario usa BCMath. El año fuera de alcance se rechaza. Los anticipos mensuales aceptan un periodo único y validan el importe cubierto contra el determinado. La suma de ITAN aplicado a anticipos y regularización está limitada al pago efectivo informado. El CIPRL está limitado tanto por el saldo disponible informado como por la deuda. Las fechas, cuotas y elegibilidad de los créditos se sustentan manualmente.

## Balance de Comprobación de trabajo

Abra el panel **Balance de Comprobación para el FV 710** y pulse **Consultar mayor**. El corte abarca el ejercicio 2025 y separa los saldos iniciales, movimientos y transferencias. Los asientos de ajuste ordinario permanecen en el movimiento. Los extornos de una apertura o un cierre dentro del mismo ejercicio conservan la clasificación del asiento de origen. Si existen saldos históricos y asientos de apertura, el usuario debe elegir cuál usar para evitar duplicarlos.

El reporte usa los códigos de la hoja **Balance de Comprobación 2025** publicada por SUNAT. Agrupa cada cuenta operativa por la coincidencia vigente más larga y conserva el detalle de origen. El papel de trabajo solo se descarga si el mayor cuadra, se resolvió la apertura y todas las cuentas con datos tienen correspondencia. El CSV conserva decimales contables y sirve para revisar los importes de la plantilla. Al abrirlo en Excel, importe la columna de códigos como texto para conservar los ceros iniciales. La hoja oficial calcula por separado las sumas del mayor y la cuenta de resultado. El CSV es una lectura del mayor al momento de la consulta.

El Estado de Resultados conserva los movimientos del ejercicio antes de los traslados clasificados como **CLOSING**, incluso cuando esos asientos se extornan ese mismo año. El ESF y los saldos del mayor siguen incluyendo los asientos contabilizados.

[Plantilla oficial y condiciones de uso del Balance de Comprobación](https://renta.sunat.gob.pe/empresas/que-es-el-balance-de-comprobacion)

## Instalación y evidencia

Aplicar únicamente la migración indicada mediante la conexión de migraciones del entorno de desarrollo autorizado. La tabla usa RLS forzada y concede al rol de aplicación lectura e inserción.

```bash
cd backend
php artisan migrate --database=pgsql_migrations --path=database/migrations/2026_11_20_000000_create_company_annual_tax_close_revisions.php
php artisan test --filter=AnnualTaxCloseTest
```

El caso desidentificado se encuentra en `backend/tests/Fixtures/annual_tax_close_sunat_2025.json`. Los reparos sin bases desglosadas en la fixture usan una base de prueba normalizada equivalente a la diferencia esperada. El test HTTP verifica los 39 ajustes, las cifras hasta el saldo a regularizar, el diferido, el cuadre de los cinco asientos de referencia, el historial, permisos y rechazos de doble uso de créditos.

Quedan pendientes los conectores y las reglas por concepto de los auxiliares, el calendario y elegibilidad por cuota o certificado, la contabilización automática con cortes de cierre, el archivo TXT de importación del Balance de Comprobación y las secciones restantes del FV 710 Completo. El expediente aprobado registra una aprobación interna.

[Referencia SUNAT 2025](https://renta.sunat.gob.pe/sites/default/files/inline-files/Caso%20practico%20Regimen%20General%20%281%29.pdf) · [Ayuda del FV 710 Completo 2025](https://renta.sunat.gob.pe/sites/default/files/inline-files/COMPL2025_Ayuda%20FV_710_completo_2025_V22122025_0.pdf)
