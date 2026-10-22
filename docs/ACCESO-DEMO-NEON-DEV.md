# Acceso inicial DEMO — ContaPerú / Neon DEV

**Solo desarrollo:** Neon PostgreSQL 18, rama contabilidad-dev, autenticación Laravel Sanctum. Nunca producción.

La base ya tiene 3 usuarios, 3 empresas y 8 membresías. No insertar usuarios ni ejecutar seeders.

| Usuario | Correo |
|---|---|
| Administrador | demo.admin@contabilidad.test |
| Contador | demo.contador@contabilidad.test |
| Auditor | demo.auditor@contabilidad.test |

Los hashes de contraseña existentes son irreversibles; no hay una contraseña por defecto publicada.

## Configurar la contraseña del administrador

En PowerShell:

```powershell
cd C:\Proyectos\contabilidad
git pull --ff-only origin main
cd backend
php -l scripts/phase2-neon-demo-login.php
php scripts/phase2-neon-connection-diagnostic.php --check
php scripts/phase2-neon-demo-login.php --verify
php scripts/phase2-neon-demo-login.php --reset=contabilidad-dev --user=admin
```

El último comando **modifica y confirma únicamente el hash del administrador DEMO** en Neon DEV. Genera 128 bits de contraseña aleatoria en tu equipo, comprueba el hash con Laravel y muestra la contraseña una sola vez en la terminal. Guarda el secreto en un gestor de contraseñas; no lo publiques en capturas, ChatGPT ni GitHub.

Para configurar otro usuario, cambiar --user=admin por --user=contador o --user=auditor; se restablece una cuenta por operación.

## Ejecutar localmente

Terminal de backend:

```powershell
cd C:\Proyectos\contabilidad\backend
php artisan serve --host=127.0.0.1 --port=8010
```

Terminal de frontend:

```powershell
cd C:\Proyectos\contabilidad\frontend
pnpm dev
```

El frontend Vite utiliza habitualmente el puerto 5180 y reenvía /api al backend 8010. Abre la dirección mostrada en la terminal e ingresa con demo.admin@contabilidad.test y la contraseña recién generada. Si utilizas una vista Vercel, tiene que apuntar a la misma API y a la misma rama Neon DEV: la contraseña no sirve en otra base.

La sesión dura 12 horas. APP_KEY de Laravel NO es la contraseña. La pantalla de períodos aparece vacía porque los ensayos F2.3 anteriores hicieron ROLLBACK.

No volver a ejecutar migraciones, activar matrices, modificar APP_KEY ni crear usuarios nuevos para resolver este acceso.

[Plan maestro](../README-PLAN-MAESTRO.md).
