<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class DaotReport
{
    public const SOURCE = 'https://www.sunat.gob.pe/legislacion/superin/2016/036-2016.pdf';
    public const UIT = [2025 => 5350, 2026 => 5500];
    public const REVIEWS = 'company_daot_document_reviews';

    public static function due(int $year, int $group): string
    {
        $date = CarbonImmutable::create($year + 1, 3, 1, 0, 0, 0, 'America/Lima');
        while ($date->isWeekend() || $group > 0) {
            if (!$date->isWeekend()) {
                $group--;
            }
            $date = $date->addDay();
        }

        return $date->toDateString();
    }

    public function documents(int $company): Builder
    {
        $review = DB::table(self::REVIEWS)->where('company_id', $company)
            ->selectRaw('DISTINCT ON (document_id) document_id, revision, included, reference, base_pen')
            ->orderBy('document_id')->orderByDesc('revision');
        $export = DB::table('accounting_tax_register_allocations as a')->where('a.company_id', $company)
            ->where('a.category', 'S_EXPORT')->selectRaw('a.tax_register_entry_id, a.base_amount as export_base');
        return DB::table('accounting_document_entries as d')->where('d.company_id', $company)
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'd.journal_entry_id')->on('j.company_id', '=', 'd.company_id'))
            ->join('entities as e', fn ($join) => $join->on('e.id', '=', 'd.entity_id')->on('e.company_id', '=', 'd.company_id'))
            ->leftJoin('accounting_tax_register_entries as r', fn ($join) => $join->on('r.accounting_document_entry_id', '=', 'd.id')->on('r.company_id', '=', 'd.company_id'))
            ->leftJoinSub($review, 'v', 'v.document_id', '=', 'd.id')
            ->leftJoinSub($export, 'x', 'x.tax_register_entry_id', '=', 'r.id')
            ->where('j.status', 'POSTED')
            ->select(['d.id', 'd.module', 'd.document_type', 'd.series', 'd.number', 'd.issue_date', 'd.currency', 'd.taxable_amount',
                'v.revision', 'v.included', 'v.reference', 'v.base_pen', 'e.country_code',
                DB::raw('COALESCE(x.export_base, 0) AS export_base'),
                DB::raw("CASE WHEN r.counterparty_identity_type <> '6' AND e.identity_document_type_code = '6' THEN e.tax_id ELSE COALESCE(r.counterparty_tax_id, e.tax_id) END AS identity"),
                DB::raw("CASE WHEN e.identity_document_type_code = '6' THEN '6' ELSE COALESCE(r.counterparty_identity_type, e.identity_document_type_code) END AS identity_type"),
                DB::raw("COALESCE(r.counterparty_name, e.legal_name) AS party_name")]);
    }

    public function detail(object $doc, array $regimes): array
    {
        $status = 'pending';
        $reason = 'Verifique inclusión o exclusión según el artículo 6';
        $amount = null;
        $month = (int) substr($doc->issue_date, 5, 2);
        $export = bccomp($doc->export_base, '0', 4) > 0;
        if (($regimes[$month] ?? null) === 'NRUS') {
            $status = 'excluded';
            $reason = 'Periodo en Nuevo RUS';
        } elseif (in_array($doc->document_type, ['50', '51', '52', '53'], true)) {
            $status = 'excluded';
            $reason = 'Importación de bienes';
        } elseif ($export && bccomp($doc->export_base, $doc->taxable_amount, 4) === 0) {
            $status = 'excluded';
            $reason = 'Exportación de bienes o servicios';
        } elseif ($doc->revision !== null) {
            $status = $doc->included ? 'included' : 'excluded';
            $reason = $doc->reference;
            if ($doc->included) {
                $basis = $doc->base_pen ?? ($doc->currency === 'PEN' ? $doc->taxable_amount : null);
                $amount = $basis === null ? null : bcmul($basis, in_array($doc->document_type, ['07', '87'], true) ? '-1' : '1', 4);
                $hasIdentity = $doc->identity_type === '6' ? preg_match('/^\d{11}$/D', (string) $doc->identity) : ($doc->identity && $doc->identity_type && $doc->identity_type !== '0');
                if ($amount === null || !$hasIdentity) {
                    $status = 'pending';
                    $reason = 'Falta identidad o base validada en soles';
                    $amount = null;
                }
            }
        } elseif ($export) {
            $reason = 'Revise si es exportación total excluida o un comprobante con operaciones mixtas';
        }

        return [
            'id' => (int) $doc->id, 'module' => $doc->module, 'document' => "$doc->document_type $doc->series-$doc->number",
            'date' => $doc->issue_date, 'currency' => $doc->currency, 'basis' => $doc->taxable_amount,
            'identity_type' => $doc->identity_type, 'identity' => $doc->identity, 'name' => $doc->party_name,
            'status' => $status, 'reason' => $reason, 'amount_pen' => $amount, 'revision' => (int) ($doc->revision ?? 0),
        ];
    }

    public function summarize(iterable $documents, array $regimes, int $threshold): array
    {
        $totals = [];
        $counts = ['included' => 0, 'excluded' => 0, 'pending' => 0];
        foreach ($documents as $doc) {
            $row = $this->detail($doc, $regimes);
            $counts[$row['status']]++;
            if ($row['status'] !== 'included') {
                continue;
            }
            $key = implode(':', [$row['module'], $row['identity_type'], $row['identity']]);
            $totals[$key] ??= ['module' => $row['module'], 'identity_type' => $row['identity_type'], 'identity' => $row['identity'],
                'name' => $row['name'], 'amount_pen' => '0.0000', 'documents' => 0];
            $totals[$key]['amount_pen'] = bcadd($totals[$key]['amount_pen'], $row['amount_pen'], 4);
            $totals[$key]['documents']++;
        }
        foreach ($totals as &$total) {
            $total['over_threshold'] = bccomp($total['amount_pen'], (string) $threshold, 4) > 0;
        }
        unset($total);
        uasort($totals, static fn (array $a, array $b) => [$a['module'], $a['identity_type'], $a['identity']] <=> [$b['module'], $b['identity_type'], $b['identity']]);

        return ['counts' => $counts, 'rows' => array_values($totals)];
    }
}
