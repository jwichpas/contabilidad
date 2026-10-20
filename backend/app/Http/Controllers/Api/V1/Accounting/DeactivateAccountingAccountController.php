<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Resources\Api\V1\Accounting\AccountingAccountResource;
use App\Models\AccountingAccount;
use App\Services\Security\AuditLoggerService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Single Action Controller para desactivar una cuenta contable (baja lógica).
 * Nunca se elimina físicamente una cuenta contable; además, no puede desactivarse
 * si posee subcuentas hijas aún activas.
 */
final readonly class DeactivateAccountingAccountController
{
    public function __construct(
        private AuditLoggerService $auditLogger,
    ) {}

    public function __invoke(Request $request, int $accountId): AccountingAccountResource
    {
        $account = AccountingAccount::query()->findOrFail($accountId);

        $hasActiveChildren = AccountingAccount::query()
            ->where('path', 'LIKE', $account->path . '.%')
            ->whereRaw('accounting_accounts.is_active IS TRUE')
            ->exists();

        if ($hasActiveChildren) {
            throw new ConflictHttpException(
                'No se puede desactivar una cuenta que posee subcuentas activas. Desactive primero sus subcuentas inferiores.'
            );
        }

        $account->update([
            'is_active' => false,
        ]);

        $this->auditLogger->log(
            eventType: 'ACCOUNT_DEACTIVATED',
            companyId: $account->company_id,
            userId: $request->user()?->id,
            auditableType: AccountingAccount::class,
            auditableId: (string) $account->id,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false],
            request: $request,
            severity: 'WARNING',
        );

        return new AccountingAccountResource($account->fresh() ?? $account);
    }
}
