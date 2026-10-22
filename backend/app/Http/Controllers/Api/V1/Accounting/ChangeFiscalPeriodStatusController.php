<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\FiscalPeriodStatusService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * F2.3: state-change endpoint behind auth:sanctum, tenant and ADMIN /
 * CHIEF_ACCOUNTANT authorization. No direct user-supplied status allowed.
 */
final readonly class ChangeFiscalPeriodStatusController
{
    public function __construct(private FiscalPeriodStatusService $service) {}

    public function __invoke(Request $request, int $periodId, string $action): JsonResponse
    {
        $payload=$request->validate([
            'reason'=>['required','string','min:10','max:500'],
        ]);
        try {
            $period=$this->service->change(
                $periodId,$action,(string)$payload['reason'],$request
            );
        }catch(DomainException $e){
            throw new ConflictHttpException($e->getMessage(),$e);
        }
        return response()->json([
            'message'=>match($action){
                'close'=>'Período cerrado. No se admiten nuevos asientos ni extornos en este período.',
                'lock'=>'Período bloqueado. La reapertura ordinaria está prohibida.',
                'reopen'=>'Período reabierto con auditoría; admite nuevos asientos.',
                default=>'Cambio de estado del período completado.',
            },
            'data'=>$period,
        ],200);
    }
}
