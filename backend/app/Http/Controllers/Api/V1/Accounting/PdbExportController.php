<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\PdbExportFiles;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class PdbExportController
{
    private const MONEY = ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,4})?$/D'];

    public function __construct(private PdbExportFiles $exports, private TenantContext $tenant) {}

    public function __invoke(Request $request, string $month): JsonResponse
    {
        $options = $request->validate([
            'include_files' => ['sometimes', 'boolean'],
            'documents' => ['sometimes', 'array', 'list', 'max:1000'],
            'documents.*' => ['array:id,surname,second_surname,first_name,second_name,foreign_without_id,customs_reference,tax_paid_on,retention,detraction,perception,reference,shipment,service,payments,total_pen'],
            'documents.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'documents.*.surname' => ['sometimes', 'string', 'max:20'],
            'documents.*.second_surname' => ['sometimes', 'string', 'max:20'],
            'documents.*.first_name' => ['sometimes', 'string', 'max:20'],
            'documents.*.second_name' => ['sometimes', 'string', 'max:20'],
            'documents.*.foreign_without_id' => ['sometimes', 'boolean'],
            'documents.*.customs_reference' => ['sometimes', 'regex:/^\d{13}$/D'],
            'documents.*.tax_paid_on' => ['sometimes', 'date_format:Y-m-d'],
            'documents.*.retention' => ['sometimes', 'boolean'],
            'documents.*.detraction' => ['sometimes', 'array:rate,voucher', 'required_array_keys:rate,voucher'],
            'documents.*.detraction.rate' => ['required_with:documents.*.detraction', 'regex:/^\d{5}$/D'],
            'documents.*.detraction.voucher' => ['required_with:documents.*.detraction', 'regex:/^[A-Z0-9]{1,15}$/Di'],
            'documents.*.perception' => ['sometimes', 'array:rate,series,number', 'required_array_keys:rate,series,number'],
            'documents.*.perception.rate' => ['required_with:documents.*.perception', 'in:01,02,03,04,05,06,07'],
            'documents.*.perception.series' => ['required_with:documents.*.perception', 'regex:/^[A-Z0-9]{1,10}$/Di'],
            'documents.*.perception.number' => ['required_with:documents.*.perception', 'regex:/^\d{1,20}$/D'],
            'documents.*.reference' => ['sometimes', 'array:type,series,number,date,base,tax,currency', 'required_array_keys:type,number,date,base,tax,currency'],
            'documents.*.reference.type' => ['required_with:documents.*.reference', 'regex:/^\d{2}$/D'],
            'documents.*.reference.series' => ['nullable', 'regex:/^[A-Z0-9-]{1,10}$/Di'],
            'documents.*.reference.number' => ['required_with:documents.*.reference', 'regex:/^[A-Z0-9-]{1,20}$/Di'],
            'documents.*.reference.date' => ['required_with:documents.*.reference', 'date_format:Y-m-d'],
            'documents.*.reference.base' => $this->dependentMoney('reference'),
            'documents.*.reference.tax' => $this->dependentMoney('reference'),
            'documents.*.reference.currency' => ['required_with:documents.*.reference', 'in:PEN,USD'],
            'documents.*.shipment' => ['sometimes', 'array:office,year,number,shipped,regularized,dam_fob,invoice_fob,type', 'required_array_keys:office,year,number,shipped,regularized,dam_fob,invoice_fob,type'],
            'documents.*.shipment.office' => ['required_with:documents.*.shipment', 'regex:/^\d{3}$/D'],
            'documents.*.shipment.year' => ['required_with:documents.*.shipment', 'regex:/^20\d{2}$/D'],
            'documents.*.shipment.number' => ['required_with:documents.*.shipment', 'regex:/^(?!0+$)\d{1,6}$/D'],
            'documents.*.shipment.shipped' => ['required_with:documents.*.shipment', 'date_format:Y-m-d'],
            'documents.*.shipment.regularized' => ['required_with:documents.*.shipment', 'date_format:Y-m-d'],
            'documents.*.shipment.dam_fob' => $this->dependentMoney('shipment'),
            'documents.*.shipment.invoice_fob' => $this->dependentMoney('shipment'),
            'documents.*.shipment.type' => ['required_with:documents.*.shipment', 'in:01,05'],
            'documents.*.total_pen' => ['nullable', ...array_slice(self::MONEY, 1)],
            'documents.*.payments' => ['sometimes', 'array', 'list', 'min:1', 'max:30'],
            'documents.*.payments.*' => ['array:method,bank,number,date,amount'],
            'documents.*.payments.*.method' => ['required', 'in:001,002,003,004,005,006,007,009,010,011,012,013,014,098'],
            'documents.*.payments.*.bank' => ['nullable', 'regex:/^\d{2}$/D'],
            'documents.*.payments.*.number' => ['nullable', 'string', 'max:30'],
            'documents.*.payments.*.date' => ['nullable', 'date_format:Y-m-d'],
            'documents.*.payments.*.amount' => ['nullable', ...array_slice(self::MONEY, 1)],
            'documents.*.service' => ['sometimes', 'array:detail,country,payment_method,paid_on,paid_amount,payment_number,bank', 'required_array_keys:detail,country,payment_method'],
            'documents.*.service.detail' => ['required_with:documents.*.service', 'regex:/^\d{2}$/D'],
            'documents.*.service.country' => ['required_with:documents.*.service', 'regex:/^[A-Z]{2}$/D'],
            'documents.*.service.payment_method' => ['required_with:documents.*.service', 'in:001,002,003,004,005,006,007,009,010,011,012,013,014,098'],
            'documents.*.service.paid_on' => ['nullable', 'date_format:Y-m-d'],
            'documents.*.service.paid_amount' => ['nullable', ...array_slice(self::MONEY, 1)],
            'documents.*.service.payment_number' => ['nullable', 'regex:/^\d{1,30}$/D'],
            'documents.*.service.bank' => ['nullable', 'regex:/^\d{2}$/D'],
        ]);
        $company = $this->tenant->requireCompanyId(self::class);
        $ruc = (string) DB::table('companies')->where('id', $company)->value('tax_id');
        abort_unless(preg_match('/^\d{11}$/D', $ruc), 409, 'Configure el RUC de la empresa.');

        return response()->json(['data' => $this->exports->generate($company, $ruc, $month, $options)],
            200, ['Cache-Control' => 'no-store']);
    }

    private function dependentMoney(string $group): array
    {
        return ["required_with:documents.*.$group", ...array_slice(self::MONEY, 1)];
    }
}
