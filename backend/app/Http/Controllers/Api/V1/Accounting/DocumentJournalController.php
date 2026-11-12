<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingDocumentEntry;
use App\Models\CompanyCurrency;
use App\Models\CompanyDocumentJournalSetting;
use App\Models\CompanySubdiary;
use App\Models\Entity;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\PostJournalEntryService;
use App\Services\Security\AuditLoggerService;
use App\Support\Math\Decimal;
use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El comprobante origina un único asiento inmutable en el motor contable existente.
 * El middleware SetTenantContext mantiene una única transacción SQL con RLS.
 * Alcance inicial: comprobantes positivos 01/03; no se asumen beneficios tributarios.
 */
final readonly class DocumentJournalController
{
    public function __construct(
        private TenantContext $tenant,
        private PostJournalEntryService $journal,
        private AuditLoggerService $audit,
    ) {}

    private function module(string $module): string
    {
        return match ($module) {
            'purchases' => 'PURCHASE',
            'sales' => 'SALE',
            default => abort(404),
        };
    }

    public function index(Request $request, string $module): JsonResponse
    {
        $this->assertSchema();
        $kind = $this->module($module);
        $this->tenant->requireCompanyId(AccountingDocumentEntry::class);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = AccountingDocumentEntry::query()->where('module', $kind)
            ->with(['entity:id,tax_id,legal_name', 'journal:id,entry_number,status,subdiary_code'])
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json($page);
    }

    public function store(Request $request, string $module): JsonResponse
    {
        $this->assertSchema();
        $kind = $this->module($module);
        $companyId = $this->tenant->requireCompanyId(AccountingDocumentEntry::class);

        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(['01', '03'])],
            'series' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'number' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'entity_id' => ['required', 'integer', 'min:1'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'entry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'taxable_amount' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'tax_amount' => ['required', 'numeric', 'min:0', 'max:999999999', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'cost_center_id' => ['nullable', 'integer', 'min:1'],
            'company_area_id' => ['nullable', 'integer', 'min:1'],
            'cash_flow_area_id' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:180'],
            'company_id' => ['prohibited'], 'module' => ['prohibited'],
            'journal_entry_id' => ['prohibited'], 'entry_number' => ['prohibited'],
        ]);

        $setting = CompanyDocumentJournalSetting::query()->where('module', $kind)->first();
        if ($setting === null) {
            throw ValidationException::withMessages(['module' => ['Configure las cuentas y el subdiario antes de registrar documentos.']]);
        }
        if (! CompanySubdiary::query()->where('code', $setting->subdiary_code)
            ->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['module' => ['El subdiario del módulo está inactivo o ya no existe.']]);
        }
        $entity = Entity::query()->where('id', (int) $data['entity_id'])->where('is_active', true)
            ->sharedLock()->first();
        if ($entity === null || ! (bool) ($kind === 'PURCHASE' ? $entity->is_supplier : $entity->is_client)) {
            throw ValidationException::withMessages(['entity_id' => ['Seleccione un proveedor o cliente activo de esta empresa.']]);
        }

        $accounts = AccountingAccount::query()
            ->whereIn('id', array_values(array_filter([
                $setting->main_account_id, $setting->tax_account_id, $setting->settlement_account_id,
            ])))->operationalActive()->get()->keyBy('id');
        $main = $accounts->get($setting->main_account_id);
        $settlement = $accounts->get($setting->settlement_account_id);
        $tax = $setting->tax_account_id === null ? null : $accounts->get($setting->tax_account_id);
        if (! $main || ! $settlement || ($setting->tax_account_id !== null && ! $tax)) {
            throw ValidationException::withMessages(['module' => ['Revise las cuentas de configuración: una o más están inactivas.']]);
        }

        $base = Decimal::ofMonetary((string) $data['taxable_amount']);
        $taxAmount = Decimal::ofMonetary((string) $data['tax_amount']);
        if ($taxAmount->isPositive() && $tax === null) {
            throw ValidationException::withMessages(['tax_amount' => ['Configure una cuenta tributaria o registre tributo cero.']]);
        }
        $total = $base->add($taxAmount);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['entry_date']);
        if (! $date) {
            throw ValidationException::withMessages(['entry_date' => ['Fecha contable inválida.']]);
        }

        // Motor bimoneda: la configuración de monedas y el TC diario son autoritativos.
        // En PEN el lado extranjero usa la única moneda secundaria activa, si existe.
        $currencies = CompanyCurrency::query()->where('company_id', $companyId)
            ->whereRaw('company_currencies.is_active IS TRUE')->with('currency')->get();
        $baseCode = $currencies->firstWhere('is_base', true)?->currency?->code ?? 'PEN';
        $secondary = $currencies->where('is_base', false)->values();
        if ($baseCode !== 'PEN' || $secondary->count() > 1) {
            throw ValidationException::withMessages(['currency' => ['Este registro requiere PEN como moneda base y máximo una moneda secundaria activa.']]);
        }
        $foreignCode = $secondary->first()?->currency?->code;
        $currency = (string) $data['currency'];
        if ($currency !== 'PEN' && $currency !== $foreignCode) {
            throw ValidationException::withMessages(['currency' => ['La moneda no está habilitada como moneda de trabajo de esta empresa.']]);
        }
        $rate = Decimal::ofExchangeRate('1');
        if ($foreignCode !== null) {
            $dailyRate = ExchangeRate::query()->where('company_id', $companyId)
                ->where('rate_date', $date->toDateString())
                ->where('source_currency', $foreignCode)
                ->where('target_currency', 'PEN')->first();
            if (! $dailyRate) {
                throw ValidationException::withMessages(['entry_date' => ['Registre el tipo de cambio del día para '.$foreignCode.' antes de contabilizar.']]);
            }
            $rate = Decimal::ofExchangeRate((string) $dailyRate->sell_rate);
            if (! $rate->isPositive()) {
                throw ValidationException::withMessages(['entry_date' => ['El tipo de cambio debe ser mayor que cero.']]);
            }
        }

        if ($currency === 'PEN') {
            $mainLocal = $base;
            $taxLocal = $taxAmount;
            $mainForeign = $base->divide($rate);
            $taxForeign = $taxAmount->isZero() ? Decimal::zero() : $taxAmount->divide($rate);
        } else {
            $mainForeign = $base;
            $taxForeign = $taxAmount;
            $mainLocal = $base->multiply($rate);
            $taxLocal = $taxAmount->isZero() ? Decimal::zero() : $taxAmount->multiply($rate);
        }
        // Construir las contrapartidas sumando los importes ya redondeados: diferencia = 0.0000.
        $totalLocal = $mainLocal->add($taxLocal);
        $totalForeign = $mainForeign->add($taxForeign);

        $period = FiscalPeriod::query()->firstOrCreate(
            ['year' => $date->year, 'month' => $date->month],
            ['start_date' => $date->startOfMonth()->toDateString(),
                'end_date' => $date->endOfMonth()->toDateString(), 'status' => 'OPEN']
        );
        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['entry_date' => ['El período contable no está abierto.']]);
        }

        $series = strtoupper($data['series']);
        $number = strtoupper($data['number']);
        $docKey = implode(':', [$companyId, $kind, $data['document_type'], $series, $number, $entity->id]);
        // Bloqueo transaccional para evitar dos asientos ante envíos concurrentes del mismo documento.
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['document-journal:'.$docKey]);
        if (AccountingDocumentEntry::query()->where([
            'module' => $kind, 'document_type' => $data['document_type'],
            'series' => $series, 'number' => $number, 'entity_id' => $entity->id,
        ])->exists()) {
            throw ValidationException::withMessages(['number' => ['Este comprobante ya fue contabilizado para el mismo tercero.']]);
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $description = ($kind === 'PURCHASE' ? 'Compra ' : 'Venta ')
                .$data['document_type'].' '.$series.'-'.$number.' / '.$entity->tax_id;
        }
        $isSale = $kind === 'SALE';
        $zero = Decimal::zero()->toString();
        $mainEntity = $this->accountNeedsEntity($main) ? (int) $entity->id : null;
        $taxEntity = $tax && $this->accountNeedsEntity($tax) ? (int) $entity->id : null;
        $settlementEntity = $this->accountNeedsEntity($settlement) ? (int) $entity->id : null;
        $lines = [
            [
                'accounting_account_id' => (int) $main->id,
                'entity_id' => $mainEntity,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'company_area_id' => $data['company_area_id'] ?? null,
                'cash_flow_area_id' => $data['cash_flow_area_id'] ?? null,
                'description' => $description,
                'debit_local' => $isSale ? $zero : $mainLocal->toString(),
                'credit_local' => $isSale ? $mainLocal->toString() : $zero,
                'debit_foreign' => $isSale ? $zero : $mainForeign->toString(),
                'credit_foreign' => $isSale ? $mainForeign->toString() : $zero,
            ],
        ];
        if ($taxAmount->isPositive()) {
            $lines[] = [
                'accounting_account_id' => (int) $tax->id, 'entity_id' => $taxEntity,
                'description' => $description,
                'debit_local' => $isSale ? $zero : $taxLocal->toString(),
                'credit_local' => $isSale ? $taxLocal->toString() : $zero,
                'debit_foreign' => $isSale ? $zero : $taxForeign->toString(),
                'credit_foreign' => $isSale ? $taxForeign->toString() : $zero,
            ];
        }
        $lines[] = [
            'accounting_account_id' => (int) $settlement->id,
            'entity_id' => $settlementEntity,
            'description' => $description,
            'debit_local' => $isSale ? $totalLocal->toString() : $zero,
            'credit_local' => $isSale ? $zero : $totalLocal->toString(),
            'debit_foreign' => $isSale ? $totalForeign->toString() : $zero,
            'credit_foreign' => $isSale ? $zero : $totalForeign->toString(),
        ];

        try {
            // SAVEPOINT: errores del motor no deben dejar periodo ni comprobante parcial.
            $document = DB::transaction(function () use (
                $period, $date, $setting, $currency, $rate, $description, $request,
                $lines, $kind, $data, $series, $number, $entity, $base, $taxAmount, $total
            ): AccountingDocumentEntry {
                $entry = $this->journal->post([
                    'fiscal_period_id' => (int) $period->id,
                    'entry_date' => $date->toDateString(),
                    'entry_type' => 'STANDARD',
                    'subdiary_code' => $setting->subdiary_code,
                    'currency' => $currency,
                    'exchange_rate' => $rate->toString(),
                    'exchange_rate_type' => 'SELL',
                    'description' => $description,
                    'created_by_user_id' => (int) $request->user()->id,
                    'lines' => $lines,
                ]);

                return AccountingDocumentEntry::query()->create([
                    'module' => $kind,
                    'document_type' => $data['document_type'],
                    'series' => $series,
                    'number' => $number,
                    'entity_id' => (int) $entity->id,
                    'issue_date' => $data['issue_date'],
                    'entry_date' => $date->toDateString(),
                    'currency' => $currency,
                    'exchange_rate' => $rate->toString(),
                    'taxable_amount' => $base->toString(),
                    'tax_amount' => $taxAmount->toString(),
                    'total_amount' => $total->toString(),
                    'journal_entry_id' => $entry->id,
                ]);
            });
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['module' => [$exception->getMessage()]]);
        }

        $this->audit->log(
            'ACCOUNTING_DOCUMENT_POSTED', $companyId, (int) $request->user()->id,
            AccountingDocumentEntry::class, (string) $document->id, null,
            ['module' => $kind, 'document_type' => $data['document_type'],
                'series' => $series, 'number' => $number,
                'journal_entry_id' => $document->journal_entry_id], $request
        );

        $document->load(['entity:id,tax_id,legal_name', 'journal:id,entry_number,status,subdiary_code']);
        return response()->json([
            'message' => 'Comprobante contabilizado y vinculado al Libro Diario.',
            'data' => $document,
        ], 201);
    }

    private function accountNeedsEntity(AccountingAccount $account): bool
    {
        $type = $account->entity_requirement_type;
        $typeName = $type instanceof \BackedEnum ? $type->value : (string) $type;
        return $typeName !== 'NONE' || (bool) $account->requires_auxiliary;
    }

    private function assertSchema(): void
    {
        if (! Schema::hasTable('company_document_journal_settings')
            || ! Schema::hasTable('accounting_document_entries')) {
            abort(503, 'Falta aplicar la migración de compras y ventas.');
        }
    }
}
