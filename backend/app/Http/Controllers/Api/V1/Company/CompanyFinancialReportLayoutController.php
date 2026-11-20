<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Services\Accounting\FinancialReportLayoutCatalog;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tenant-specific presentation only: no ability to mutate financial amounts,
 * immutable standard codes, movement classifications or published formulas.
 */
final readonly class CompanyFinancialReportLayoutController
{
    public function __construct(private TenantContext $tenant, private AuditLoggerService $audit) {}

    private function check(string $statement): array
    {
        abort_unless(in_array($statement, FinancialReportLayoutCatalog::STATEMENTS, true), 404);
        abort_unless(Schema::hasTable('company_financial_report_layout_revisions'), 503,
            'Instale primero la migración de estructuras financieras por empresa.');
        $catalog = FinancialReportLayoutCatalog::forStatement($statement);
        abort_if($catalog === [], 409, 'El Estado de Cambios en el Patrimonio aún no tiene vista contable operativa.');
        return $catalog;
    }

    private function latest(int $companyId, string $statement): array
    {
        $row = DB::table('company_financial_report_layout_revisions')
            ->where('company_id', $companyId)->where('statement', $statement)
            ->orderByDesc('revision')->first();
        if ($row === null) {
            return ['revision' => 0, 'settings' => FinancialReportLayoutCatalog::defaults(), 'created_at' => null];
        }
        return [
            'revision' => (int) $row->revision,
            'settings' => array_replace(FinancialReportLayoutCatalog::defaults(),
                json_decode((string) $row->settings, true, 512, JSON_THROW_ON_ERROR)),
            'created_at' => (string) $row->created_at,
        ];
    }

    public function show(string $statement): JsonResponse
    {
        $catalog = $this->check($statement);
        $companyId = $this->tenant->requireCompanyId(self::class);
        $history = DB::table('company_financial_report_layout_revisions')
            ->where('company_id', $companyId)->where('statement', $statement)
            ->orderByDesc('revision')->limit(20)->get(['revision', 'created_at', 'created_by'])
            ->map(static fn ($row): array => [
                'revision' => (int) $row->revision, 'created_at' => (string) $row->created_at,
                'created_by' => (int) $row->created_by,
            ])->all();
        return response()->json(['data' => $this->latest($companyId, $statement),
            'catalog' => $catalog, 'history' => $history])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $statement): JsonResponse
    {
        $catalog = $this->check($statement);
        $companyId = $this->tenant->requireCompanyId(self::class);
        $input = $request->validate([
            'company_id' => ['prohibited'], 'statement' => ['prohibited'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'settings' => ['required', 'array:labels,hidden_codes,line_order,hide_zero'],
            'settings.labels' => ['present', 'array', 'max:150'],
            'settings.labels.*' => ['required', 'string', 'min:2', 'max:120'],
            'settings.hidden_codes' => ['present', 'array', 'max:150'],
            'settings.hidden_codes.*' => ['required', 'string', 'max:64', 'distinct'],
            'settings.line_order' => ['present', 'array', 'max:150'],
            'settings.line_order.*' => ['required', 'string', 'max:64', 'distinct'],
            'settings.hide_zero' => ['required', 'boolean'],
        ]);
        $settings = $input['settings'];
        // Protect totals, OCI pending, unclassified and balance reconciliations.
        foreach ($settings['labels'] as $code => &$label) {
            if (($catalog[(string) $code]['kind'] ?? null) !== 'detail') {
                throw ValidationException::withMessages(['settings.labels' =>
                    ['Solo se pueden cambiar nombres de líneas de detalle del catálogo estándar.']]);
            }
            $label = trim($label);
            if (mb_strlen($label) < 2) {
                throw ValidationException::withMessages(['settings.labels' => ['Descripción inválida.']]);
            }
        }
        unset($label);
        foreach (['hidden_codes', 'line_order'] as $field) {
            foreach ($settings[$field] as $code) {
                if (($catalog[$code]['kind'] ?? null) !== 'detail') {
                    throw ValidationException::withMessages(['settings.'.$field =>
                        ['La línea no existe o es un subtotal/resultado protegido.']]);
                }
            }
        }

        $revision = DB::transaction(function () use ($companyId, $statement, $settings, $input, $request): int {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $previous = $this->latest($companyId, $statement);
            if ($previous['revision'] !== (int) $input['expected_revision']) {
                throw new ConflictHttpException('La estructura cambió en otra sesión. Recargue antes de guardar.');
            }
            $revision = $previous['revision'] + 1;
            DB::table('company_financial_report_layout_revisions')->insert([
                'company_id' => $companyId, 'statement' => $statement,
                'revision' => $revision, 'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_by' => (int) $request->user()->id, 'created_at' => now(),
            ]);
            $this->audit->log('COMPANY_FINANCIAL_LAYOUT_UPDATED', $companyId, (int) $request->user()->id,
                'company_financial_report_layout_revisions', $statement.':'.$revision,
                $previous, ['revision' => $revision, 'settings' => $settings], $request);
            return $revision;
        });
        return response()->json(['data' => ['revision' => $revision, 'settings' => $settings]])
            ->header('Cache-Control', 'private, no-store');
    }
}
