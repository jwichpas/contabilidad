<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use DomainException;
use Illuminate\Support\Facades\DB;

final class JournalAreaRequirement
{
    public static function validateCashFlow(bool $required, string $category, ?array $area): void
    {
        $categories = ['NONE', 'CASH_EQUIVALENT', 'OPERATING', 'INVESTING', 'FINANCING'];
        if (! in_array($category, $categories, true)) {
            throw new DomainException('Categoría de flujo no reconocida en la cuenta.');
        }
        if ($area === null) {
            if ($required) {
                throw new DomainException('La cuenta requiere un área de flujo de efectivo activa.');
            }
            return;
        }
        if (! ($area['is_active'] ?? false)) {
            throw new DomainException('El área de flujo seleccionada está inactiva.');
        }
        $activity = $area['activity_category'] ?? '';
        if (! in_array($activity, ['CASH_EQUIVALENT', 'OPERATING', 'INVESTING', 'FINANCING'], true)) {
            throw new DomainException('El área de flujo tiene una categoría no reconocida.');
        }
        // A cash/equivalent account can carry operating, investing or financing flows.
        // NONE leaves classification optional; a specific activity must match.
        if (in_array($category, ['OPERATING', 'INVESTING', 'FINANCING'], true) && $activity !== $category) {
            throw new DomainException('El área de flujo no corresponde a la categoría configurada en la cuenta.');
        }
    }

    /** Outer tenant transaction holds shared locks through posting. */
    public static function validateSelection(int $companyId, bool $required, string $category,
        ?int $companyAreaId, ?int $cashFlowAreaId): void
    {
        if ($companyAreaId !== null) {
            $area = DB::table('company_areas')->where('company_id', $companyId)->where('id', $companyAreaId)
                ->sharedLock()->first(['is_active']);
            if ($area === null || ! $area->is_active) {
                throw new DomainException('Área organizativa inactiva, ajena o inexistente.');
            }
        }
        $flow = null;
        if ($cashFlowAreaId !== null) {
            $flow = DB::table('cash_flow_areas')->where('company_id', $companyId)->where('id', $cashFlowAreaId)
                ->sharedLock()->first(['is_active', 'activity_category']);
            if ($flow === null) {
                throw new DomainException('Área de flujo ajena o inexistente.');
            }
        }
        self::validateCashFlow($required, $category, $flow === null ? null : (array) $flow);
    }
}
