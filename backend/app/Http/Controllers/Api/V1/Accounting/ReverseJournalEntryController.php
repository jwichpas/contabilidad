<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\JournalEntry;
use App\Services\Accounting\PostJournalEntryService;
use App\Services\Security\AuditLoggerService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Extorno contable: NO modifica las partidas del asiento original.
 * Crea un asiento REVERSAL de signo invertido, trazable y balanceado.
 * Requiere auth:sanctum, SetTenantContext y rol contable en routes/api.php.
 */
final readonly class ReverseJournalEntryController
{
    public function __construct(
        private PostJournalEntryService $service,
        private AuditLoggerService $audit,
    ) {}

    public function __invoke(Request $request, int $entryId): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_period_id' => ['required','integer','min:1'],
            'reversal_date' => ['required','date_format:Y-m-d'],
            'entry_number' => ['nullable','string','max:30'],
            'reason' => ['required','string','min:3','max:250'],
        ]);

        try {
            $reversal = $this->service->reverse(
                originalEntryId: $entryId,
                targetFiscalPeriodId: (int) $validated['fiscal_period_id'],
                reversalEntryNumber: trim((string) ($validated['entry_number'] ?? '')),
                reversalDate: $validated['reversal_date'],
                reason: trim($validated['reason']),
                userId: (int) $request->user()->id
            );
        } catch (DomainException $exception) {
            return response()->json(['message'=>$exception->getMessage()],422);
        }

        $this->audit->log(
            eventType:'JOURNAL_ENTRY_REVERSED',
            companyId:(int) $reversal->company_id,
            userId:(int) $request->user()->id,
            auditableType:JournalEntry::class,
            auditableId:(string)$entryId,
            oldValues:['status'=>'POSTED'],
            newValues:[
                'status'=>'REVERSED',
                'reversal_entry_id'=>(int)$reversal->id,
                'reversal_entry_number'=>$reversal->entry_number,
            ],
            request:$request,
            severity:'INFO',
        );

        $reversal->load(['lines.account','fiscalPeriod']);
        return response()->json([
            'message'=>'Extorno contable registrado. El asiento original permanece inmutable.',
            'data'=>$reversal,
        ],201);
    }
}
