<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PdbExportFiles
{
    public const SOURCE = 'https://www.sunat.gob.pe/orientacion/pdbExpo/instaladores.htm';
    private const BUY = ['01','03','04','05','06','07','08','10','11','12','13','14','15','16','17','18','21','22','23','24','25','26','27','28','29','30','32','34','35','36','37','50','52','53','54','55','87','88','91','97','98'];
    private const SELL_INTERNAL = ['01','03','05','06','07','08','12','15','16','23','25','34','35','36','37'];
    private const SELL_EXPORT = ['01','03','07','08','12','34','35','36','37'];
    private const CUSTOMS_PURCHASE = ['50','52','53','54'];
    private const NOTES = ['07','08','87','88','97','98'];

    public function generate(int $company, string $ruc, string $month, array $options): array
    {
        $period = str_replace('-', '', $month);
        $supplements = collect($options['documents'] ?? [])->keyBy('id');
        $query = DB::table('accounting_tax_register_entries as r')->where('r.company_id', $company)
            ->join('accounting_document_entries as d', fn ($join) => $join->on('d.id', '=', 'r.accounting_document_entry_id')->on('d.company_id', '=', 'r.company_id'))
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'd.journal_entry_id')->on('j.company_id', '=', 'd.company_id'))
            ->join('entities as e', fn ($join) => $join->on('e.id', '=', 'd.entity_id')->on('e.company_id', '=', 'd.company_id'))
            ->join('accounting_tax_register_allocations as a', fn ($join) => $join->on('a.tax_register_entry_id', '=', 'r.id')->on('a.company_id', '=', 'r.company_id'))
            ->where('j.status', 'POSTED')->where(function ($query) use ($period, $supplements): void {
                $query->where('r.tax_period', $period)->orWhereIn('d.id', $supplements->keys());
            })->orderBy('d.id')->orderBy('a.category');
        $records = $query->get(['d.id', 'd.module', 'd.document_type', 'd.series', 'd.number', 'd.issue_date', 'd.currency', 'd.total_amount',
            'r.tax_period', 'r.counterparty_identity_type', 'r.counterparty_tax_id', 'r.counterparty_name', 'e.country_code',
            'a.category', 'a.base_amount', 'a.tax_amount'])->groupBy('id');
        if ($supplements->keys()->diff($records->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['documents' => 'Hay comprobantes ausentes del registro tributario de la empresa activa.']);
        }

        $files = ['C' => [], 'V' => [], 'F' => [], 'tc' => [], 'dua' => [], 'DU1' => [], 'ser' => [], 'exp' => []];
        $problems = [];
        $exportCandidates = [];
        $ratesNeeded = [];
        $customs = [];
        $recordCount = 0;
        foreach ($records as $id => $allocations) {
            $doc = $allocations->first();
            $document = trim("$doc->document_type $doc->series-$doc->number");
            $sale = $doc->module === 'SALE';
            $export = $sale && $allocations->contains('category', 'S_EXPORT');
            $metadata = $supplements->get($id, []);
            if ($export) {
                $exportCandidates[] = ['id' => (int) $id, 'document' => $document, 'period' => $doc->tax_period, 'name' => $doc->counterparty_name];
            }
            if ($doc->tax_period === $period) {
                $recordCount++;
                if ($export && !isset($metadata['shipment']) && !isset($metadata['service'])) {
                    $problems[] = ['document_id' => (int) $id, 'document' => $document, 'file' => 'vínculo',
                        'message' => 'Complete los datos de la exportación si corresponde declararla en este periodo.'];
                }
                try {
                    $code = $sale ? 'V' : 'C';
                    $documentRates = [];
                    $lines = $this->document($doc, $allocations->all(), $metadata, $export, $documentRates);
                    foreach ($documentRates as $date => $required) {
                        $ratesNeeded[$date][$code] = $required;
                    }
                    array_push($files[$code], ...$lines);
                    if (isset($metadata['payments'])) {
                        try {
                            $this->ensure(!$sale, 'El archivo F registra únicamente pagos de adquisiciones.');
                            array_push($files['F'], ...$this->payments($doc, $lines[0], $metadata['payments'], $metadata['total_pen'] ?? null));
                        } catch (InvalidArgumentException $e) {
                            $problems[] = ['document_id' => (int) $id, 'document' => $document, 'file' => 'F', 'message' => $e->getMessage()];
                        }
                    }
                } catch (InvalidArgumentException $e) {
                    $problems[] = ['document_id' => (int) $id, 'document' => $document, 'file' => $sale ? 'V' : 'C', 'message' => $e->getMessage()];
                }
            }
            if (isset($metadata['shipment']) || isset($metadata['service'])) {
                try {
                    $this->ensure($export && $doc->tax_period <= $period && !(isset($metadata['shipment']) && isset($metadata['service'])),
                        'Vincule una sola modalidad a un comprobante de exportación de este periodo o uno anterior.');
                    if (isset($metadata['shipment'])) {
                        $this->shipment($doc, $metadata['shipment'], $month, $customs, $files);
                    } else {
                        $this->service($doc, $metadata['service'], $month, $files);
                    }
                } catch (InvalidArgumentException $e) {
                    $problems[] = ['document_id' => (int) $id, 'document' => $document, 'file' => 'exportación', 'message' => $e->getMessage()];
                }
            }
        }

        $start = CarbonImmutable::parse($month.'-01', 'America/Lima');
        $missingRegister = DB::table('accounting_document_entries as d')->where('d.company_id', $company)
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'd.journal_entry_id')->on('j.company_id', '=', 'd.company_id'))
            ->leftJoin('accounting_tax_register_entries as r', fn ($join) => $join->on('r.accounting_document_entry_id', '=', 'd.id')->on('r.company_id', '=', 'd.company_id'))
            ->where('d.entry_date', '>=', $start->toDateString())->where('d.entry_date', '<', $start->addMonth()->toDateString())
            ->where('j.status', 'POSTED')->whereNull('r.id')->get(['d.id', 'd.module', 'd.document_type', 'd.series', 'd.number']);
        foreach ($missingRegister as $doc) {
            $problems[] = ['document_id' => null, 'document' => "$doc->document_type $doc->series-$doc->number",
                'file' => $doc->module === 'SALE' ? 'V' : 'C', 'message' => 'Comprobante contabilizado sin información en el registro tributario interno.'];
        }

        $files['dua'] = array_values($customs);
        $missingRateFiles = [];
        if ($ratesNeeded !== []) {
            $rates = DB::table('exchange_rates')->where('company_id', $company)->where('source_currency', 'USD')
                ->where('target_currency', 'PEN')->whereIn('rate_date', array_keys($ratesNeeded))
                ->get(['rate_date', 'buy_rate', 'sell_rate'])->keyBy('rate_date');
            ksort($ratesNeeded);
            foreach (array_keys($ratesNeeded) as $date) {
                $rate = $rates[$date] ?? null;
                if ($rate === null) {
                    $missingRateFiles = [...$missingRateFiles, ...array_keys($ratesNeeded[$date])];
                    $problems[] = ['document_id' => null, 'document' => $date, 'file' => 'tc', 'message' => 'Registre el tipo de cambio diario USD/PEN de compra y venta.'];
                    continue;
                }
                $files['tc'][] = [$this->date($date), $this->decimal($rate->buy_rate, 3), $this->decimal($rate->sell_rate, 3)];
            }
        }

        $names = ['C' => "C$ruc$period.txt", 'V' => "V$ruc$period.txt", 'F' => "F$ruc$period.TXT", 'tc' => "$ruc.tc",
            'dua' => "$ruc$period.dua", 'DU1' => "$ruc$period.DU1", 'ser' => "$ruc$period.ser", 'exp' => "$ruc$period.exp"];
        $blocked = array_column($problems, 'file');
        $downloads = [];
        foreach ($files as $key => $lines) {
            $base = in_array($key, ['dua', 'DU1', 'ser', 'exp'], true) ? 'exportación' : $key;
            if ($lines === [] || in_array($base, $blocked, true) ||
                (in_array($key, ['dua', 'DU1', 'ser', 'exp'], true) && (in_array('V', $blocked, true) || in_array('V', $missingRateFiles, true))) ||
                in_array($key, $missingRateFiles, true) ||
                ($key === 'F' && (in_array('C', $blocked, true) || in_array('C', $missingRateFiles, true)))) {
                continue;
            }
            $content = implode('', array_map(static fn (array $fields) => implode('|', $fields).'|'."\r\n", $lines));
            $encoded = mb_convert_encoding($content, 'Windows-1252', 'UTF-8');
            if (mb_convert_encoding($encoded, 'UTF-8', 'Windows-1252') !== $content) {
                $problems[] = ['document_id' => null, 'document' => $names[$key], 'file' => $key, 'message' => 'El archivo contiene caracteres incompatibles con Windows-1252.'];
                continue;
            }
            $downloads[] = ['name' => $names[$key], 'rows' => count($lines), 'review_required' => $key === 'ser', 'base64' => !empty($options['include_files']) ? base64_encode($encoded) : null];
        }
        return ['period' => $month, 'ruc' => $ruc, 'documents' => $recordCount, 'export_candidates' => $exportCandidates,
            'problems' => $problems, 'files' => $downloads, 'source' => self::SOURCE];
    }

    private function document(object $doc, array $allocations, array $meta, bool $export, array &$rates): array
    {
        $sale = $doc->module === 'SALE';
        $this->ensure($doc->issue_date <= CarbonImmutable::today('America/Lima')->toDateString()
            && str_replace('-', '', substr($doc->issue_date, 0, 7)) <= $doc->tax_period, 'Revise la fecha de emisión respecto del periodo PDB.');
        $type = trim($doc->document_type);
        $this->ensure(in_array($type, $sale ? ($export ? self::SELL_EXPORT : self::SELL_INTERNAL) : self::BUY, true),
            'El tipo de comprobante no pertenece al catálogo de este archivo del PDB.');
        $this->ensure(in_array($doc->currency, ['PEN', 'USD'], true), 'Convierta o revise este comprobante en moneda distinta de PEN o USD.');
        $series = $doc->series;
        $number = $doc->number;
        $foreignPurchase = !$sale && in_array($type, [...self::CUSTOMS_PURCHASE, '91', '97', '98'], true);
        if (!$sale && in_array($type, self::CUSTOMS_PURCHASE, true)) {
            $series = $meta['customs_reference'] ?? '';
            $this->ensure(preg_match('/^\d{13}$/D', $series) === 1, 'Ingrese aduana, año y correlativo de la declaración de importación.');
            $number = '';
        } else {
            $numberValid = $type === '12' || (!$sale && in_array($type, ['13', '14', '87', '88'], true))
                ? preg_match('/^[A-Z0-9-]{1,20}$/Di', $number) === 1
                : ctype_digit($number) && strlen($number) <= 20 && ltrim($number, '0') !== '';
            $seriesLimit = $type === '12' ? 20 : (in_array($type, ['01', '03', '04', '07', '08', '55'], true) ? 4 : 10);
            $seriesRequired = in_array($type, ['01', '03', '04', '07', '08', '55'], true);
            $this->ensure($numberValid && (!$seriesRequired || $series !== '')
                && strlen($series) <= $seriesLimit && preg_match('/^[A-Z0-9-]*$/Di', $series) === 1,
                'Revise la serie y el número del comprobante.');
        }
        $identity = trim($doc->counterparty_tax_id);
        $identityType = trim($doc->counterparty_identity_type);
        $companyName = $doc->counterparty_name ?? '';
        $names = ['', '', '', ''];
        $person = '02';
        if ($foreignPurchase || ($sale && $export && $doc->country_code !== 'PER')) {
            $person = '03';
            $this->ensure($identityType === '7' || ($meta['foreign_without_id'] ?? false),
                'Confirme sin documento o registre el pasaporte del tercero no domiciliado.');
            if ($identityType !== '7') {
                $identityType = '-';
                $identity = '';
            }
            $this->ensure(strlen($identity) <= ($sale ? 12 : 15), 'La identificación extranjera supera el máximo del formato.');
        } elseif ($identityType === '6' && preg_match('/^20\d{9}$/D', $identity)) {
            $person = '02';
        } else {
            $person = '01';
            $identityType = $identityType === '0' ? '-' : $identityType;
            if ($identityType === '-') {
                $identity = '';
            }
            $this->ensure(in_array($identityType, ['-', '1', '2', '3', '4', '6', '7'], true), 'El tipo de identificación del tercero requiere revisión.');
            $names = [$meta['surname'] ?? '', $meta['second_surname'] ?? '', $meta['first_name'] ?? '', $meta['second_name'] ?? ''];
            $this->ensure($names[0] !== '' && $names[2] !== '', 'Ingrese apellido paterno y primer nombre del tercero.');
            $companyName = '';
        }
        $name = $this->field($companyName, 40);
        if ($person !== '01') {
            $this->ensure($name !== '', 'Falta la razón social del tercero.');
        }
        foreach ($names as $part) {
            $this->field($part, 20);
        }
        $identity = $this->field($identity, $sale ? 12 : 15);
        $this->ensure($identityType === '-' || $identity !== '', 'Falta el número de identificación del tercero.');

        $taxDate = $doc->issue_date;
        if (!$sale && in_array($type, [...self::CUSTOMS_PURCHASE, '91'], true)) {
            $taxDate = $meta['tax_paid_on'] ?? '';
            $this->ensure($taxDate >= $doc->issue_date && $taxDate <= CarbonImmutable::today('America/Lima')->toDateString()
                && str_replace('-', '', substr($taxDate, 0, 7)) <= $doc->tax_period,
                'Confirme la fecha de pago del impuesto del documento externo.');
        }
        if ($doc->currency === 'USD') {
            $rates[$taxDate] = true;
        }
        $prefix = [$foreignPurchase || $export ? '02' : '01', $type, $this->date($taxDate), $series, $number, $person,
            $identityType, $identity, $name, ...$names, $doc->currency === 'PEN' ? '1' : '2'];
        $classifications = ['P_TAXED_EXCLUSIVE' => 1, 'P_TAXED_MIXED' => 2, 'P_TAXED_NON_TAXED' => 3, 'P_NON_TAXED' => 4,
            'S_TAXED' => 1, 'S_EXONERATED' => 2, 'S_UNAFFECTED' => 2, 'S_EXPORT' => 2];
        $groups = [];
        foreach ($allocations as $allocation) {
            $destination = $classifications[$allocation->category] ?? null;
            $this->ensure($destination !== null, 'La clasificación de IGV requiere confirmar su tratamiento en PDB.');
            $groups[$destination] ??= ['base' => '0.0000', 'tax' => '0.0000'];
            $groups[$destination]['base'] = bcadd($groups[$destination]['base'], $allocation->base_amount, 4);
            $groups[$destination]['tax'] = bcadd($groups[$destination]['tax'], $allocation->tax_amount, 4);
        }
        $this->ensure(!$export || count($allocations) === 1, 'El comprobante combina exportación con otra operación. Revise su clasificación en PDB.');
        ksort($groups);

        $reference = array_fill(0, 6, '');
        if (in_array($type, self::NOTES, true) || $type === '91') {
            $ref = $meta['reference'] ?? null;
            $this->ensure($ref !== null && $ref['date'] <= $doc->issue_date, 'Complete el comprobante de referencia y sus importes de ajuste.');
            $reference = [$ref['type'], $ref['series'] ?? '', $ref['number'], $this->date($ref['date']),
                $this->amount($ref['base']), $export ? '' : $this->amount($ref['tax'])];
            if ($ref['currency'] === 'USD') {
                $rates[$ref['date']] = true;
            }
        }

        $flags = $this->flags($meta, $sale, $export);
        $lines = [];
        foreach ($groups as $destination => $amount) {
            $mixed = count($groups) > 1;
            $base = $this->amount($amount['base']);
            $lines[] = [...$prefix, (string) ($mixed ? ($sale ? 3 : 5) : $destination), (string) ($mixed ? $destination : 1),
                $doc->currency === 'USD' ? '' : $base, $doc->currency === 'USD' ? $base : '0.00',
                $sale && $destination === 2 ? '' : $this->amount($amount['tax']), '0.00', ...$flags, ...$reference];
        }
        return $lines;
    }

    private function flags(array $meta, bool $sale, bool $export): array
    {
        if ($export) {
            return ['', '', '', ''];
        }
        if ($sale) {
            $perception = $meta['perception'] ?? null;
            return $perception ? ['1', $perception['rate'], $perception['series'], $perception['number']] : ['0', '0', '0', '0'];
        }
        $detraction = $meta['detraction'] ?? null;
        return [$detraction ? '1' : '0', $detraction['rate'] ?? '0', $detraction['voucher'] ?? '0', !empty($meta['retention']) ? '1' : '0'];
    }

    private function payments(object $doc, array $line, array $payments, ?string $totalPen): array
    {
        $ceiling = $doc->currency === 'PEN' ? $doc->total_amount : $totalPen;
        $this->ensure($ceiling !== null, 'Valide el total de adquisición en soles para la forma de pago.');
        $paid = '0.00';
        $rows = [];
        foreach ($payments as $payment) {
            $method = $payment['method'];
            $pending = $method === '098';
            $noBank = in_array($method, ['009', '011', '013', '014', '098'], true);
            $date = in_array($method, ['009', '098'], true) ? '' : ($payment['date'] ?? '');
            $this->ensure($noBank || (isset($payment['bank'], $payment['number'])), 'Consigne entidad y número de operación de la adquisición.');
            $this->ensure(in_array($method, ['009', '098'], true) || ($date >= $doc->issue_date && $date <= CarbonImmutable::today('America/Lima')->toDateString()),
                'La fecha del pago no corresponde al comprobante.');
            $this->ensure(!$pending || count($payments) === 1, 'El pago pendiente debe ser la única fila del comprobante.');
            $this->ensure($pending || isset($payment['amount']), 'Consigne el importe del pago en soles.');
            $amount = $pending ? '' : $this->amount($payment['amount']);
            if (!$pending) {
                $paid = bcadd($paid, $amount, 2);
            }
            $rows[] = [$line[0], $line[1], $line[3], $line[4], $line[5], $line[6], $line[7], $method,
                $noBank ? '' : $payment['bank'], $noBank ? '' : $this->field($payment['number'], 30), $date !== '' ? $this->date($date) : '', $amount];
        }
        $this->ensure(bccomp($paid, $this->amount($ceiling), 2) <= 0, 'La suma de pagos supera el total de la adquisición en soles.');
        return $rows;
    }

    private function shipment(object $doc, array $input, string $month, array &$customs, array &$files): void
    {
        [$year] = explode('-', $month);
        $today = CarbonImmutable::today('America/Lima')->toDateString();
        $this->ensure(str_starts_with($input['shipped'], $month.'-') && $input['shipped'] >= $doc->issue_date
            && $input['regularized'] >= $input['shipped'] && $input['regularized'] <= $today,
            'El embarque debe corresponder al periodo, ser posterior o igual a la emisión y contar con regularización.');
        $this->ensure($input['year'] === $year || (str_ends_with($month, '-12') && (int) $input['year'] === (int) $year + 1),
            'El año de la DAM no corresponde al periodo de embarque.');
        $key = $input['office'].$input['year'].str_pad($input['number'], 6, '0', STR_PAD_LEFT);
        $row = [$input['office'], $input['year'], $input['number'], $this->date($input['shipped']),
            $this->date($input['regularized']), $this->amount($input['dam_fob'])];
        $this->ensure(!isset($customs[$key]) || $customs[$key] === $row, 'La misma DAM tiene datos de regularización o FOB diferentes.');
        $this->ensure(bccomp($input['invoice_fob'], $input['dam_fob'], 4) <= 0, 'El FOB facturado supera el FOB de la DAM.');
        $du1 = [$doc->tax_period, trim($doc->document_type), $this->field($doc->series, 20), $this->field($doc->number, 20),
            $input['type'], $key, $this->amount($input['invoice_fob']), '', '', ''];
        $customs[$key] = $row;
        $files['DU1'][] = $du1;
    }

    private function service(object $doc, array $input, string $month, array &$files): void
    {
        $this->ensure($doc->tax_period === str_replace('-', '', $month), 'Vincule los servicios del periodo del comprobante.');
        $paid = $input['payment_method'] !== '098';
        $this->ensure(!$paid || (isset($input['paid_on'], $input['paid_amount'], $input['payment_number'], $input['bank'])
            && $input['paid_on'] >= $doc->issue_date && $input['paid_on'] <= CarbonImmutable::today('America/Lima')->toDateString()),
            'Complete los datos del medio de pago del servicio.');
        $prefix = [$doc->tax_period, trim($doc->document_type), $this->field($doc->series, 20), $this->field($doc->number, 20), '07'];
        $files['exp'][] = $prefix;
        $files['ser'][] = [...$prefix, $input['detail'], $input['country'], $this->field($doc->counterparty_name, 100),
            $input['payment_method'], $paid ? str_replace('-', '', $input['paid_on']) : '',
            $paid ? $this->amount($input['paid_amount']) : '', $paid ? $input['payment_number'] : '', $paid ? $input['bank'] : ''];
    }

    private function amount(string $amount): string
    {
        $rounded = $this->decimal($amount, 2);
        $this->ensure(bccomp($rounded, '9999999999.99', 2) <= 0, 'El importe supera la longitud máxima del PDB.');
        return $rounded;
    }

    private function decimal(string $value, int $scale): string
    {
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }

    private function date(string $date): string
    {
        return substr($date, 8, 2).'/'.substr($date, 5, 2).'/'.substr($date, 0, 4);
    }

    private function field(string $value, int $limit): string
    {
        $this->ensure(mb_strlen($value) <= $limit && !preg_match('/[|\x00-\x1F\x7F]/u', $value), 'Un dato del comprobante contiene caracteres separadores o supera la longitud del PDB.');
        return $value;
    }

    private function ensure(bool $valid, string $message): void
    {
        if (!$valid) {
            throw new InvalidArgumentException($message);
        }
    }
}
