<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class CashFlowPresentationSettingsService
{
    public function __construct(
        private TenantContext $tenant,
        private AuditLoggerService $audit,
    ) {}

    private function requireTable(): void
    {
        abort_unless(Schema::hasTable('company_cash_flow_presentation_revisions'), 503,
            'Aplique primero la migración de configuración del flujo de efectivo.');
    }

    /** @return array{revision:int,settings:array<string,mixed>,created_at:?string,created_by:?int} */
    public function latest(): array
    {
        $this->requireTable();
        $companyId = $this->tenant->requireCompanyId(self::class);
        $row = DB::table('company_cash_flow_presentation_revisions')
            ->where('company_id', $companyId)->orderByDesc('revision')->first();
        return $this->project($row);
    }

    /** @return list<array<string,mixed>> */
    public function history(): array
    {
        $this->requireTable();
        $companyId = $this->tenant->requireCompanyId(self::class);
        return DB::table('company_cash_flow_presentation_revisions')
            ->where('company_id', $companyId)->orderByDesc('revision')
            ->limit(20)->get(['revision', 'created_at', 'created_by'])
            ->map(static fn ($row): array => [
                'revision' => (int) $row->revision,
                'created_at' => (string) $row->created_at,
                'created_by' => (int) $row->created_by,
            ])->all();
    }

    /**
     * @param array<string,mixed> $settings Validated and normalized by controller.
     * @return array<string,mixed>
     */
    public function save(array $settings, int $expectedRevision, Request $request): array
    {
        $this->requireTable();
        $companyId = $this->tenant->requireCompanyId(self::class);
        return DB::transaction(function () use ($companyId, $settings, $expectedRevision, $request): array {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $previous = DB::table('company_cash_flow_presentation_revisions')
                ->where('company_id', $companyId)->orderByDesc('revision')->first();
            $rev = (int) ($previous->revision ?? 0);
            if ($rev !== $expectedRevision) {
                throw new ConflictHttpException('La configuración cambió en otra sesión. Recargue antes de guardar.');
            }

            DB::table('company_cash_flow_presentation_revisions')->insert([
                'company_id' => $companyId,
                'revision' => $rev + 1,
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_by' => (int) $request->user()->id,
                'created_at' => now(),
            ]);
            $before = $this->project($previous);
            $this->audit->log('COMPANY_CASH_FLOW_PRESENTATION_UPDATED',
                $companyId, (int) $request->user()->id,
                'company_cash_flow_presentation_revisions', (string) ($rev + 1),
                ['revision' => $rev, 'settings' => $before['settings']],
                ['revision' => $rev + 1, 'settings' => $settings], $request);
            return [
                'revision' => $rev + 1,
                'settings' => $settings,
                'created_by' => (int) $request->user()->id,
            ];
        });
    }

    /** @return array{revision:int,settings:array<string,mixed>,created_at:?string,created_by:?int} */
    private function project(?object $row): array
    {
        if ($row === null) {
            return ['revision' => 0, 'settings' => CashFlowPresentationCatalog::defaults(),
                'created_at' => null, 'created_by' => null];
        }
        $settings = json_decode((string) $row->settings, true, 512, JSON_THROW_ON_ERROR);
        return [
            'revision' => (int) $row->revision,
            'settings' => array_replace(CashFlowPresentationCatalog::defaults(), $settings),
            'created_at' => (string) $row->created_at,
            'created_by' => (int) $row->created_by,
        ];
    }
}
