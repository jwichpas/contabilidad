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
        if (! $required) {
            throw new DomainException('La cuenta no habilita un área de flujo de efectivo.');
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

    public static function validateCompanyArea(bool $required, ?array $area): void
    {
        if (! $required && $area !== null) {
            throw new DomainException('La cuenta no habilita un área organizativa.');
        }
        if ($required && $area === null) {
            throw new DomainException('La cuenta requiere un área organizativa activa.');
        }
        if ($area !== null && ! ($area['is_active'] ?? false)) {
            throw new DomainException('El área organizativa está inactiva.');
        }
    }

    /** Outer tenant transaction holds shared locks through posting. */
    public static function validateSelection(int $companyId, bool $required, string $category,
        ?int $companyAreaId, ?int $cashFlowAreaId, bool $requiresCompanyArea = false): void
    {
        // Never accept a dimension that the account does not require.
        if (! $requiresCompanyArea && $companyAreaId !== null) {
            throw new DomainException('La cuenta no habilita un área organizativa.');
        }
        if (! $required && $cashFlowAreaId !== null) {
            throw new DomainException('La cuenta no habilita un área de flujo de efectivo.');
        }
        $companyArea = null;
        if ($companyAreaId !== null) {
            $companyArea = DB::table('company_areas')->where('company_id', $companyId)->where('id', $companyAreaId)
                ->sharedLock()->first(['is_active']);
            if ($companyArea === null) {
                throw new DomainException('Área organizativa ajena o inexistente.');
            }
        }
        self::validateCompanyArea($requiresCompanyArea, $companyArea === null ? null : (array) $companyArea);
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
