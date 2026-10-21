# PostgreSQL 18 como version objetivo del ERP contable

**Decision del 21/10/2026:** PostgreSQL 18 es la version principal para nuevas migraciones, RLS, funciones, triggers y pruebas de integracion del ERP. **Supabase es solo entorno de desarrollo**, no condicion de produccion.

## Estados verificados

| Entorno | Version | Resultado |
|---|---|---|
| PostgreSQL Docker aislado | **18.6** | F1.4 PASS: migraciones, cinco funciones, RLS audit logs, 12 pruebas y 37 aserciones |
| PostgreSQL Docker opcional | 17 | Compatibilidad previa PASS |
| GitHub Actions | **18** | Workflow adaptado y version comprobada; ejecucion real pendiente |
| Supabase Dev contabilidad-database | **17.11** | Activo, sin cambio de version ni aplicacion F1.4 |
| Produccion futura | **18** | Objetivo, sin instancia desplegada |

## Reglas de arquitectura

1. Las migraciones **portables del ERP** deben ejecutar sobre PostgreSQL 18 estandar, sin acoplarse al ID de Supabase ni a sus esquemas internos. Mantener aparte integraciones exclusivas de Supabase (Data API, roles internos y cambios de credenciales).
2. PostgreSQL 18 es **predeterminado en Docker y CI**. La prueba de Docker 17 queda como control optativo de compatibilidad mientras Supabase Dev se mantenga en 17.11.
3. Mantener en Supabase Dev las empresas ficticias, el trabajo de lectura y las migraciones controladas compatibles con 17; las funciones nuevas exclusivas de PG18 no pueden ejecutarse alli hasta que el proveedor admita upgrade a 18.
4. La futura base productiva sera PostgreSQL 18 administrado o autogestionado, con roles restringidos, FORCE RLS, TLS y backups. No asumir que una instalacion PostgreSQL generica incluye Supavisor, Storage, Realtime o Auth de Supabase.
5. Ejecutar pruebas DML destructivas solo en Docker efimero. Nunca ejecutar migrate:fresh ni la suite de escrituras en Supabase Dev.

## Comandos

Desde C:\Proyectos\contabilidad\backend en PowerShell:

    # PostgreSQL 18, valor predeterminado
    .\scripts\phase1-isolated-writes-docker.ps1

    # Verificacion opcional de compatibilidad con Supabase Dev
    .\scripts\phase1-isolated-writes-docker.ps1 -PostgresVersion 17

El runner Docker confirma la version real, controla la identidad del contenedor, valida una por una las migraciones, comprueba los RLS y JUnit 12/12; elimina la base temporal.

## Estado real de Supabase y problema administrativo pendiente

El proyecto Dev esta en PostgreSQL 17.11. Cambiar version en Docker, PHP o Laravel **no actualiza Supabase alojado**. La disponibilidad de la version 18 en la opcion Upgrade project del Dashboard **no esta confirmada**; consultar el panel de Supabase antes de cualquier upgrade, con respaldo e inspeccion de extensiones.

La migracion F1.4 Dev fallo por una URI administrativa defectuosa: invalid sslmode value: "connect_timeout=30". El preflight posterior muestra host, puerto, base, SSL y usuario administrativos vacios en la configuracion Laravel. **No es un fallo de PostgreSQL 17 y no se arregla pasando a 18.** No ejecutar migraciones contra Dev hasta que el preflight sea satisfactorio.

    Test-Path .\.env
    php scripts/phase1-admin-connection-preflight.php

Verificar variables DB_MIGRATIONS_HOST, PORT, DATABASE, USERNAME, SSLMODE y PASSWORD en el archivo local privado, o definir una URL administrativa correcta (evitando configuraciones conflictivas). No compartir secretos ni archivos .env.

## Criterios de promocion

Una nueva version de PostgreSQL en un entorno persistente requiere backup probado, verificacion de extensiones, roles, RLS, tablas contables, triggers, migraciones y suite de integracion. GitHub Actions sigue pendiente de una corrida CI exitosa.

Fuentes: https://supabase.com/docs/guides/platform/upgrading


## Continuación 21/10/2026 — diagnóstico sin SQL y GitHub Actions

El usuario confirmó **PostgreSQL 18.6 Docker F1.4 PASS** (12 pruebas/37 aserciones). La validación de configuración administrativa `php scripts/phase1-admin-connection-preflight.php` mostró que **host, puerto, base, usuario y SSL estaban vacíos**, sin URI de migraciones. Es un problema del `backend/.env` local (ausente, incompleto o variables vacías); **no implica incompatibilidad con PostgreSQL 18**.

Se actualizó el preflight para mostrar si `backend/.env` existe, distinguir host directo o pooler de sesión, comprobar contraseña solo como indicador SI/NO, validar TLS y detectar URI con parámetros separados de forma incorrecta. **No conecta a PostgreSQL, no muestra credenciales y no altera el archivo .env.**

Desde Windows, dentro de `backend`:

```powershell
Test-Path .\.env
php scripts/phase1-admin-connection-preflight.php
```

Si falta el archivo, crear `backend/.env` a partir de `backend/.env.example` solo cuando NO existe el original y añadir credenciales privadas válidas. Para conexión directa Supabase Dev, mantener `DB_MIGRATIONS_URL=` vacía y configurar `DB_MIGRATIONS_HOST=db.bnjwxphwtkhblvhsnylj.supabase.co`, puerto 5432, base postgres, usuario postgres, contraseña privada y SSL require. La conexión por pooler de sesión usa host de pooler y usuario postgres con sufijo de proyecto. **No ejecutar migraciones hasta que preflight sea PASS y se compruebe destino real de conexión.** El servidor Dev sigue en PG17.11; PG18 es motor principal del ERP y pruebas Docker.

**GitHub Actions continúa bloqueado antes de ejecutar jobs**: la corrida [#39678580152](https://github.com/jwichpas/contabilidad/actions/runs/39678580152) terminó failure inmediatamente (fecha creación y actualización coincidentes); su API de jobs devolvió total_count=0. Corridas recientes muestran el mismo patrón. Sin logs ni jobs, la causa no puede atribuirse al framework ni a PostgreSQL 18. Revisar la página de la corrida (aviso de workflow inválido, permisos, runners o facturación) y la configuración de Actions del repositorio. No generar reintentos en bucle ni declarar CI PASS.
