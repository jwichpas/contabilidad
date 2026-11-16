# Registro persistente de los 14 asientos de prueba en el ERP (Docker)

**No es una carga de operaciones empresariales reales.** Son transacciones simuladas que se registrarán **de verdad** en el Libro Diario del entorno de desarrollo Docker, con el motor POST de Laravel, auditoría, validaciones, RLS y posibilidad de consulta desde la interfaz.

## Destino autorizado

- Contenedor: `contabilidad-pg18-local`, etiqueta `contabilidad.local=pg18-dev`, PostgreSQL 18.
- Puerto local: `127.0.0.1:55433`.
- Base: `contabilidad_local`; conexión de aplicación `app_backend`, no propietario.
- Empresa: `EMPRESA DEMO PCGE 2019 - SOLO DOCKER`, RUC ficticio **20999990101**.
- Usuario ficticio: `demo.pg18@example.test` con membresía ADMIN.
- Periodo: enero de 2026; 14 asientos J01–J14, con descripciones `[F5-2026-Jxx]`.
- **No utilizar** sobre Neon, otras empresas, ni datos tributarios o contables reales.

## Ejecución en PowerShell de Windows

Desde `C:\Proyectos\contabilidad`:

```powershell
git fetch origin test/ciclo-contable-eeff-niif-2026
git switch test/ciclo-contable-eeff-niif-2026
git pull --ff-only
cd backend
.\scripts\cargar-ciclo-contable-pg18-local.ps1 -Accion Plan
```

Verifica los **19 mapeos de cuenta** PCGE y los requisitos de anexo, centro de costo y flujo. Si informa `BLOQUEO`, **no ejecutar Aplicar**; corregir el problema previo y volver a ejecutar Plan. El script es deliberadamente restrictivo: se cancela ante cuentas inexistentes, empresa distinta, enero ocupado, RLS incompleto, tipo de cambio inválido o configuraciones desconocidas.

Si Plan termina en `PASS`:

```powershell
.\scripts\cargar-ciclo-contable-pg18-local.ps1 -Accion Simular
```

**Simular** ejecuta las 14 llamadas HTTP Laravel y consulta ESF/EFE dentro de una transacción PostgreSQL que **siempre se revierte**. Así se descubre qué validación de negocio no satisface la fixture sin dejar asientos parciales.

Si Simular termina en `PROBE PASS` y `ROLLBACK`, para **registrar** en el Libro Diario:

```powershell
.\scripts\cargar-ciclo-contable-pg18-local.ps1 -Accion Aplicar
.\scripts\cargar-ciclo-contable-pg18-local.ps1 -Accion Verificar
```

**Aplicar** crea antes un `pg_dump -Fc` en `%LOCALAPPDATA%\Contabilidad\Backups`, sin aceptar archivos vacíos, y registra los 14 comprobantes como una unidad. Si falla un POST, se revierte el lote. Si registra `COMMIT`, **no repetir Aplicar**: la protección anti-duplicados bloquea el segundo intento.

Finalmente, entra en el ERP con la empresa **EMPRESA DEMO PCGE 2019 - SOLO DOCKER** y pulsa **Libro Diario → Actualizar**. La pantalla carga los últimos 50 asientos; debería mostrar los 14 registros de enero.

## Qué valida y qué todavía NO hace

- Sí: partida doble, persistencia PostgreSQL, subdiarios, contador de asientos, anexos, centros y áreas, importes bimoneda, seguridad por empresa, auditoría y consulta de ESF/EFE preliminares.
- Sí: muestra `[GAP EEFF]` y `[GAP EFE]` si la presentación real difiere del patrón esperado: **no lo oculta ni afirma cumplimiento NIIF**.
- No: generar documentos tributarios SUNAT, Registro de Compras/Ventas con facturas, Kardex de inventario físico, NIIF 9 con TIE ni los cuatro estados NIIF completos. El registro de ventas/compras se simula **como vouchers**, no documentos comerciales reales.
- Tipos de cambio: si no hay una cotización local en esas fechas, inserta **S/ 3.750000/USD puramente sintético** mediante el propio endpoint de asientos; preserva la tasa que ya exista. No es una tasa SUNAT histórica. Solo válido para el laboratorio.
- Los rubros de resultados actualmente pueden tener defectos (por ejemplo, clase 8 en el resumen); el resultado que arroje el ERP se contrasta con la referencia y los errores quedan pendientes de corregir.

**Ninguna ejecución real ha sido hecha desde ChatGPT contra tu Docker**: este contenedor está en tu Windows y no está accesible en mi sesión. Solo la salida `[COMMIT]` de tu propio PowerShell confirma que los asientos se han registrado.
