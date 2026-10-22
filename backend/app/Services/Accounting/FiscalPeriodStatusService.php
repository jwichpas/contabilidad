<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\AuditLog;
use App\Models\FiscalPeriod;
use App\Support\Tenant\TenantContext;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * F2.3 — serializable period lifecycle.
 *
 * All mutations run under SetTenantContext / FORCE RLS, and lock the very
 * fiscal_periods row which PostJournalEntryService::post/reverse lock.
 *
 * OPEN -> CLOSED -> LOCKED; CLOSED -> OPEN is an audited exception.
 * LOCKED cannot be reopened by this endpoint.
 *
 * The state mutation and its audit must COMMIT or ROLLBACK together.
 */
final readonly class FiscalPeriodStatusService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Explicitly initialize one monthly period for the active company.
     * Never called by GET; never overwrites CLOSED / LOCKED periods.
     * The unique company/year/month index serializes concurrent requests.
     */
    public function open(int $year, int $month, string $reason, Request $request): FiscalPeriod
    {
        $companyId=$this->tenantContext->requireCompanyId(FiscalPeriod::class);
        $actorId=(int)$request->user()->id;
        $reason=trim($reason);
        if($year<2000||$year>2100||$month<1||$month>12){
            throw new DomainException('Ejercicio o mes fuera de rango.');
        }
        if(mb_strlen($reason)<10||mb_strlen($reason)>500){
            throw new DomainException('Debe justificar la apertura con 10 a 500 caracteres.');
        }

        $start=sprintf('%04d-%02d-01',$year,$month);
        $end=\Carbon\CarbonImmutable::parse($start,'UTC')->endOfMonth()->toDateString();

        try {
            return DB::transaction(function()use(
                $companyId,$actorId,$year,$month,$start,$end,$reason,$request
            ):FiscalPeriod {
                // An existing CLOSED / LOCKED period may never be "opened"
                // by re-creating it. REOPEN is a separate audited transition.
                if(FiscalPeriod::query()
                    ->where('company_id',$companyId)->where('year',$year)
                    ->where('month',$month)->exists()){
                    throw new DomainException(
                        'El período ya existe. Actualice la pantalla y utilice su estado actual.'
                    );
                }

                $period=FiscalPeriod::query()->create([
                    'company_id'=>$companyId,
                    'year'=>$year,
                    'month'=>$month,
                    'start_date'=>$start,
                    'end_date'=>$end,
                    'status'=>'OPEN',
                    'closed_at'=>null,
                ]);

                // The opening and its audit commit together. No silent errors.
                AuditLog::query()->create([
                    'company_id'=>$companyId,
                    'user_id'=>$actorId,
                    'event_type'=>'FISCAL_PERIOD_OPENED',
                    'auditable_type'=>FiscalPeriod::class,
                    'auditable_id'=>(string)$period->id,
                    'old_values'=>null,
                    'new_values'=>[
                        'status'=>'OPEN','year'=>$year,'month'=>$month,'reason'=>$reason,
                    ],
                    'ip_address'=>substr((string)($request->ip()??'127.0.0.1'),0,45),
                    'user_agent'=>substr((string)($request->userAgent()??'system'),0,500),
                    'severity'=>'INFO',
                ]);

                return $period->refresh();
            });
        }catch(\Illuminate\Database\QueryException $e){
            // UNIQUE(company_id,year,month) is the final race protection.
            if((string)($e->errorInfo[0]??$e->getCode())==='23505'){
                throw new DomainException(
                    'Otro usuario ya registró este período. Actualice la pantalla.',
                    previous:$e
                );
            }
            throw $e;
        }
    }

    public function change(int $periodId, string $action, string $reason, Request $request): FiscalPeriod
    {
        $companyId=$this->tenantContext->requireCompanyId(FiscalPeriod::class);
        $actorId=(int)$request->user()->id;
        $reason=trim($reason);

        if(strlen($reason)<10 || strlen($reason)>500){
            throw new DomainException('El motivo del cierre o reapertura debe contener de 10 a 500 caracteres.');
        }

        $transitions=[
            'close'=>['from'=>'OPEN','to'=>'CLOSED','event'=>'FISCAL_PERIOD_CLOSED'],
            'lock'=>['from'=>'CLOSED','to'=>'LOCKED','event'=>'FISCAL_PERIOD_LOCKED'],
            'reopen'=>['from'=>'CLOSED','to'=>'OPEN','event'=>'FISCAL_PERIOD_REOPENED'],
        ];
        if(!isset($transitions[$action])){
            throw new DomainException('Operación de período contable no reconocida.');
        }
        $transition=$transitions[$action];

        return DB::transaction(function () use(
            $periodId,$companyId,$actorId,$reason,$request,$action,$transition
        ):FiscalPeriod {
            // Same lock as POST and reverse, including the tenant boundary.
            $period=FiscalPeriod::query()
                ->where('company_id',$companyId)
                ->whereKey($periodId)->lockForUpdate()->first();
            if($period===null){
                throw new NotFoundHttpException('El período contable no existe en la empresa activa.');
            }
            if($period->status!==$transition['from']){
                throw new DomainException(sprintf(
                    'No se permite %s el período en estado %s (estado requerido: %s).',
                    $action,$period->status,$transition['from']
                ));
            }

            // Defense in depth: a broken direct DB import must not make a
            // "closed" period conceal unbalanced vouchers.
            if($action==='close'){
                $invalid=DB::table('journal_entries as j')
                    // EXISTS must select only a GROUP BY key: SELECT * would
                    // attempt non-grouped l.* columns under PostgreSQL.
                    ->select('j.id')
                    ->leftJoin('journal_entry_lines as l',function($join):void{
                        $join->on('l.journal_entry_id','=','j.id')
                            ->on('l.company_id','=','j.company_id');
                    })
                    ->where('j.company_id',$companyId)
                    ->where('j.fiscal_period_id',$periodId)
                    ->groupBy('j.id')
                    ->havingRaw('COUNT(l.id) < 2')
                    ->orHavingRaw(
                        'COALESCE(SUM(l.debit_local),0) <> COALESCE(SUM(l.credit_local),0)'
                    )
                    ->orHavingRaw(
                        'COALESCE(SUM(l.debit_foreign),0) <> COALESCE(SUM(l.credit_foreign),0)'
                    )->limit(1)->exists();
                if($invalid){
                    throw new DomainException('No se puede cerrar: existen asientos con partidas incompletas o descuadradas.');
                }
            }

            $previous=$period->status;
            $period->status=$transition['to'];
            $period->closed_at=$action==='reopen'?null:($period->closed_at??now());
            $period->save();

            // Deliberately NOT AuditLoggerService::log, which swallows SQL
            // errors. State and audit are all-or-nothing here.
            AuditLog::query()->create([
                'company_id'=>$companyId,
                'user_id'=>$actorId,
                'event_type'=>$transition['event'],
                'auditable_type'=>FiscalPeriod::class,
                'auditable_id'=>(string)$periodId,
                'old_values'=>['status'=>$previous],
                'new_values'=>[
                    'status'=>$transition['to'],
                    'reason'=>$reason,
                    'year'=>(int)$period->year,
                    'month'=>(int)$period->month,
                ],
                'ip_address'=>substr((string)($request->ip()??'127.0.0.1'),0,45),
                'user_agent'=>substr((string)($request->userAgent()??'system'),0,500),
                'severity'=>$action==='reopen'?'WARNING':'INFO',
            ]);

            return $period->refresh();
        });
    }
}
