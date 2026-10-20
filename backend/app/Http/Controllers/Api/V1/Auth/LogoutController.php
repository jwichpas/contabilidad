<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Models\User;
use App\Services\Security\AuditLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single Action Controller para revocar el token personal Sanctum de la sesión actual.
 */
final readonly class LogoutController
{
    public function __construct(
        private AuditLoggerService $auditLogger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            $this->auditLogger->log(
                eventType: 'AUTH_LOGOUT',
                companyId: $user->current_company_id,
                userId: $user->id,
                auditableType: User::class,
                auditableId: (string) $user->id,
                oldValues: null,
                newValues: null,
                request: $request,
                severity: 'INFO',
            );

            $user->currentAccessToken()?->delete();
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}
