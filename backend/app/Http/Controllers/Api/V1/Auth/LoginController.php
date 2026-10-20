<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Models\User;
use App\Services\Security\AuditLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $deviceName = $validated['device_name'] ?? 'spa-web-client';
        $plainTextToken = $user->createToken($deviceName)->plainTextToken;

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

        $user->load(['companies' => static function ($query): void {
            $query->where('companies.is_active', true)
                ->wherePivot('is_active', true);
        }]);

        $companies = $user->companies->map(static function (\App\Models\Company $company): array {
            return [
                'id' => $company->id,
                'uuid' => $company->uuid,
                'tax_id' => $company->tax_id,
                'business_name' => $company->business_name,
                'legal_address' => $company->legal_address,
                'fiscal_regime' => 'Régimen General · PRICO',
                'role' => $company->pivot->role?->value ?? (string) $company->pivot->role,
            ];
        })->values()->all();

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
