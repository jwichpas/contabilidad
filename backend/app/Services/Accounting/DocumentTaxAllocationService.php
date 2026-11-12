<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Clasificación interna compatible con las distinciones tributarias SUNAT:
 * RCE: compras gravadas exclusivas, mixtas, destinadas a no gravadas,
 *      sin crédito fiscal y adquisiciones no gravadas.
 * RVIE: ventas gravadas, exoneradas, inafectas y exportaciones.
 *
 * No valida por sí sola el derecho material al crédito ni remite al SIRE.
 */
final class DocumentTaxAllocationService
{
    public const PURCHASE_CATEGORIES = [
        'P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED',
        'P_TAXED_NO_CREDIT', 'P_NON_TAXED',
    ];

    public const SALE_CATEGORIES = ['S_TAXED', 'S_EXONERATED', 'S_UNAFFECTED', 'S_EXPORT'];

    /** @return list<string> */
    public static function categories(string $module): array
    {
        return $module === 'PURCHASE' ? self::PURCHASE_CATEGORIES : self::SALE_CATEGORIES;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{allocations: list<array{category:string,base_amount:string,tax_amount:string,creditable_tax_amount:string}>, base:Decimal,tax:Decimal,creditable:Decimal,total:Decimal}
     */
    public static function normalize(string $module, array $data): array
    {
        $items = $data['tax_allocations'] ?? null;
        if (! is_array($items) || $items === []) {
            if (! isset($data['taxable_amount'], $data['tax_amount'])) {
                throw ValidationException::withMessages([
                    'tax_allocations' => ['Indique al menos una clasificación tributaria.'],
                ]);
            }
            $tax = Decimal::ofMonetary((string) $data['tax_amount']);
            $category = $module === 'PURCHASE'
                ? ($tax->isPositive() ? ((string) $data['document_type'] === '03' ? 'P_TAXED_NO_CREDIT' : 'P_TAXED_EXCLUSIVE') : 'P_NON_TAXED')
                : ($tax->isPositive() ? 'S_TAXED' : 'S_UNAFFECTED');
            $items = [[
                'category' => $category,
                'base_amount' => $data['taxable_amount'],
                'tax_amount' => $data['tax_amount'],
                'creditable_tax_amount' => $module === 'PURCHASE' && $category === 'P_TAXED_EXCLUSIVE'
                    ? $data['tax_amount'] : '0',
            ]];
        }

        if (count($items) > count(self::categories($module))) {
            throw ValidationException::withMessages(['tax_allocations' => ['Se excedió el número de clasificaciones permitidas.']]);
        }

        $totalBase = Decimal::zero();
        $totalTax = Decimal::zero();
        $totalCredit = Decimal::zero();
        $seen = [];
        $result = [];

        foreach ($items as $index => $item) {
            $category = (string) $item['category'];
            if (! in_array($category, self::categories($module), true) || isset($seen[$category])) {
                throw ValidationException::withMessages([
                    "tax_allocations.$index.category" => ['Categoría no permitida o repetida para este registro.'],
                ]);
            }
            $seen[$category] = true;
            $base = Decimal::ofMonetary((string) $item['base_amount']);
            $tax = Decimal::ofMonetary((string) ($item['tax_amount'] ?? '0'));
            $credit = Decimal::ofMonetary((string) ($item['creditable_tax_amount'] ?? '0'));

            if (! $base->isPositive() || $tax->isNegative() || $credit->isNegative() || $credit->sub($tax)->isPositive()) {
                throw ValidationException::withMessages([
                    "tax_allocations.$index.base_amount" => ['Importes inválidos para base, tributo o crédito fiscal.'],
                ]);
            }
            $isTaxed = in_array($category, [
                'P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED',
                'P_TAXED_NO_CREDIT', 'S_TAXED',
            ], true);
            if ($isTaxed && ! $tax->isPositive()) {
                throw ValidationException::withMessages([
                    "tax_allocations.$index.tax_amount" => ['Una operación clasificada como gravada necesita un IGV positivo.'],
                ]);
            }
            if (! $isTaxed && ! $tax->isZero()) {
                throw ValidationException::withMessages([
                    "tax_allocations.$index.tax_amount" => ['No corresponde IGV en operaciones no gravadas, exoneradas, inafectas o exportaciones.'],
                ]);
            }
            if ($module === 'SALE' && ! $credit->isZero()) {
                throw ValidationException::withMessages([
                    "tax_allocations.$index.creditable_tax_amount" => ['El crédito fiscal solo corresponde al Registro de Compras.'],
                ]);
            }
            if ($module === 'PURCHASE') {
                if ($category === 'P_TAXED_EXCLUSIVE') {
                    if ($data['document_type'] === '03') {
                        throw ValidationException::withMessages([
                            "tax_allocations.$index.category" => ['La boleta de compra no genera crédito fiscal automático. Seleccione adquisición sin crédito fiscal.'],
                        ]);
                    }
                    // La clasificación es tributaria: la procedencia definitiva del crédito
                    // se debe cotejar con la propuesta SUNAT y los requisitos legales.
                    if (! $credit->equals($tax)) {
                        throw ValidationException::withMessages([
                            "tax_allocations.$index.creditable_tax_amount" => ['En la clasificación exclusiva, indique el IGV potencialmente creditable íntegro.'],
                        ]);
                    }
                } elseif ($category === 'P_TAXED_MIXED') {
                    if (! array_key_exists('creditable_tax_amount', $item)) {
                        throw ValidationException::withMessages([
                            "tax_allocations.$index.creditable_tax_amount" => ['Indique explícitamente el crédito calculado por prorrata; no se infiere.'],
                        ]);
                    }
                    if ($data['document_type'] === '03' && $credit->isPositive()) {
                        throw ValidationException::withMessages([
                            "tax_allocations.$index.creditable_tax_amount" => ['Una boleta no genera crédito fiscal automáticamente.'],
                        ]);
                    }
                } elseif (! $credit->isZero()) {
                    throw ValidationException::withMessages([
                        "tax_allocations.$index.creditable_tax_amount" => ['Esta categoría no habilita crédito fiscal.'],
                    ]);
                }
            }
            if ($category === 'S_EXPORT' && $data['document_type'] !== '01') {
                throw ValidationException::withMessages([
                    "tax_allocations.$index.category" => ['En este flujo, registre exportaciones con factura (01).'],
                ]);
            }

            $totalBase = $totalBase->add($base);
            $totalTax = $totalTax->add($tax);
            $totalCredit = $totalCredit->add($credit);
            $result[] = [
                'category' => $category,
                'base_amount' => $base->toString(),
                'tax_amount' => $tax->toString(),
                'creditable_tax_amount' => $credit->toString(),
            ];
        }

        if (isset($data['taxable_amount']) && ! $totalBase->equals((string) $data['taxable_amount'])) {
            throw ValidationException::withMessages([
                'taxable_amount' => ['La base total no coincide con la suma de categorías tributarias.'],
            ]);
        }
        if (isset($data['tax_amount']) && ! $totalTax->equals((string) $data['tax_amount'])) {
            throw ValidationException::withMessages([
                'tax_amount' => ['El IGV total no coincide con la suma de categorías tributarias.'],
            ]);
        }

        return [
            'allocations' => $result,
            'base' => $totalBase,
            'tax' => $totalTax,
            'creditable' => $totalCredit,
            'total' => $totalBase->add($totalTax),
        ];
    }
}
