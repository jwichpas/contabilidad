<?php

declare(strict_types=1);

namespace App\Support\Company;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Catálogos iniciales de naturaleza jurídica y macrosectores; CIIU es un dato independiente. */
final class CompanyEconomicProfile
{
    public const LEGAL_FORMS = [
        'PERSONA_NATURAL', 'EIRL', 'SAC', 'SRL', 'SA', 'SAA', 'SACS', 'OTRA',
    ];

    public const SECTORS = [
        'COMERCIAL', 'SERVICIOS', 'INDUSTRIAL', 'AGRICOLA', 'GANADERIA',
        'PESCA', 'CONSTRUCCION', 'EXTRACTIVA', 'TRANSPORTE_LOGISTICA', 'OTROS',
    ];

    /**
     * Internal sector suggestion by unambiguous CIIU divisions.
     * This is an ERP classification, NOT an additional SUNAT classification.
     * Ambiguous/mixed divisions remain user-editable.
     */
    public static function suggestedSectorForCiiu(string $code): ?string
    {
        if (preg_match('/^[0-9]{4}$/D', $code) !== 1) {
            return null;
        }

        $division = (int) substr($code, 0, 2);

        return match (true) {
            $division >= 45 && $division <= 47 => 'COMERCIAL',
            $division >= 49 && $division <= 53 => 'TRANSPORTE_LOGISTICA',
            $division >= 41 && $division <= 43 => 'CONSTRUCCION',
            $division >= 5 && $division <= 9 => 'EXTRACTIVA',
            $division === 3 => 'PESCA',
            $division >= 10 && $division <= 33 => 'INDUSTRIAL',
            default => null,
        };
    }

    /**
     * Invariantes del perfil que se pueden probar sin conexión PostgreSQL.
     * La existencia oficial de CIIU/sector se verificará con el catálogo SUNAT/INEI.
     *
     * @param array<int, array{sector_code:string,ciiu_code:string,description:string,is_primary:bool}> $activities
     */
    public static function assertValidActivities(array $activities): void
    {
        $ciiuCodes = array_column($activities, 'ciiu_code');
        $primaryCount = count(array_filter(
            $activities,
            static fn (array $activity): bool => (bool) ($activity['is_primary'] ?? false)
        ));

        if (count($activities) < 1 || count($activities) > 10
            || $primaryCount !== 1
            || count($ciiuCodes) !== count(array_unique($ciiuCodes))) {
            throw ValidationException::withMessages([
                'economic_activities' => ['Debe existir una sola actividad principal y no repetir códigos CIIU (máximo 10).'],
            ]);
        }

        foreach ($activities as $index => $activity) {
            $suggested = self::suggestedSectorForCiiu((string) ($activity['ciiu_code'] ?? ''));
            if ($suggested !== null && ($activity['sector_code'] ?? '') !== $suggested) {
                throw ValidationException::withMessages([
                    'economic_activities.'.$index.'.sector_code' => [
                        'El sector interno debe ser '.$suggested.' para este CIIU. Seleccione la actividad nuevamente.',
                    ],
                ]);
            }

            if (! in_array($activity['sector_code'] ?? '', self::SECTORS, true)
                || preg_match('/^[0-9]{4}$/', $activity['ciiu_code'] ?? '') !== 1
                || trim($activity['description'] ?? '') === ''
                || mb_strlen($activity['description'] ?? '') > 200) {
                throw ValidationException::withMessages([
                    'economic_activities' => ['El sector, CIIU y la descripción de cada actividad son obligatorios y válidos.'],
                ]);
            }
        }
    }

    /**
     * Clearly-mappable legal forms from SUNAT RUC Annex 2.
     * SACS and OTRA intentionally have no invented one-to-one mapping.
     */
    public static function assertContributorTypeMatchesLegalForm(string $form,string $type):void
    {
        $known=[
            'PERSONA_NATURAL'=>['02'],
            'EIRL'=>['07'],
            'SRL'=>['28'],
            'SA'=>['26'],
            'SAA'=>['38'],
            'SAC'=>['39'],
        ];
        if(isset($known[$form]) && !in_array($type,$known[$form],true)){
            throw ValidationException::withMessages([
                'sunat_contributor_type_code'=>[
                    'El tipo SUNAT seleccionado no corresponde a la forma jurídica. Revise el anexo 2.',
                ],
            ]);
        }
    }

    public static function assertTaxRegimeEligibility(string $taxRegime, ?string $legalForm): void
    {
        if ($taxRegime === 'NRUS' && $legalForm !== 'PERSONA_NATURAL') {
            throw ValidationException::withMessages([
                'tax_regime' => ['El Nuevo RUS requiere una persona natural elegible.'],
            ]);
        }
    }

    /** Permite desplegar API/Vue antes de que Laravel aplique la migración pendiente. */
    public static function isReady(): bool
    {
        return Schema::hasColumn('company_settings', 'legal_form')
            && Schema::hasTable('company_economic_activities');
    }
}
