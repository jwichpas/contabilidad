<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\Company\CompanySunatCalendarController;
use App\Services\Accounting\DaotReport;
use App\Services\Accounting\SunatCalendar;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class DaotReportController
{
    public function __construct(private DaotReport $report, private TenantContext $tenant,
        private CompanySunatCalendarController $calendar, private SunatCalendar $sunat) {}

    public function show(Request $request, int $year): JsonResponse
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'scope' => ['sometimes', 'in:all,pending']]);
        [$profile, $estimate] = $this->context($year);
        $summary = $this->report->summarize($this->documents($year)->orderBy('d.id')->cursor(), $profile['regimes'], DaotReport::UIT[$year] * 2);
        $documents = $this->documents($year)->orderBy('d.issue_date')->orderBy('d.id');
        if (($filters['scope'] ?? null) === 'pending') {
            $documents->whereNull('v.revision')->whereNotIn('d.document_type', ['50', '51', '52', '53'])
                ->whereRaw('COALESCE(x.export_base, 0) < d.taxable_amount');
            foreach ($profile['regimes'] as $month => $regime) {
                if ($regime === 'NRUS') {
                    $documents->whereMonth('d.issue_date', '<>', $month);
                }
            }
        }

        $detail = $documents->simplePaginate(40)->through(fn (object $doc) => $this->report->detail($doc, $profile['regimes']));
        return response()->json(['data' => $summary + [
            'year' => $year, 'ruc' => $profile['ruc'], 'threshold' => DaotReport::UIT[$year] * 2,
            'due' => $estimate, 'status' => $profile['profile']['daot_status'], 'documents' => $detail,
        ]]);
    }

    public function review(Request $request, int $year, int $document): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'included' => ['required', 'boolean'],
            'reference' => ['required', 'string', 'max:500'],
            'base_pen' => ['nullable', 'string', 'regex:/^(?!0+(?:\.0+)?$)\d{1,12}(\.\d{1,4})?$/D'],
        ]);
        [$profile] = $this->context($year);
        $company = $this->tenant->requireCompanyId(self::class);
        $revision = DB::transaction(function () use ($request, $year, $document, $data, $company, $profile): int {
            DB::table('companies')->where('id', $company)->lockForUpdate()->first();
            $doc = $this->documents($year)->where('d.id', $document)->first();
            abort_if(!$doc, 404, 'Comprobante ausente del ejercicio.');
            abort_if((int) ($doc->revision ?? 0) !== (int) $data['expected_revision'], 409, 'Se modificó la revisión. Vuelva a cargar el reporte.');
            $basis = $data['base_pen'] ?? null;
            if ($data['included']) {
                $candidate = clone $doc;
                $candidate->revision = 1;
                $candidate->included = true;
                $candidate->reference = $data['reference'];
                $candidate->base_pen = $basis;
                if ($this->report->detail($candidate, $profile['regimes'])['status'] !== 'included') {
                    throw ValidationException::withMessages(['included' => 'Revise la exclusión legal, la identidad y la base validada en soles.']);
                }
                $domestic = bcsub($doc->taxable_amount, $doc->export_base, 4);
                if (bccomp($doc->export_base, '0', 4) > 0 && $basis === null) {
                    throw ValidationException::withMessages(['base_pen' => 'Indique únicamente la base de las operaciones internas del comprobante mixto.']);
                }
                if ($doc->currency === 'PEN' && $basis !== null && bccomp($basis, $domestic, 4) > 0) {
                    throw ValidationException::withMessages(['base_pen' => 'La base supera el importe sin IGV de las operaciones internas del comprobante.']);
                }
            }
            $next = (int) $data['expected_revision'] + 1;
            DB::table(DaotReport::REVIEWS)->insert([
                'company_id' => $company, 'document_id' => $document, 'revision' => $next, 'included' => $data['included'],
                'base_pen' => $data['included'] ? $basis : null, 'reference' => $data['reference'], 'created_by' => $request->user()->id,
            ]);
            return $next;
        });

        return response()->json(['revision' => $revision]);
    }

    public function csv(int $year, string $format): StreamedResponse
    {
        [$profile] = $this->context($year);
        $company = $this->tenant->requireCompanyId(self::class);
        $documents = $this->documents($year)->orderBy('d.id')->cursor();
        $regimes = $profile['regimes'];
        $threshold = DaotReport::UIT[$year] * 2;
        $name = "DAOT-$format-".$profile['ruc']."-$year.csv";

        if ($format === 'detalle') {
            $headers = ['Movimiento', 'Comprobante', 'Emisión', 'Tipo de identidad', 'Identidad', 'Tercero', 'Base PEN', 'Estado', 'Sustento'];
            $rows = $documents->map(function (object $doc) use ($regimes): array {
                $row = $this->report->detail($doc, $regimes);
                return [$row['module'], $row['document'], $row['date'], $row['identity_type'], $row['identity'], $row['name'],
                    $row['amount_pen'], $row['status'], $row['reason']];
            });
        } else {
            $headers = ['Movimiento', 'Tipo de identidad', 'Identidad', 'Tercero', 'Base PEN', 'Comprobantes', 'Supera 2 UIT'];
            $rows = array_map(static fn (array $row) => [$row['module'], $row['identity_type'], $row['identity'], $row['name'],
                $row['amount_pen'], $row['documents'], $row['over_threshold'] ? 'Sí' : 'No'],
                $this->report->summarize($documents, $regimes, $threshold)['rows']);
        }

        return response()->streamDownload(function () use ($company, $headers, $rows): void {
            $this->tenant->run($company, static function () use ($headers, $rows): void {
                $stream = fopen('php://output', 'wb');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, $headers, ',', '"', '');
                foreach ($rows as $row) {
                    fputcsv($stream, array_map(static function ($cell) {
                        return is_string($cell) && !is_numeric($cell) && preg_match('/^\s*[=+@-]|^[\t\r\n]/', $cell) ? "'".$cell : $cell;
                    }, $row), ',', '"', '');
                }
                fclose($stream);
            });
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function documents(int $year): Builder
    {
        return $this->report->documents($this->tenant->requireCompanyId(self::class))
            ->where('d.issue_date', '>=', "$year-01-01")->where('d.issue_date', '<', ($year + 1).'-01-01');
    }

    private function context(int $year): array
    {
        abort_unless(isset(DaotReport::UIT[$year]), 404, 'No se cargó la UIT del ejercicio.');
        $profile = $this->calendar->report($year);
        $date = DaotReport::due($year, $this->sunat->group($profile['profile']['daot_good'] ? 'BC' : substr($profile['ruc'], -1)));
        foreach ($profile['profile']['overrides'] as $override) {
            if ($override['code'] === 'DAOT' && $override['period'] === (string) $year) {
                $date = $override['date'];
                break;
            }
        }
        return [$profile, $date];
    }
}
