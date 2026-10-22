<?php

declare(strict_types=1);

use App\Support\Company\GlobalReferenceReadiness;

it('accepts the pinned 13-table global reference manifest and expected PCGE versions', function (): void {
    $counts = [
        'currencies' => 3,
        'accounting_standards' => 43,
        'financial_statement_rubrics' => 48,
        'sunat_table_01_payment_methods' => 20,
        'sunat_table_02_identity_document_types' => 5,
        'sunat_table_03_financial_institutions' => 33,
        'sunat_table_05_inventory_types' => 6,
        'sunat_table_06_measurement_units' => 16,
        'sunat_table_08_electronic_books' => 20,
        'sunat_table_10_electronic_document_types' => 24,
        'sunat_table_11_customs_dependencies' => 22,
        'sunat_table_12_operation_types' => 17,
        'accounting_account_templates' => 3401,
    ];
    $manifest = [
        'schema_version' => 1,
        'dataset' => 'nexus-global-reference-2026-10-21-v1',
        'counts' => $counts,
        'plan_versions' => ['PCGE_2019' => 1764, 'PCGE_2026' => 1637],
    ];

    expect(GlobalReferenceReadiness::parseExpectedCounts(json_encode($manifest, JSON_THROW_ON_ERROR)))
        ->toEqual($counts);

    $wrong = $manifest;
    $wrong['plan_versions']['PCGE_2026'] = 1636;
    expect(GlobalReferenceReadiness::parseExpectedCounts(json_encode($wrong, JSON_THROW_ON_ERROR)))
        ->toBeNull();

    $wrong = $manifest;
    $wrong['dataset'] = 'unreviewed-v2';
    expect(GlobalReferenceReadiness::parseExpectedCounts(json_encode($wrong, JSON_THROW_ON_ERROR)))
        ->toBeNull();

    $wrong = $manifest;
    unset($wrong['counts']['sunat_table_12_operation_types']);
    expect(GlobalReferenceReadiness::parseExpectedCounts(json_encode($wrong, JSON_THROW_ON_ERROR)))
        ->toBeNull();
});
