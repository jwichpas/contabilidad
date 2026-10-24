<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Pure account-to-entity rule, shared by all new manual journal lines. */
final class JournalEntityRequirement
{
    /** Called within TenantContext transaction; hold entity status stable until commit. */
    public static function validateSelection(int $companyId, string $type, bool $requiresAuxiliary, ?int $entityId): void
    {
        $entity = null;
        if ($entityId !== null) {
            $entity = DB::table('entities')->where('company_id', $companyId)->where('id', $entityId)
                ->sharedLock()->first(['is_active', 'is_client', 'is_supplier', 'is_employee',
                    'is_shareholder', 'is_financial_institution', 'is_tax_authority']);
            if ($entity === null) {
                throw new DomainException('Anexo ajeno o inexistente.');
            }
        }
        self::validate($type, $requiresAuxiliary, $entity === null ? null : (array) $entity);
    }

    public static function validate(string $type, bool $requiresAuxiliary, ?array $entity): void
    {
        $role = match ($type) {
            'NONE', 'ANY_THIRD_PARTY' => null,
            'CUSTOMER' => 'is_client',
            'SUPPLIER' => 'is_supplier',
            'EMPLOYEE' => 'is_employee',
            'SHAREHOLDER' => 'is_shareholder',
            'FINANCIAL_INSTITUTION' => 'is_financial_institution',
            'TAX_AUTHORITY' => 'is_tax_authority',
            default => throw new DomainException('Tipo de anexo no reconocido en la cuenta.'),
        };
        if ($entity === null) {
            if ($type !== 'NONE' || $requiresAuxiliary) {
                throw new DomainException('La cuenta requiere un anexo activo.');
            }
            return;
        }
        if (! ($entity['is_active'] ?? false)) {
            throw new DomainException('El anexo seleccionado está inactivo.');
        }
        if ($role !== null && ! ($entity[$role] ?? false)) {
            throw new DomainException('El anexo no cumple el tipo exigido por la cuenta: '.$type.'.');
        }
    }
}
