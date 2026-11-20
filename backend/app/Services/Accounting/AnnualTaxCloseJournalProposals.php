<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/** Reference entries for the working paper. Account assignment and posting belong to the Diario. */
final class AnnualTaxCloseJournalProposals
{
    public function build(array $result, string $adminParticipation, string $salesParticipation): array
    {
        $proposals = [];
        $participation = $result['participation'];
        $tax = $result['current_tax'];
        $expense = $result['tax_expense'];
        $assetDelta = bcsub($result['asset_closing'], $result['asset_opening'], 2);
        $liabilityDelta = bcsub($result['liability_closing'], $result['liability_opening'], 2);

        $this->add($proposals, 'PARTICIPATION', 'Provisión de participación de trabajadores', [
            ['622', $participation],
            ['411', bcsub('0', $participation, 2)],
        ]);
        if (bccomp(bcadd($adminParticipation, $salesParticipation, 2), $participation, 2) === 0) {
            $this->add($proposals, 'PARTICIPATION_ALLOCATION', 'Destino analítico de la participación', [
                ['94', $adminParticipation],
                ['95', $salesParticipation],
                ['79', bcsub('0', $participation, 2)],
            ]);
        }
        $this->add($proposals, 'TAX', 'Impuesto corriente y diferido', [
            ['881', $tax],
            ['4017', bcsub('0', $tax, 2)],
            ['882', $result['deferred_expense']],
            ['3712', $assetDelta],
            ['491', bcsub('0', $liabilityDelta, 2)],
        ]);
        $this->add($proposals, 'TAX_TRANSFER', 'Traslado del impuesto al resultado', [
            ['89', $expense],
            ['881', bcsub('0', $tax, 2)],
            ['882', bcsub('0', $result['deferred_expense'], 2)],
        ]);
        $this->add($proposals, 'RESULT_TRANSFER', 'Traslado del resultado a resultados acumulados', [
            ['89', $result['net_profit']],
            ['59', bcsub('0', $result['net_profit'], 2)],
        ]);

        return $proposals;
    }

    private function add(array &$proposals, string $code, string $title, array $signedLines): void
    {
        $lines = [];
        foreach ($signedLines as [$account, $amount]) {
            $sign = bccomp($amount, '0', 2);
            if ($sign !== 0) {
                $lines[] = [
                    'account' => $account,
                    'debit' => $sign > 0 ? bcadd($amount, '0', 2) : '0.00',
                    'credit' => $sign < 0 ? bcsub('0', $amount, 2) : '0.00',
                ];
            }
        }

        if ($lines !== []) {
            $proposals[] = ['code' => $code, 'title' => $title, 'lines' => $lines];
        }
    }
}
