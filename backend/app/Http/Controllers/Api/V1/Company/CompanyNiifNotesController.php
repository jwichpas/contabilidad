<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Narrative notes are operator-authored DRAFTS. The system cannot certify a
 * framework, policy, contingent liability or subsequent event from the GL.
 */
final readonly class CompanyNiifNotesController
{
    public const CATALOG = [
        'N01' => 'Información general de la entidad',
        'N02' => 'Bases de preparación y marco contable aplicable',
        'N03' => 'Políticas contables significativas y estimaciones',
        'N04' => 'Efectivo y equivalentes de efectivo',
        'N05' => 'Cuentas por cobrar y pérdidas crediticias esperadas',
        'N06' => 'Inventarios y costo de ventas',
        'N07' => 'Propiedades, planta y equipo; depreciación',
        'N08' => 'Préstamos, pasivos financieros y riesgos',
        'N09' => 'Tributos, impuesto corriente y diferido',
        'N10' => 'Capital, reservas y movimientos del patrimonio',
        'N11' => 'Ingresos y gastos del período',
        'N12' => 'Conciliación del efectivo y operaciones no monetarias',
        'N13' => 'Provisiones, contingencias y compromisos',
        'N14' => 'Partes relacionadas',
        'N15' => 'Hechos posteriores al cierre',
        'N16' => 'Otras revelaciones requeridas por el marco aplicable',
    ];

    public function __construct(private TenantContext $tenant, private AuditLoggerService $audit) {}

    private function requireSchema(): void
    {
        abort_unless(Schema::hasTable('company_niif_note_revisions'), 503,
            'Falta instalar la migración de borradores de notas NIIF.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireSchema();
        $input = $request->validate(['year' => ['required','integer','between:2000,2100']]);
        $companyId = $this->tenant->requireCompanyId(self::class);
        $rows = DB::table('company_niif_note_revisions')
            ->where('company_id',$companyId)->where('year',(int) $input['year'])
            ->orderBy('note_code')->orderByDesc('revision')
            ->get(['note_code','revision','narrative','status','created_by','created_at']);
        $notes = [];
        foreach (self::CATALOG as $code => $title) {
            $notes[$code] = ['code'=>$code,'title'=>$title,'revision'=>0,'narrative'=>'',
                'status'=>'DRAFT','created_at'=>null,'created_by'=>null];
        }
        foreach ($rows as $row) {
            $code = (string) $row->note_code;
            if (isset($notes[$code]) && $notes[$code]['revision'] === 0) {
                $notes[$code]['revision'] = (int) $row->revision;
                $notes[$code]['narrative'] = (string) $row->narrative;
                $notes[$code]['created_at'] = (string) $row->created_at;
                $notes[$code]['created_by'] = (int) $row->created_by;
            }
        }
        return response()->json(['data'=>['year'=>(int) $input['year'],
            'notes'=>array_values($notes),'is_approved'=>false,
            'warning'=>'Borradores por empresa; no implican aprobación ni cumplimiento NIIF.']])
            ->header('Cache-Control','private, no-store');
    }

    public function save(Request $request, string $code): JsonResponse
    {
        $this->requireSchema();
        abort_unless(isset(self::CATALOG[$code]),404,'Nota inexistente.');
        $input = $request->validate([
            'year'=>['required','integer','between:2000,2100'],
            'expected_revision'=>['required','integer','min:0'],
            'narrative'=>['required','string','max:5000'],
            'company_id'=>['prohibited'],'status'=>['prohibited'],
        ]);
        $companyId = $this->tenant->requireCompanyId(self::class);
        $text = trim($input['narrative']);
        $revision = DB::transaction(function () use ($request,$companyId,$code,$text,$input): int {
            DB::table('companies')->where('id',$companyId)->lockForUpdate()->first();
            $before = DB::table('company_niif_note_revisions')
                ->where('company_id',$companyId)->where('year',(int) $input['year'])
                ->where('note_code',$code)->orderByDesc('revision')->first();
            $current = (int) ($before->revision ?? 0);
            if ($current !== (int) $input['expected_revision']) {
                throw new ConflictHttpException('La nota fue modificada. Recargue para evitar sobrescribir el borrador.');
            }
            DB::table('company_niif_note_revisions')->insert([
                'company_id'=>$companyId,'year'=>(int) $input['year'],'note_code'=>$code,
                'revision'=>$current+1,'narrative'=>$text,'status'=>'DRAFT',
                'created_by'=>(int) $request->user()->id,'created_at'=>now(),
            ]);
            $this->audit->log('COMPANY_NIIF_NOTE_DRAFT_UPDATED',$companyId,(int)$request->user()->id,
                'company_niif_note_revisions',$code.':'.$input['year'].':'.($current+1),
                ['revision'=>$current,'narrative'=>$before->narrative ?? ''],
                ['revision'=>$current+1,'narrative'=>$text],$request);
            return $current+1;
        });
        return response()->json(['data'=>['note_code'=>$code,'year'=>(int)$input['year'],
            'revision'=>$revision,'narrative'=>$text,'status'=>'DRAFT']])
            ->header('Cache-Control','private, no-store');
    }
}
