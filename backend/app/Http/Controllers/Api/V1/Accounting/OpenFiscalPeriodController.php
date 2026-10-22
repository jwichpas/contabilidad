<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\FiscalPeriodStatusService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /accounting/fiscal-periods
 * auth:sanctum + active tenant + ADMIN/CHIEF_ACCOUNTANT middleware.
 */
final readonly class OpenFiscalPeriodController
{
    public function __construct(private FiscalPeriodStatusService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload=$request->validate([
            'year'=>['required','integer','between:2000,2100'],
            'month'=>['required','integer','between:1,12'],
            'reason'=>['required','string','min:10','max:500'],
        ]);
        try {
            $period=$this->service->open(
                (int)$payload['year'],
                (int)$payload['month'],
                (string)$payload['reason'],
                $request,
            );
        }catch(DomainException $e){
            throw new ConflictHttpException($e->getMessage(),$e);
        }
        return response()->json([
            'message'=>'Período abierto y auditado. Ya puede registrar asientos en este mes.',
            'data'=>$period,
        ],201);
    }
}
