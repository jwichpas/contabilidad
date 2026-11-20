<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Services\Accounting\SunatCalendar;
use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CompanySunatCalendarController
{
    private const TABLE = 'company_sunat_calendar_profiles';
    private const DEFAULTS = [
        'income_band' => 'UNCONFIRMED', 'monthly_group' => 'RUC', 'annual_schedule' => 'UNCONFIRMED',
        'annual_good' => false, 'plame' => false, 'itan' => 'NONE',
        'sales_electronic' => true, 'inventory_electronic' => false, 'extra_books' => [], 'excluded_books' => [], 'overrides' => [],
    ];

    public function __construct(private TenantContext $tenant, private SunatCalendar $calendar) {}

    public function show(int $year): JsonResponse
    {
        return response()->json(['data' => $this->report($year)]);
    }

    public function report(int $year): array
    {
        $query = $this->profile($year);
        $company = $this->tenant->requireCompanyId(self::class);
        $ruc = (string) DB::table('companies')->where('id', $company)->value('tax_id');
        abort_unless(preg_match('/^\d{11}$/D', $ruc), 409, 'La empresa necesita un RUC de once dígitos.');

        $latest = $query->orderByDesc('revision')->first();
        $settings = $latest ? json_decode($latest->settings, true, 512, JSON_THROW_ON_ERROR) + self::DEFAULTS : self::DEFAULTS;
        $settingsRegime = DB::table('company_settings')->where('company_id', $company)->value('tax_regime');
        $timeline = Schema::hasTable('company_fiscal_profiles')
            ? DB::table('company_fiscal_profiles')->where('company_id', $company)
                ->where('fiscal_year', '<=', $year)->orderByDesc('effective_date')->orderByDesc('revision')
                ->get(['effective_date', 'tax_regime'])
            : collect();
        $regimes = [];
        foreach (range(1, 12) as $month) {
            $through = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->endOfMonth()->toDateString();
            $revision = $timeline->first(static fn (object $item) => $item->effective_date <= $through);
            $regimes[$month] = $revision->tax_regime ?? $settingsRegime;
        }

        return [
            'year' => $year, 'ruc' => $ruc, 'regime' => $regimes[12], 'regimes' => $regimes, 'revision' => (int) ($latest->revision ?? 0),
            'profile' => $settings,
            'books' => collect(SunatCalendar::CATALOG)->map(
                static fn (array $book, $code) => ['code' => (string) $code, 'label' => $book[0]]
            )->values(),
            'events' => $this->calendar->company($year, substr($ruc, -1), $regimes, $settings),
        ];
    }

    public function save(Request $request, int $year): JsonResponse
    {
        $query = $this->profile($year);
        $bookCodes = array_map('strval', array_keys(SunatCalendar::CATALOG));
        $input = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'profile' => ['required', 'array:income_band,monthly_group,annual_schedule,annual_good,plame,itan,sales_electronic,inventory_electronic,extra_books,excluded_books,overrides'],
            'profile.income_band' => ['required', 'in:UNCONFIRMED,UP_TO_300,UP_TO_500,UP_TO_1700,OVER_1700'],
            'profile.monthly_group' => ['required', 'in:RUC,BC'],
            'profile.annual_schedule' => ['required', 'in:UNCONFIRMED,GENERAL,EXTENDED'],
            'profile.annual_good' => ['required', 'boolean'],
            'profile.plame' => ['required', 'boolean'],
            'profile.itan' => ['required', 'in:NONE,FULL,INSTALLMENTS'],
            'profile.sales_electronic' => ['required', 'boolean'],
            'profile.inventory_electronic' => ['required', 'boolean'],
            'profile.extra_books' => ['present', 'array', 'list', 'max:26'],
            'profile.extra_books.*' => ['string', 'distinct', Rule::in($bookCodes)],
            'profile.excluded_books' => ['present', 'array', 'list', 'max:26'],
            'profile.excluded_books.*' => ['string', 'distinct', Rule::in($bookCodes)],
            'profile.overrides' => ['present', 'array', 'list', 'max:400'],
            'profile.overrides.*' => ['array:code,label,type,period,date,reference'],
            'profile.overrides.*.code' => ['required', 'regex:/^[A-Z0-9_-]{1,30}$/D'],
            'profile.overrides.*.label' => ['required', 'string', 'max:160'],
            'profile.overrides.*.type' => ['required', 'in:tax,books'],
            'profile.overrides.*.period' => ['required', 'string', "regex:/^$year(-(?:0[1-9]|1[0-2]))?$/D"],
            'profile.overrides.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$year.'-01-01', 'before_or_equal:'.($year+3).'-12-31'],
            'profile.overrides.*.reference' => ['required', 'string', 'max:500'],
        ]);
        $seen = [];
        foreach ($input['profile']['overrides'] as $i => $override) {
            $key = $override['code'].':'.$override['period'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["profile.overrides.$i.code" => 'La obligación ya tiene una fecha particular para ese periodo.']);
            }
            $seen[$key] = true;
        }
        $company = $this->tenant->requireCompanyId(self::class);
        DB::transaction(function () use ($query, $request, $year, $input, $company): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->first();
            $current = (int) ($query->orderByDesc('revision')->value('revision') ?? 0);
            abort_if($current !== (int) $input['expected_revision'], 409, 'La configuración cambió. Consulte el calendario nuevamente.');
            DB::table(self::TABLE)->insert([
                'company_id' => $company, 'year' => $year, 'revision' => $current + 1,
                'settings' => json_encode($input['profile'], JSON_THROW_ON_ERROR),
                'created_by' => $request->user()->id, 'created_at' => now(),
            ]);
        });

        return $this->show($year);
    }

    private function profile(int $year): Builder
    {
        abort_unless($year >= 2025 && $year <= 2099, 404, 'Ejercicio fuera de alcance.');
        abort_unless(Schema::hasTable(self::TABLE), 503, 'Aplique la migración de calendario SUNAT por empresa.');

        return DB::table(self::TABLE)->where('company_id', $this->tenant->requireCompanyId(self::class))->where('year', $year);
    }
}
