<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Http\Controllers\Api\V1\Company\CompanySunatCalendarController;
use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final readonly class MonthlyAccountingWork
{
    private const TABLE = 'company_monthly_work_revisions';
    public const CONFIG = 'config';

    public function __construct(private TenantContext $tenant, private CompanySunatCalendarController $calendar, private SunatCalendar $official) {}

    public function period(string $month): CarbonImmutable
    {
        if (!preg_match('/^20(?:2[5-9]|[3-9]\d)-(0[1-9]|1[0-2])$/D', $month)) {
            throw ValidationException::withMessages(['period' => 'Seleccione un periodo mensual válido.']);
        }

        return CarbonImmutable::parse($month.'-01', 'America/Lima');
    }

    public function preview(CarbonImmutable $period, ?array $values = null): array
    {
        $calendar = $this->calendar->report($period->year);
        $profile = $calendar['profile'];
        $month = $period->format('Y-m');
        $regime = $calendar['regimes'][$period->month];
        $events = collect($calendar['events']);
        $edition = $this->official->edition($period->year);
        $group = $this->official->group($profile['monthly_group'] === 'BC' ? 'BC' : substr($calendar['ruc'], -1));
        $ordinaryTaxDate = $edition['tax'][$period->month][$group] ?? null;
        $hasWorkers = $values['workers'] ?? $profile['plame'];
        $hasAssets = $values['assets'] ?? false;
        $internal = $values['internal_due'] ?? null;
        $tasks = [];

        if ($regime !== null && $regime !== 'NRUS') {
            $tasks[] = $this->task('bank', 'Conciliar caja y bancos', $internal, 'internal');
            $tasks[] = $this->task('ledger', 'Revisar mayores y balance de comprobación', $internal, 'internal');
            $tasks[] = $this->task('third-parties', 'Conciliar cuentas por cobrar y por pagar', $internal, 'internal');
        }
        if ($values['prico'] ?? false) {
            $tasks[] = $this->task('prico-review', 'Revisar notificaciones y obligaciones específicas del directorio PRICO', $internal, 'internal');
        }
        if ($hasAssets) {
            $tasks[] = $this->task('depreciation', 'Generar y revisar depreciación mensual', $internal, 'internal');
        }
        if ($hasWorkers) {
            $tasks[] = $this->task('payroll', 'Preparar y revisar planilla de trabajadores', $internal, 'internal');
        }

        $selected = $events->where('period', $month);
        if ($period->month === 12) {
            $selected = $selected->concat($events->where('period', (string) $period->year));
        }
        foreach ($selected as $event) {
            if ($event['code'] === 'PLAME' && !$hasWorkers && !$profile['plame']) {
                continue;
            }
            $annual = strlen($event['period']) === 4;
            $task = $this->task($this->eventKey($event['code']).($annual ? '-annual' : ''), $event['label'], $event['date'], $event['type'], $event['source']);
            $tasks[] = $task + ['basis' => $event['basis'], 'annual' => $annual];
        }
        $present = array_column($tasks, 'code');
        if ($hasWorkers && !in_array('sunat-plame', $present, true)) {
            $tasks[] = $this->task('sunat-plame', 'Declarar y pagar PLAME', $ordinaryTaxDate, 'tax',
                'https://orientacion.sunat.gob.pe/pdt-plame');
        }
        foreach ([
            'retention' => ['626', 'Retenciones del IGV · FV 626', 'https://www.sunat.gob.pe/orientacion/regimenEspIGV/agentesRetencion/declaracionPago.htm'],
            'perception' => ['633', 'Percepciones del IGV · FV 633', 'https://www.sunat.gob.pe/orientacion/regimenEspIGV/agentesPercepcion/agentesPercepcionCombust/declaracionPago.htm'],
        ] as $flag => [$form, $label, $source]) {
            $code = $this->eventKey($form);
            if (($values[$flag] ?? false) && !in_array($code, $present, true)) {
                $tasks[] = $this->task($code, $label, $ordinaryTaxDate, 'tax', $source);
            }
        }

        if ($hasWorkers) {
            $this->laborTasks($tasks, $period, $values ?? []);
        }

        return ['tasks' => $tasks, 'tax_profile_ready' => $calendar['revision'] > 0 && $regime !== null
            && (!in_array($regime, ['GENERAL', 'MYPE_TRIBUTARIO', 'MYPE'], true) || $profile['income_band'] !== 'UNCONFIRMED'),
            'calendar_revision' => $calendar['revision'],
        ];
    }

    private function laborTasks(array &$tasks, CarbonImmutable $period, array $values): void
    {
        $existing = array_column($tasks, 'code');
        if (($values['afp'] ?? false) && !in_array('sunat-afp', $existing, true)) {
            $due = $period->addMonth();
            for ($businessDay = 0; $businessDay < 5; $businessDay++) {
                $due = $this->official->workingDay($businessDay === 0 ? $due : $due->addDay());
            }
            $tasks[] = $this->task('sunat-afp', 'Declarar y pagar aportes AFPnet', $due->year <= 2027 ? $due->toDateString() : null,
                'labor', 'https://www.sbs.gob.pe/boletin/detalleboletin/idbulletin/73');
        }
        if (($values['cts'] ?? false) && in_array($period->month, [5, 11], true) && !in_array('sunat-cts', $existing, true)) {
            $due = $this->official->workingDay($period->day(15))->toDateString();
            $tasks[] = $this->task('sunat-cts', 'Depositar CTS', $due, 'labor', 'https://www.sbs.gob.pe/usuarios/aprende-con-la-sbs/cuenta-cts');
        }
        if (($values['gratification'] ?? false) && in_array($period->month, [7, 12], true) && !in_array('sunat-gratification', $existing, true)) {
            $tasks[] = $this->task('sunat-gratification', 'Pagar gratificaciones', $period->day(15)->toDateString(),
                'labor', 'https://cdn.www.gob.pe/uploads/document/file/3321103/guia_gratificaciones.pdf');
        }
    }

    public function state(CarbonImmutable $period): array
    {
        $rows = $this->query($period)->selectRaw('DISTINCT ON (code) code, revision, payload, created_at')
            ->orderBy('code')->orderByDesc('revision')->get()->keyBy('code');
        $revision = $rows[self::CONFIG] ?? null;
        $config = $revision ? $this->decode($revision->payload) : null;
        $tasks = $config['tasks'] ?? $this->preview($period)['tasks'];
        $today = CarbonImmutable::today('America/Lima')->toDateString();
        $soon = CarbonImmutable::today('America/Lima')->addDays(7)->toDateString();
        $kpi = ['total' => count($tasks), 'done' => 0, 'overdue' => 0, 'upcoming' => 0, 'undated' => 0];
        foreach ($tasks as &$task) {
            $event = $rows['task-'.$task['code']] ?? null;
            $change = $event ? $this->decode($event->payload) : [];
            $done = $this->completed($change, $task['due']);
            $status = match (true) {
                $done => 'done',
                !$task['due'] => 'undated',
                $task['due'] < $today => 'overdue',
                $task['due'] <= $soon => 'upcoming',
                default => 'pending',
            };
            if (isset($kpi[$status])) {
                $kpi[$status]++;
            }
            $task += ['status' => $status, 'revision' => (int) ($event->revision ?? 0), 'evidence' => $change['evidence'] ?? null];
        }

        unset($task);

        $summaries = [];
        foreach (['depreciation', 'payroll'] as $code) {
            $workpaper = $rows[$code] ?? null;
            $summaries[$code] = $workpaper ? $this->decode($workpaper->payload)['totals'] : null;
        }
        $activeTasks = array_values(array_filter($tasks, static fn (array $task) => in_array($task['status'], ['overdue', 'upcoming'], true)));
        usort($activeTasks, static fn (array $a, array $b) => [$a['due'], $a['code']] <=> [$b['due'], $b['code']]);

        return ['period' => $period->format('Y-m'), 'revision' => (int) ($revision->revision ?? 0),
            'profile' => $config['values'] ?? null, 'tasks' => $tasks, 'kpi' => $kpi, 'workpapers' => $summaries,
            'notifications' => $activeTasks];
    }

    public function alerts(CarbonImmutable $current): array
    {
        $today = CarbonImmutable::today('America/Lima');
        $through = $today->addDays(7)->toDateString();
        $rows = $this->companyQuery()->where('period', '<=', $current->toDateString())
            ->where(static fn (Builder $query) => $query->where('code', self::CONFIG)->orWhere('code', 'like', 'task-%'))
            ->selectRaw('DISTINCT ON (period, code) period, code, payload')->orderBy('period')->orderBy('code')->orderByDesc('revision')
            ->get()->groupBy('period');
        $count = 0;
        $target = $current->format('Y-m');
        $earliest = null;
        foreach ($rows as $period => $events) {
            $byCode = $events->keyBy('code');
            if (!isset($byCode[self::CONFIG])) {
                continue;
            }
            foreach ($this->decode($byCode[self::CONFIG]->payload)['tasks'] as $task) {
                $due = $task['due'];
                if ($due === null || $due > $through) {
                    continue;
                }
                $change = $byCode['task-'.$task['code']] ?? null;
                if ($change && $this->completed($this->decode($change->payload), $due)) {
                    continue;
                }
                $count++;
                if ($earliest === null || $due < $earliest) {
                    $earliest = $due;
                    $target = substr($period, 0, 7);
                }
            }
        }

        return ['needsConfirmation' => !$rows->has($current->toDateString()), 'count' => $count, 'target' => $target];
    }

    private function completed(array $change, ?string $due): bool
    {
        return ($change['done'] ?? false) && ($change['task_due'] ?? null) === $due;
    }

    private function configRow(CarbonImmutable $period): ?object
    {
        return $this->query($period)->where('code', self::CONFIG)->orderByDesc('revision')->first(['revision', 'payload']);
    }

    public function requireConfirmation(CarbonImmutable $period): void
    {
        abort_if($this->configRow($period) === null, 428,
            'Confirme el perfil fiscal y los vencimientos de '.$period->format('Y-m').' en Agenda mensual para continuar.');
    }

    public function workpaper(CarbonImmutable $period, string $code): array
    {
        $row = $this->query($period)->where('code', $code)->orderByDesc('revision')->first();
        return ['revision' => (int) ($row->revision ?? 0), 'data' => $row ? $this->decode($row->payload) : null];
    }

    public function append(CarbonImmutable $period, string $code, int $expected, int $user, array $payload): int
    {
        $this->lockCompany();
        $current = (int) ($this->query($period)->where('code', $code)->orderByDesc('revision')->value('revision') ?? 0);
        abort_if($current !== $expected, 409, 'La agenda cambió. Vuelva a cargar el periodo.');
        $this->query($period)->insert([
            'company_id' => $this->tenant->requireCompanyId(self::class),
            'period' => $period->toDateString(), 'code' => $code, 'revision' => $current + 1,
            'created_by' => $user, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $current + 1;
    }

    public function lockCompany(): void
    {
        DB::table('companies')->where('id', $this->tenant->requireCompanyId(self::class))->lockForUpdate()->first();
    }

    private function query(CarbonImmutable $period): Builder
    {
        return $this->companyQuery()->where('period', $period->toDateString());
    }

    private function companyQuery(): Builder
    {
        abort_unless(Schema::hasTable(self::TABLE), 503, 'Aplique la migración de agenda mensual.');
        return DB::table(self::TABLE)->where('company_id', $this->tenant->requireCompanyId(self::class));
    }

    private function eventKey(string $code): string
    {
        return 'sunat-'.strtolower($code);
    }

    private function task(string $code, string $label, ?string $due, string $kind, string $source = ''): array
    {
        return compact('code', 'label', 'due', 'kind', 'source');
    }

    private function decode(string $value): array
    {
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
