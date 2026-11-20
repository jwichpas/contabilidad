<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class PayrollLedger
{
    public function __construct(private TenantContext $tenant) {}

    public function company(): int
    {
        return $this->tenant->requireCompanyId(self::class);
    }

    public function lock(): void
    {
        DB::table('companies')->where('id', $this->company())->lockForUpdate()->first();
    }

    public function latest(string $kind, string $reference): ?array
    {
        $row = $this->query($kind)->where('reference', $reference)->orderByDesc('revision')->first();
        return $row ? $this->record($row) : null;
    }

    public function records(string $kind, string $prefix = ''): array
    {
        return $this->query($kind)->when($prefix !== '', fn (Builder $query) => $query->where('reference', 'like', $prefix.'%'))
            ->selectRaw('DISTINCT ON (reference) *')->orderBy('reference')->orderByDesc('revision')
            ->get()->mapWithKeys(fn (object $row) => [$row->reference => $this->record($row)])->all();
    }

    public function parameterHistory(): array
    {
        return $this->query('parameter')->orderByDesc('id')->get()->map(fn (object $row) =>
            $this->record($row) + [
                'created_by' => (int) $row->created_by,
                'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            ]
        )->all();
    }

    public function workforce(string $month): array
    {
        return $this->query('worker')->whereRaw("payload->>'effective_month' <= ?", [$month])
            ->selectRaw('DISTINCT ON (reference) *')->orderBy('reference')->orderByDesc('revision')->get()
            ->mapWithKeys(fn (object $row) => [$row->reference => $this->record($row)])->all();
    }

    public function save(string $kind, string $reference, int $expected, int $actor, array $payload): array
    {
        $current = $this->latest($kind, $reference);
        abort_if(($current['revision'] ?? 0) !== $expected, 409, 'Hay una revisión posterior. Vuelva a cargar la planilla.');
        $this->query($kind)->insert([
            'company_id' => $this->company(), 'kind' => $kind, 'reference' => $reference, 'revision' => $expected + 1,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_by' => $actor,
        ]);
        return $this->latest($kind, $reference);
    }

    private function query(string $kind): Builder
    {
        return DB::table('company_payroll_revisions')->where('company_id', $this->company())->where('kind', $kind);
    }

    private function record(object $row): array
    {
        return ['reference' => $row->reference, 'revision' => (int) $row->revision,
            'data' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)];
    }
}
