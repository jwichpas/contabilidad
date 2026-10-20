# Fase 0 — cierre técnico y condiciones para pasar a Fase 1

**Fecha:** 20 de octubre de 2026 · **Repositorio:** `jwichpas/contabilidad` · **Rama:** `main`

## Decisión del responsable

El usuario dispone de un solo disco local (`C:`) y **pospone el cifrado individual de los respaldos PostgreSQL hasta el paso a producción**. No activar BitLocker ni exigir la ejecución de `age` durante el desarrollo. El script `backend/scripts/phase0-encrypt-backup-windows.ps1` queda disponible, **sin ejecutar ni validar**. Esta excepción no significa que el archivo esté protegido.

## Evidencia de recuperación aportada el 20/10/2026

Resultado del ensayo local `phase0-restore-rehearsal.ps1` con `C:\CONTA\contabilidad-public-20261020T210205261Z.dump`:

- PASS: SHA256 del respaldo.
- PASS: Docker PostgreSQL 17 sin red ni puertos publicados; preparación del esquema temporal `public` mediante `DROP SCHEMA public RESTRICT`.
- PASS: `pg_restore` finalizó sin errores.
- PASS: coincidieron los conteos de `companies`, `users`, `company_user`, `accounting_accounts`, `journal_entries`, `journal_entry_lines` y `migrations`.
- PASS: 14 tablas con RLS forzado y 5 funciones del esquema `public`.
- PASS: contenedor efímero y datos temporales eliminados.

**Alcance:** recuperación de `public` probada en contenedor independiente, **no** recuperación integral de Supabase (no incluye `auth.users`, Storage, roles y GRANT de producción ni esquema completo de plataforma).

El comando elevado `manage-bde -status C:` informó: `Descifrado completo`, `0.0%`, `Protección desactivada`, `Método de cifrado: Ninguno`. Por tanto, los archivos locales en `C:\CONTA` **no están cifrados en reposo por BitLocker**.

## Plan de protección obligatoria antes de producción

1. Elegir un destino de respaldo seguro con **copia externa e independiente** del equipo. Tener un único disco constituye además un punto único de fallo.
2. Generar una identidad `age`, custodiar la **clave privada fuera de GitHub, del servidor y de la ubicación del respaldo**, y verificar recuperación de la clave.
3. Adaptar y validar el pipeline para cifrar `dump`, `list` y manifiesto; **evitar que copias plaintext permanezcan en discos sin cifrar** después de validar.
4. Probar descifrado, integridad y restauración en PostgreSQL independiente; definir rotación, retención, accesos mínimos y monitoreo.
5. Preparar las migraciones de roles/GRANT y verificar las dependencias externas al esquema `public`, así como RPO/RTO, antes de liberar producción.
6. Revisar el parámetro `-EncryptedDestinationConfirmed` del script de backup: **es solo una declaración**, no detecta automáticamente cifrado. No utilizarlo como evidencia de protección.

**No borrar el respaldo actual antes de contar con otra copia recuperable y protegida. No almacenar la clave privada ni el respaldo en Git.**

## Cierre de Fase 0: alcance y pendientes

| Control | Estado | Evidencia |
| --- | --- | --- |
| Recuperabilidad de `public` | **Aprobado para desarrollo** | Ensayo local reportado por el usuario |
| SHA256, conteos, RLS y limpieza del ensayo | **Aprobado** | Salida completa de PowerShell |
| Login, selección empresa, cuentas y editor (navegación) | **Validación manual reportada** | Registro en `README-PLAN-MAESTRO.md` |
| Pruebas HTTP y frontend previamente ejecutadas | **Aprobadas en su alcance** | Registros históricos del plan; no equivalen a cobertura total |
| Protección cifrada de backups y redundancia | **Diferida a producción por decisión expresa** | Disco único sin cifrar |
| Revisión de 5 advertencias `function_search_path_mutable` | **Pendiente** | Propuesta preparada, sin aplicar DDL |
| Rotación de credencial antiguamente expuesta | **Pendiente** | El retiro del archivo no borra historial Git |
| Revisión global de permisos/roles y pruebas POST/extorno | **Pendiente / programar para Fase 1–2** | No declararlas probadas |
| Auth, Storage y recuperación integral del servidor productivo | **Pendiente de producción** | Fuera del alcance del dump |

**Decisión operativa:** es razonable **iniciar los trabajos de Fase 1 en paralelo a estos pendientes**, manteniendo la Fase 0 como **cierre condicionado**, no certificada para producción. Antes de cualquier despliegue productivo, resolver la protección de los respaldos, credenciales, autorizaciones y recuperación integral.

## Flujo de trabajo

Todos los cambios se versionan en `main`; en Windows se aplican con `git pull --ff-only origin main`. No editar scripts localmente ni crear ramas adicionales. Supabase `contabilidad-database` queda exclusivo para desarrollo; las pruebas destructivas quedan prohibidas sin autorización y entorno separado.
