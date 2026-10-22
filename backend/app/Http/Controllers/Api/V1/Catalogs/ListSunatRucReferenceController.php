<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Authenticated global SUNAT RUC reference snapshots:
 * not interchangeable with PLE/SIRE electronic book table numbers.
 */
final readonly class ListSunatRucReferenceController
{
    public function __invoke(Request $request,string $dataset):JsonResponse
    {
        $table=match($dataset){
            'contributor-types'=>'sunat_ruc_contributor_types',
            'ciiu'=>'sunat_ciiu_rev4_classes',
            'tax-codes'=>'sunat_ruc_tax_codes',
            default=>throw new NotFoundHttpException('Catálogo RUC desconocido'),
        };
        $query=DB::table($table)->where('is_active',true)->orderBy('code');
        // All 417 CIIU entries can be transferred in one short response.
        // Filter by code or description when supplied.
        $q=trim((string)$request->query('q',''));
        if($q!==''){
            if(mb_strlen($q)>80){
                return response()->json(['message'=>'Búsqueda demasiado larga.'],422);
            }
            $query->where(static fn($builder)=>$builder
                ->where('code','ilike','%'.$q.'%')
                ->orWhere('name','ilike','%'.$q.'%'));
        }
        $items=$query->limit(500)->get();
        return response()->json([
            'data'=>$items,
            'meta'=>[
                'dataset'=>$dataset,'version'=>'2026-10-22-v1',
                'total'=>$items->count(),
                'scope'=>'SUNAT_RUC_REFERENCE_NOT_PLE',
            ],
        ]);
    }
}
