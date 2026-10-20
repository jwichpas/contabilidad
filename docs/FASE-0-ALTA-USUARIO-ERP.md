# Fase 0 — Alta controlada de usuario Laravel Sanctum

**Fecha:** 2026-10-20  
**Estado:** script preparado en GitHub/main; **no ejecutado**, **ningún INSERT/UPDATE aplicado**.  
**Base objetivo:** Supabase `contabilidad-database`; backend con rol operativo `app_backend`.

## Contexto y evidencia de solo lectura

El usuario autorizó **preparar** el alta de `elcontador@gmail.com` para la empresa ficticia existente, con confirmación del rol y cambios antes de escribir. Ese correo se muestra en `auth.users` de Supabase Authentication, pero el diagnóstico local de Laravel confirmó que **no existe en `public.users`**. La autenticación utilizada por Vue es Laravel Sanctum, no la API de Supabase Auth. **No copiar contraseñas de `auth.users`**.

SQL de lectura (2026-10-20): 1 empresa activa (`companies.id=1`, `Corporación Peruana de Distribución S.A.C.`), 1 usuario, 1 membresía activa (`CHIEF_ACCOUNTANT`), 28 tokens, 2 asientos y 18 migraciones; 39 auditorías, que pueden incrementar por intentos fallidos de login. `app_backend` dispone de SELECT/INSERT/UPDATE en `users` y `company_user` y acceso a las secuencias; `company_user` tiene ENABLE/FORCE RLS y política de INSERT condicionada a `app.current_company_id`. `users.current_company_id` referencia la membresía mediante FK compuesta, por lo que el orden correcto es: crear usuario sin empresa activa, establecer contexto LOCAL y crear membresía, asignar empresa al usuario, validar y COMMIT.

## Propuesta pendiente de confirmación

| Campo | Propuesta |
|---|---|
| Correo | `elcontador@gmail.com` |
| Tabla | `public.users` (no `auth.users`) |
| Nombre visible inicial | `Contador de Desarrollo` |
| Empresa | ID `1`: Corporación Peruana de Distribución S.A.C. |
| Rol | **`ACCOUNTANT` (Contador Analista)** — recomendado por mínimo privilegio |
| Vigencia | Membresía activa; tokens nuevos Sanctum a **12 horas** |
| Contraseña | Nueva, privada, **no igual a la usada en Supabase Auth ni a una contraseña compartida en el chat** |
| Efectos si se confirma el alta | **+1 usuario y +1 membresía**; sin borrar usuarios ni modificar asientos, cuentas o catálogos |

**Se necesita confirmar expresamente el rol y el nombre propuestos antes de ejecutar `--apply`.** Un rol `CHIEF_ACCOUNTANT` o `ADMIN` requiere aprobación específica.

## Script preparado: modo predeterminado SOLO LECTURA

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only
cd backend
php -l scripts/phase0-provision-erp-user.php
php scripts/phase0-provision-erp-user.php --email=elcontador@gmail.com --company-id=1 --role=ACCOUNTANT
```

El preflight valida `APP_ENV=local`, proyecto y rol `app_backend`, empresa activa, existencia/duplicidad del correo, política RLS y la línea base, **sin crear nada**. No debe utilizarse `php artisan db:seed`, `migrate:fresh`, SQL administrativo o actualización directa de `auth.users`.

## Ejecución eventual (SOLO tras aprobar rol y datos)

El script tiene modo explícito `--apply`, bloqueado si faltan `ERP_PROVISION_APPROVED=YES_CREATE_NEW_ERP_USER` y `ERP_PROVISION_PASSWORD` en el proceso local. No pasar contraseñas por argumento, Git, capturas de pantalla ni chat. La contraseña nueva debe tener 16–128 caracteres y diversidad de caracteres.

Se recomienda crear la contraseña mediante entrada segura de PowerShell y pasarla únicamente como variable de entorno temporal del proceso local; después limpiar esa variable inmediatamente y no compartir su valor. El script inicia una transacción en `pgsql`, aplica `set_config('app.current_company_id', ..., true)` **solo en esa transacción**, inserta usuario y membresía, comprueba vínculo y conteos antes de confirmar. Ante error utiliza rollback. No se ejecutó y no se ha validado con `php -l` en el equipo Windows ni con una conexión de escritura.

Si el usuario ya existe al repetir la comprobación, **bloquear en vez de sobrescribir contraseña o duplicar membresía**. La primera autenticación real emitirá un token y registrará auditoría, por lo que los conteos entonces variarán.

## Seguridad y reversión

No hay backup de recuperación comprobado; no borrar ni alterar la única cuenta ya existente. Después de un alta autorizada: verificar rol/empresa, que `users=2` y `company_user=2` sin variar asientos/cuentas, y comprobar login Laravel con la contraseña nueva. Para dar de baja posteriormente se requiere un procedimiento independiente y aprobación: **no hacer DELETE automático**, especialmente ante FKs.

**Estado:** pendiente preflight local y confirmación de rol. Fase 0 sigue abierta.
