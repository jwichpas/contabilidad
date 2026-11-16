<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Services\Accounting\CashFlowPresentationCatalog as Catalog;
use App\Services\Accounting\CashFlowPresentationSettingsService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only global catalog + scoped, versioned company presentation overlays. */
final readonly class CompanyCashFlowPresentationController
{
    public function __construct(
        private CashFlowPresentationSettingsService $settings,
        private TenantContext $tenant,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->latest(),
            'standard' => Catalog::forApi(), 'history' => $this->settings->history()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = $this->tenant->requireCompanyId(self::class);
        $input = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'company_id' => ['prohibited'],
            'id' => ['prohibited'],
            'settings' => ['required', 'array:hide_zero,labels,hidden_codes,custom_lines,account_rules,area_rules'],
            'settings.hide_zero' => ['required', 'boolean'],
            'settings.labels' => ['required', 'array', 'max:60'],
            'settings.labels.*' => ['required', 'string', 'min:2', 'max:120'],
            'settings.hidden_codes' => ['required', 'array', 'max:70'],
            'settings.hidden_codes.*' => ['required', 'string', 'max:16', 'distinct'],
            'settings.custom_lines' => ['required', 'array', 'max:40'],
            'settings.custom_lines.*' => ['required', 'array:code,label,section,direction'],
            'settings.custom_lines.*.code' => ['required', 'string', 'regex:/^C[0-9]{4}$/'],
            'settings.custom_lines.*.label' => ['required', 'string', 'min:2', 'max:120'],
            'settings.custom_lines.*.section' => ['required', 'string', 'in:OPERATING,INVESTING,FINANCING'],
            'settings.custom_lines.*.direction' => ['required', 'string', 'in:INFLOW,OUTFLOW'],
            'settings.account_rules' => ['required', 'array', 'max:100'],
            'settings.account_rules.*' => ['required', 'array:account_code,section,direction,target_code'],
            'settings.account_rules.*.account_code' => ['required', 'string', 'regex:/^[0-9]{3,8}$/'],
            'settings.account_rules.*.section' => ['required', 'string', 'in:OPERATING,INVESTING,FINANCING'],
            'settings.account_rules.*.direction' => ['required', 'string', 'in:INFLOW,OUTFLOW'],
            'settings.account_rules.*.target_code' => ['required', 'string', 'max:16'],
            'settings.area_rules' => ['required', 'array', 'max:100'],
            'settings.area_rules.*' => ['required', 'array:area_code,section,direction,target_code'],
            'settings.area_rules.*.area_code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{2,32}$/'],
            'settings.area_rules.*.section' => ['required', 'string', 'in:OPERATING,INVESTING,FINANCING'],
            'settings.area_rules.*.direction' => ['required', 'string', 'in:INFLOW,OUTFLOW'],
            'settings.area_rules.*.target_code' => ['required', 'string', 'max:16'],
        ]);
        $settings = $input['settings'];
        $customs = [];
        foreach ($settings['custom_lines'] as &$line) {
            $line['label'] = trim($line['label']);
            if ($line['label'] === '' || isset($customs[$line['code']])) {
                throw ValidationException::withMessages(['settings.custom_lines' => ['Código duplicado o descripción vacía.']]);
            }
            $customs[$line['code']] = true;
        }
        unset($line);

        $usedCodes = array_fill_keys(array_keys(Catalog::LINES), true) + $customs;
        foreach ($settings['labels'] as $code => &$label) {
            $label = trim($label);
            if (($entry = Catalog::line((string) $code, $settings)) === null
                || $entry['kind'] !== 'detail' || $label === '') {
                throw ValidationException::withMessages(['settings.labels' => ['Solo se puede renombrar conceptos de detalle existentes.']]);
            }
        }
        unset($label);
        foreach ($settings['hidden_codes'] as $code) {
            if (! isset($usedCodes[$code]) || Catalog::line($code, $settings)['kind'] !== 'detail') {
                throw ValidationException::withMessages(['settings.hidden_codes' => ['No se pueden ocultar códigos de control o totales.']]);
            }
        }
        foreach (['account_rules', 'area_rules'] as $key) {
            $seen = [];
            foreach ($settings[$key] as $rule) {
                $target = (string) $rule['target_code'];
                $section = $rule['section'];
                $direction = $rule['direction'];
                if (! Catalog::validTarget($target, $settings, $section, $direction)) {
                    throw ValidationException::withMessages([$key => ['El concepto de destino no corresponde a la actividad y dirección.']]);
                }
                $identifier = $key === 'account_rules' ? $rule['account_code'] : $rule['area_code'];
                $unique = $identifier.'|'.$section.'|'.$direction;
                if (isset($seen[$unique])) {
                    throw ValidationException::withMessages([$key => ['Hay reglas duplicadas para la misma cuenta/área.']]);
                }
                $seen[$unique] = true;
                if ($key === 'account_rules') {
                    $account = DB::table('accounting_accounts')->where('company_id', $companyId)
                        ->where('code', $identifier)->where('is_operational', true)->first(['id']);
                    if ($account === null || str_starts_with($identifier, '10')) {
                        throw ValidationException::withMessages(['settings.account_rules' => ['La cuenta PCGE debe existir, ser operativa y no ser de efectivo.']]);
                    }
                } else {
                    $area = DB::table('cash_flow_areas')->where('company_id', $companyId)
                        ->where('code', $identifier)->first(['activity_category', 'flow_direction']);
                    if ($area === null || $area->activity_category !== $section || $area->flow_direction !== $direction) {
                        throw ValidationException::withMessages(['settings.area_rules' => ['Área inexistente o con dirección/actividad distinta.']]);
                    }
                }
            }
        }

        $result = $this->settings->save($settings, (int) $input['expected_revision'], $request);
        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }
}
