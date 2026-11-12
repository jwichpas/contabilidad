<?php

declare(strict_types=1);

use App\Services\ExchangeRates\SunatCurrentExchangeRateClient;

it('parses the published SUNAT pipe-separated quote to six decimals', function (): void {
    expect(SunatCurrentExchangeRateClient::parse("12/11/2026|3.407|3.414|\n"))
        ->toBe(['date' => '2026-11-12', 'buy' => '3.407000', 'sell' => '3.414000']);

    expect(SunatCurrentExchangeRateClient::parse("\xEF\xBB\xBF12/11/2026|3.407|3.414|\r\n"))
        ->toBe(['date' => '2026-11-12', 'buy' => '3.407000', 'sell' => '3.414000']);
});

it('rejects invalid and potentially dangerous SUNAT payloads', function (string $body): void {
    expect(SunatCurrentExchangeRateClient::parse($body))->toBeNull();
})->with([
    '<html>Unavailable</html>',
    '31/02/2026|3.407|3.414|',
    '12/11/2026|0|3.414|',
    '12/11/2026|3.407|-3.414|',
    '12/11/2026|3.407|100000|',
    '12/11/2026|3.500|3.414|',
    '12/11/2026|3,407|3,414|',
    '12/11/2026|3.407|3.414',
    '12/11/2026|3.407|3.414|HACK',
    '12/11/2026|3.4070000|3.414|',
    "12/11/2026|3.407|3.414|\nNEXT",
    '',
]);
