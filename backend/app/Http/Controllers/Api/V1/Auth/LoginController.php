<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Models\User;
use App\Services\Tenant\ListUserCompaniesService;
use App\Services\Security\AuditLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Single Action Controller para autenticación stateless mediante Laravel Sanctum.
 * Devuelve el token personal y el listado de empresas activas a las que pertenece el usuario.
 */
final readonly class LoginController
{
    public function __construct(
        private AuditLoggerService $auditLogger,
        private ListUserCompaniesService $companyListing,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{email: string, password: string, device_name?: string} $validated */
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $email = strtolower(trim($validated['email']));

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            $this->auditLogger->log(
                eventType: 'AUTH_LOGIN_FAILED',
                companyId: null,
                userId: $user?->id,
                auditableType: User::class,
                auditableId: (string) ($user?->id ?? 'unknown'),
                oldValues: null,
                newValues: ['attempted_email' => $email],
                request: $request,
                severity: 'WARNING',
            );

            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        // Verificar la lectura de empresas ANTES de generar el token Sanctum:
        // asi un error de listado no deja un token persistido para un login 500.
        // Reutiliza la misma politica RLS y SQL BOOLEAN que /tenant/companies.
        $companies = $this->companyListing->forUser($user);

        // Este login no pasa por SessionGuard: actualizar explícitamente el
        // coste/algoritmo después de verificar la contraseña. La comparación
        // del hash anterior evita sobrescribir un cambio concurrente de clave.
        if (Hash::needsRehash($user->password)) {
            $updated = User::query()
                ->whereKey($user->getKey())
                ->where('password', $user->password)
                ->update(['password' => Hash::make($validated['password'])]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'email' => ['Las credenciales proporcionadas son incorrectas.'],
                ]);
            }
        }

        $deviceName = $validated['device_name'] ?? 'spa-web-client';
        // Fecha individual de expiracion (12 h) + limite global 720 min de
        // Sanctum: incluso tokens antiguos con expires_at NULL dejaran de ser
        // aceptados tras 12 horas desde created_at.
        $expiresAt = now()->addHours(12);
        $plainTextToken = $user->createToken($deviceName, ['*'], $expiresAt)->plainTextToken;

        $this->auditLogger->log(
            eventType: 'AUTH_LOGIN',
            companyId: $user->current_company_id,
            userId: $user->id,
            auditableType: User::class,
            auditableId: (string) $user->id,
            oldValues: null,
            newValues: ['device_name' => $deviceName],
            request: $request,
            severity: 'INFO',
        );

        return response()->json([
            'data' => [
                'token' => $plainTextToken,
                'user' => [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'current_company_id' => $user->current_company_id,
                ],
                'companies' => $companies,
            ],
        ]);
    }
}
