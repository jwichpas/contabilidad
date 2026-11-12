<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\AccountingAccount;
use App\Models\CompanyDocumentJournalSetting;
use App\Models\CompanySubdiary;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Parámetros contables por módulo: nunca se comparten entre empresas. */
final readonly class DocumentJournalSettingsController
{
    public function __construct(
        private TenantContext $tenant,
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

    public function show(string $module): JsonResponse
    {
        $this->assertSchema();
        $kind = $this->module($module);
        $this->tenant->requireCompanyId(CompanyDocumentJournalSetting::class);
        $setting = CompanyDocumentJournalSetting::query()->where('module', $kind)->first();

        return response()->json(['data' => $setting ?? [
            'module' => $kind,
            'subdiary_code' => $kind === 'PURCHASE' ? 'COMPRAS' : 'VENTAS',
            'main_account_id' => null,
            'tax_account_id' => null,
            'settlement_account_id' => null,
            'default_tax_rate' => '18.0000',
        ]]);
    }

    public function update(Request $request, string $module): JsonResponse
    {
        $this->assertSchema();
        $kind = $this->module($module);
        $companyId = $this->tenant->requireCompanyId(CompanyDocumentJournalSetting::class);
        $data = $request->validate([
            'subdiary_code' => ['required', 'string', 'regex:/^[A-Z0-9_]{2,24}$/'],
            'main_account_id' => ['required', 'integer', 'min:1'],
            'tax_account_id' => ['nullable', 'integer', 'min:1'],
            'settlement_account_id' => ['required', 'integer', 'min:1'],
            'default_tax_rate' => ['required', 'numeric', 'between:0,100', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'company_id' => ['prohibited'],
            'module' => ['prohibited'],
        ]);
        if (! CompanySubdiary::query()->where('code', $data['subdiary_code'])
            ->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['subdiary_code' => ['Seleccione un subdiario activo de la empresa.']]);
        }

        $accountIds = array_values(array_filter([
            (int) $data['main_account_id'],
            isset($data['tax_account_id']) ? (int) $data['tax_account_id'] : null,
            (int) $data['settlement_account_id'],
        ], static fn (?int $id) => $id !== null));

        if (count(array_unique($accountIds)) !== count($accountIds)) {
            throw ValidationException::withMessages(['main_account_id' => ['Use cuentas distintas para base, tributo y contrapartida.']]);
        }

        $activeCount = AccountingAccount::query()->whereIn('id', $accountIds)
            ->operationalActive()->count();
        if ($activeCount !== count($accountIds)) {
            throw ValidationException::withMessages(['main_account_id' => ['Todas las cuentas deben ser operativas, activas y de la empresa.']]);
        }

        $record = CompanyDocumentJournalSetting::query()->updateOrCreate(
            ['module' => $kind],
            [
                'subdiary_code' => $data['subdiary_code'],
                'main_account_id' => (int) $data['main_account_id'],
                'tax_account_id' => isset($data['tax_account_id']) ? (int) $data['tax_account_id'] : null,
                'settlement_account_id' => (int) $data['settlement_account_id'],
                'default_tax_rate' => $data['default_tax_rate'],
            ]
        );

        $this->audit->log(
            'DOCUMENT_JOURNAL_SETTINGS_SAVED', $companyId, (int) $request->user()->id,
            CompanyDocumentJournalSetting::class, (string) $record->id,
            null, ['module' => $kind, 'subdiary_code' => $record->subdiary_code,
                'main_account_id' => $record->main_account_id, 'tax_account_id' => $record->tax_account_id,
                'settlement_account_id' => $record->settlement_account_id], $request
        );

        return response()->json(['data' => $record]);
    }

    private function assertSchema(): void
    {
        if (! Schema::hasTable('company_document_journal_settings')) {
            abort(503, 'Falta aplicar la migración de los registros de compras y ventas.');
        }
    }
}
