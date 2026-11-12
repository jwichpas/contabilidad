<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\AccountingTaxRegisterEntry;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reporte tributario interno, independiente del libro diario.
 * NO genera, acepta ni presenta un registro SIRE ante SUNAT.
 */
final readonly class TaxRegisterController
{
    public function __construct(private TenantContext $tenant) {}

    private function module(string $module): string
    {
        return match ($module) {
            'purchases' => 'PURCHASE',
            'sales' => 'SALE',
            default => abort(404),
        };
    }

    private function validatedPeriod(Request $request): string
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^[0-9]{4}(0[1-9]|1[0-2])$/'],
        ]);
        return $data['period'];
    }

    public function index(Request $request, string $module): JsonResponse
    {
        $this->requireSchema();
        $kind = $this->module($module);
        $this->tenant->requireCompanyId(AccountingTaxRegisterEntry::class);
        $period = $this->validatedPeriod($request);
        $page = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $records = AccountingTaxRegisterEntry::query()
            ->where('module', $kind)
            ->where('tax_period', $period)
            ->with([
                'document:id,company_id,module,document_type,series,number,issue_date,entry_date,currency,exchange_rate,taxable_amount,tax_amount,total_amount,journal_entry_id',
                'document.journal:id,entry_number,status,subdiary_code',
                'allocations',
            ])
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'page', (int) ($page['page'] ?? 1));

        return response()->json($records);
    }

    public function summary(Request $request, string $module): JsonResponse
    {
        $this->requireSchema();
        $kind = $this->module($module);
        $companyId = $this->tenant->requireCompanyId(AccountingTaxRegisterEntry::class);
        $period = $this->validatedPeriod($request);

        // Los asientos extornados siguen visibles en el historial, pero se excluyen del resumen activo.
        // Agrupar por moneda: no mezclar PEN con USD antes de convertir a soles
        // en el adaptador normativo SIRE, aún pendiente de implementar.
        $byCategory = DB::table('accounting_tax_register_entries as r')
            ->join('accounting_document_entries as d', function ($join): void {
                $join->on('d.id', '=', 'r.accounting_document_entry_id')
                    ->on('d.company_id', '=', 'r.company_id');
            })
            ->join('accounting_tax_register_allocations as a', function ($join): void {
                $join->on('a.tax_register_entry_id', '=', 'r.id')
                    ->on('a.company_id', '=', 'r.company_id');
            })
            ->join('journal_entries as journal', function ($join): void {
                $join->on('journal.id', '=', 'd.journal_entry_id')
                    ->on('journal.company_id', '=', 'd.company_id');
            })
            ->where('r.company_id', $companyId)
            ->where('r.module', $kind)
            ->where('r.tax_period', $period)
            ->where('journal.status', 'POSTED')
            ->groupBy('a.category', 'd.currency')
            ->orderBy('a.category')
            ->orderBy('d.currency')
            ->get([
                'a.category', 'd.currency',
                DB::raw('COUNT(*) AS document_count'),
                DB::raw('SUM(a.base_amount)::text AS base_amount'),
                DB::raw('SUM(a.tax_amount)::text AS tax_amount'),
                DB::raw('SUM(a.creditable_tax_amount)::text AS creditable_tax_amount'),
            ]);

        return response()->json([
            'data' => [
                'module' => $kind,
                'period' => $period,
                'registration' => 'INTERNAL_PENDING_SIRE_RECONCILIATION',
                'breakdown' => $byCategory,
            ],
        ]);
    }

    private function requireSchema(): void
    {
        if (! Schema::hasTable('accounting_tax_register_entries')
            || ! Schema::hasTable('accounting_tax_register_allocations')) {
            abort(503, 'Falta aplicar las migraciones de Registros Tributarios SUNAT.');
        }
    }
}
