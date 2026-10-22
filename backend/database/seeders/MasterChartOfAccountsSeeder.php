<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AccountingAccountTemplate;
use App\Models\Company;
use App\Services\Accounting\InitializeCompanyChartOfAccountsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeder oficial de Planes Contables Matrices:
 * 1. PCGE 2019 (1,764 cuentas oficiales vigentes).
 * 2. PCGE 2026 (1,637 cuentas oficiales preparadas para NIIF 18 / NIIF 19).
 *
 * Persiste los catálogos matrices en `accounting_account_templates` e inicializa
 * el plan contable aislado e independiente de la empresa activa usando el servicio
 * de clonación descendente `InitializeCompanyChartOfAccountsService`.
 */
final class MasterChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // Cargar la matriz global nunca debe modificar por sorpresa
        // el plan de ninguna empresa; la clonacion requiere empresa explicita.
        $this->seedTemplates();
    }

    public function runForCompany(int $companyId, string $version = 'PCGE_2019', bool $force = false): void
    {
        $this->seedTemplates();

        /** @var InitializeCompanyChartOfAccountsService $service */
        $service = app(InitializeCompanyChartOfAccountsService::class);
        $service->initialize($companyId, $version, $force);
    }

    public function seedTemplates(): void
    {
        $baseDir = dirname(__DIR__, 3);
        $file2019 = $baseDir.DIRECTORY_SEPARATOR.'plan-contable-2019.md';
        $file2026 = $baseDir.DIRECTORY_SEPARATOR.'plan-contable-2026.md';

        if (! is_file($file2019) || ! is_file($file2026)) {
            throw new RuntimeException('Faltan ambas fuentes de PCGE versionadas en el repositorio.');
        }

        $rows2019 = $this->buildAccountTemplates($file2019, 'PCGE_2019');
        $rows2026 = $this->buildAccountTemplates($file2026, 'PCGE_2026');

        // Congelar este contrato: cualquier modificacion de las matrices
        // exige manifiesto/version nueva y revision antes de tocar datos.
        if (count($rows2019) !== 1764 || count($rows2026) !== 1637) {
            throw new RuntimeException('La matriz PCGE no coincide con la fuente de referencia verificada.');
        }

        $allRows = array_merge($rows2019, $rows2026);
        $expected = [];
        foreach ($allRows as $row) {
            $key = $row['plan_version'].'|'.$row['code'];
            if (isset($expected[$key])) {
                throw new RuntimeException('Codigo PCGE duplicado en fuente versionada.');
            }
            $expected[$key] = $row;
        }

        DB::transaction(static function () use ($allRows, $expected): void {
            $existingCount = DB::table('accounting_account_templates')->count();
            if ($existingCount === 0) {
                // Primera instalacion: solo INSERT. Nunca TRUNCATE ni cambio
                // de las cuentas de las empresas.
                foreach (array_chunk($allRows, 200) as $chunk) {
                    DB::table('accounting_account_templates')->insert($chunk);
                }
                $existingCount = DB::table('accounting_account_templates')->count();
            }
            if ($existingCount !== count($expected)) {
                throw new RuntimeException('Matriz PCGE parcial o de version diferente; detener carga sin sobrescribir.');
            }

            $booleanFields = [
                'requires_cost_center', 'has_automatic_destination',
                'requires_cash_flow_area', 'is_operational', 'is_active',
            ];
            $columns = array_keys($allRows[0]);
            $columns = array_values(array_diff($columns, ['created_at','updated_at']));
            $found = 0;
            foreach (DB::table('accounting_account_templates')->select($columns)
                ->orderBy('plan_version')->orderBy('code')->cursor() as $saved) {
                $key = $saved->plan_version.'|'.$saved->code;
                if (! isset($expected[$key])) {
                    throw new RuntimeException('Matriz PCGE contiene un codigo no versionado.');
                }
                foreach ($columns as $field) {
                    $actual = $saved->{$field};
                    $wanted = $expected[$key][$field];
                    if (in_array($field, $booleanFields, true)) {
                        if (filter_var($actual, FILTER_VALIDATE_BOOLEAN) !== (bool) $wanted) {
                            throw new RuntimeException('Diferencia en definicion del PCGE, campo: '.$field);
                        }
                    } elseif (($actual === null) !== ($wanted === null)
                        || ($actual !== null && (string) $actual !== (string) $wanted)) {
                        throw new RuntimeException('Diferencia en definicion del PCGE, campo: '.$field);
                    }
                }
                $found++;
            }
            if ($found !== count($expected)) {
                throw new RuntimeException('Matriz PCGE incompleta: no coinciden las cuentas esperadas.');
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAccountTemplates(string $filePath, string $version): array
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("No se pudo leer el archivo: {$filePath}");
        }

        $elements = [
            '1' => ['name' => 'ACTIVO CORRIENTE', 'type' => 'ASSET', 'balance' => 'DEBIT'],
            '2' => ['name' => 'ACTIVO NO CORRIENTE - INVENTARIOS', 'type' => 'ASSET', 'balance' => 'DEBIT'],
            '3' => ['name' => 'ACTIVO NO CORRIENTE', 'type' => 'ASSET', 'balance' => 'DEBIT'],
            '4' => ['name' => 'PASIVO', 'type' => 'LIABILITY', 'balance' => 'CREDIT'],
            '5' => ['name' => 'PATRIMONIO NETO', 'type' => 'EQUITY', 'balance' => 'CREDIT'],
            '6' => ['name' => 'GASTOS POR NATURALEZA', 'type' => 'EXPENSE', 'balance' => 'DEBIT'],
            '7' => ['name' => 'INGRESOS', 'type' => 'REVENUE', 'balance' => 'CREDIT'],
            '8' => ['name' => 'SALDOS INTERMEDIARIOS DE GESTIÓN', 'type' => 'EQUITY', 'balance' => 'CREDIT'],
            '9' => ['name' => 'CONTABILIDAD ANALÍTICA DE EXPLOTACIÓN', 'type' => 'COST', 'balance' => 'DEBIT'],
            '0' => ['name' => 'CUENTAS DE ORDEN', 'type' => 'MEMORANDUM', 'balance' => 'DEBIT'],
        ];

        $rawAccounts = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '# PLAN')) {
                continue;
            }

            if (preg_match('/^## ELEMENTO (\d+):?\s*(.*)$/i', $line, $m)) {
                $elemDigit = $m[1];
                $elemName = trim($m[2]) ?: ($elements[$elemDigit]['name'] ?? "ELEMENTO {$elemDigit}");
                $rawAccounts[$elemDigit] = [
                    'code' => $elemDigit,
                    'name' => $elemName,
                    'level' => 1,
                    'parent_code' => null,
                    'account_type' => $elements[$elemDigit]['type'] ?? 'ASSET',
                    'balance_type' => $elements[$elemDigit]['balance'] ?? 'DEBIT',
                ];
                continue;
            }

            if (preg_match('/^### (\d{2})\s+(.*)$/', $line, $m)) {
                $code = $m[1];
                $name = trim($m[2]);
                $elem = substr($code, 0, 1);
                if (! isset($rawAccounts[$elem])) {
                    $rawAccounts[$elem] = [
                        'code' => $elem,
                        'name' => $elements[$elem]['name'] ?? "ELEMENTO {$elem}",
                        'level' => 1,
                        'parent_code' => null,
                        'account_type' => $elements[$elem]['type'] ?? 'ASSET',
                        'balance_type' => $elements[$elem]['balance'] ?? 'DEBIT',
                    ];
                }
                $rawAccounts[$code] = [
                    'code' => $code,
                    'name' => $name,
                    'level' => 2,
                    'parent_code' => $elem,
                    'account_type' => $rawAccounts[$elem]['account_type'],
                    'balance_type' => $rawAccounts[$elem]['balance_type'],
                ];
                continue;
            }

            if (preg_match('/^[-*]\s+(\d+)\s+(.*)$/', $line, $m)) {
                $code = $m[1];
                $name = trim($m[2]);
                $len = strlen($code);

                if ($len === 3) {
                    $parentCode = substr($code, 0, 2);
                    $level = 3;
                } elseif ($len === 4) {
                    $parentCode = substr($code, 0, 3);
                    $level = 4;
                } elseif ($len === 5) {
                    $parentCode = substr($code, 0, 4);
                    $level = 5;
                } else {
                    $parentCode = substr($code, 0, $len - 1);
                    $level = min($len, 6);
                }

                $elem = substr($code, 0, 1);
                $rawAccounts[$code] = [
                    'code' => $code,
                    'name' => $name,
                    'level' => $level,
                    'parent_code' => $parentCode,
                    'account_type' => $elements[$elem]['type'] ?? 'ASSET',
                    'balance_type' => $elements[$elem]['balance'] ?? 'DEBIT',
                ];
            }
        }

        // Auto-síntesis de cuentas intermedias faltantes si existieran
        $toAdd = [];
        foreach ($rawAccounts as $code => $acc) {
            $pCode = $acc['parent_code'];
            if ($pCode !== null && ! isset($rawAccounts[$pCode]) && ! isset($toAdd[$pCode])) {
                $len = strlen($pCode);
                $ppCode = null;
                if ($len === 3) {
                    $ppCode = substr($pCode, 0, 2);
                } elseif ($len === 4) {
                    $ppCode = substr($pCode, 0, 3);
                } elseif ($len === 2) {
                    $ppCode = substr($pCode, 0, 1);
                }

                $elem = substr($pCode, 0, 1);
                $toAdd[$pCode] = [
                    'code' => $pCode,
                    'name' => 'Cuenta ' . $pCode,
                    'level' => $len,
                    'parent_code' => $ppCode,
                    'account_type' => $elements[$elem]['type'] ?? 'ASSET',
                    'balance_type' => $elements[$elem]['balance'] ?? 'DEBIT',
                ];
            }
        }

        foreach ($toAdd as $pCode => $pAcc) {
            $rawAccounts[$pCode] = $pAcc;
        }

        // Identificar hojas (leaf nodes)
        $parentCodes = [];
        foreach ($rawAccounts as $acc) {
            if ($acc['parent_code'] !== null) {
                $parentCodes[$acc['parent_code']] = true;
            }
        }

        // Ordenar jerárquicamente por nivel para computar paths sin saltos
        uasort($rawAccounts, function (array $a, array $b): int {
            if ($a['level'] !== $b['level']) {
                return $a['level'] <=> $b['level'];
            }
            return strcmp((string) $a['code'], (string) $b['code']);
        });

        $paths = [];
        $rows = [];
        $now = Carbon::now()->toIso8601String();

        foreach ($rawAccounts as $rawCode => $acc) {
            $code = (string) $acc['code'];
            $isLeaf = ! isset($parentCodes[$code]);
            $level = $acc['level'];
            $parentCode = $acc['parent_code'];

            if ($level === 1 || $parentCode === null) {
                $path = $code;
            } else {
                $path = ($paths[$parentCode] ?? $parentCode) . '.' . $code;
            }
            $paths[$code] = $path;

            // Determinar moneda
            $lowerName = strtolower($acc['name']);
            $currencyMode = 'PEN';
            if (
                str_contains($lowerName, 'dólares') ||
                str_contains($lowerName, 'dolares') ||
                str_contains($lowerName, 'extranjera') ||
                $code === '1042' ||
                $code === '1062'
            ) {
                $currencyMode = 'USD';
            }

            // Determinar entidad/anexo requerido
            $entityReq = 'NONE';
            if ($isLeaf && $level >= 3) {
                if (str_starts_with($code, '104') || str_starts_with($code, '106') || str_starts_with($code, '45')) {
                    $entityReq = 'FINANCIAL_INSTITUTION';
                } elseif (str_starts_with($code, '12') || str_starts_with($code, '13')) {
                    $entityReq = 'CUSTOMER';
                } elseif (str_starts_with($code, '14') || str_starts_with($code, '41')) {
                    $entityReq = 'EMPLOYEE';
                } elseif (str_starts_with($code, '42') || str_starts_with($code, '43')) {
                    $entityReq = 'SUPPLIER';
                } elseif (str_starts_with($code, '40')) {
                    $entityReq = 'TAX_AUTHORITY';
                } elseif (str_starts_with($code, '44')) {
                    $entityReq = 'SHAREHOLDER';
                } elseif (
                    str_starts_with($code, '16') ||
                    str_starts_with($code, '17') ||
                    str_starts_with($code, '46') ||
                    str_starts_with($code, '47')
                ) {
                    $entityReq = 'ANY_THIRD_PARTY';
                }
            }

            // Centro de costos y destino automático
            $reqCostCenter = false;
            $hasAutoDest = false;
            $destDebit = null;
            $destCredit = null;

            if ($isLeaf && $level >= 3) {
                if (
                    str_starts_with($code, '62') ||
                    str_starts_with($code, '63') ||
                    str_starts_with($code, '64') ||
                    str_starts_with($code, '65') ||
                    str_starts_with($code, '67') ||
                    str_starts_with($code, '68')
                ) {
                    $reqCostCenter = true;
                    $hasAutoDest = true;
                    $destDebit = '9411';
                    $destCredit = '7911';
                } elseif (str_starts_with($code, '94') || str_starts_with($code, '95')) {
                    $reqCostCenter = true;
                }
            }

            // Área de Flujo de Efectivo (Elemento 10)
            $reqCashFlow = str_starts_with($code, '10');
            $cashFlowCat = $reqCashFlow ? 'CASH_EQUIVALENT' : 'NONE';

            // Rubro de Estados Financieros para cuentas operativas
            $fsRubric = 'NONE';
            if ($isLeaf && $level >= 3) {
                if (str_starts_with($code, '10')) {
                    $fsRubric = 'ESF_ACT_CORR_EFE';
                } elseif (str_starts_with($code, '12') || str_starts_with($code, '13')) {
                    $fsRubric = 'ESF_ACT_CORR_CXC_COM';
                } elseif (str_starts_with($code, '14') || str_starts_with($code, '16') || str_starts_with($code, '17')) {
                    $fsRubric = 'ESF_ACT_CORR_OTR_CXC';
                } elseif (str_starts_with($code, '2')) {
                    $fsRubric = 'ESF_ACT_CORR_INV';
                } elseif (
                    str_starts_with($code, '33') ||
                    str_starts_with($code, '34') ||
                    str_starts_with($code, '35') ||
                    str_starts_with($code, '36') ||
                    str_starts_with($code, '38') ||
                    str_starts_with($code, '39')
                ) {
                    $fsRubric = 'ESF_ACT_NCORR_IME';
                } elseif (str_starts_with($code, '37')) {
                    $fsRubric = 'ESF_ACT_NCORR_INT';
                } elseif (str_starts_with($code, '40')) {
                    $fsRubric = 'ESF_PAS_CORR_TRIB';
                } elseif (str_starts_with($code, '41')) {
                    $fsRubric = 'ESF_PAS_CORR_REM';
                } elseif (str_starts_with($code, '42') || str_starts_with($code, '43')) {
                    $fsRubric = 'ESF_PAS_CORR_CXP_COM';
                } elseif (str_starts_with($code, '45')) {
                    $fsRubric = 'ESF_PAS_OBLIG_FIN';
                } elseif (str_starts_with($code, '50') || str_starts_with($code, '51') || str_starts_with($code, '52')) {
                    $fsRubric = 'ESF_PAT_CAP';
                } elseif (str_starts_with($code, '59')) {
                    $fsRubric = 'ESF_PAT_RES_ACUM';
                } elseif (str_starts_with($code, '70')) {
                    $fsRubric = 'ER_ING_VENTAS_NETAS';
                } elseif (str_starts_with($code, '75')) {
                    $fsRubric = 'ER_ING_OTROS_OPER';
                } elseif (str_starts_with($code, '60')) {
                    $fsRubric = 'ERN_COMPRAS_MERC';
                } elseif (str_starts_with($code, '62')) {
                    $fsRubric = 'ERN_GASTOS_PERSONAL';
                } elseif (str_starts_with($code, '63')) {
                    $fsRubric = 'ERN_SERV_TERCEROS';
                } elseif (str_starts_with($code, '64')) {
                    $fsRubric = 'ERN_GASTOS_TRIBUTOS';
                } elseif (str_starts_with($code, '65')) {
                    $fsRubric = 'ERN_OTROS_GASTOS_GESTION';
                } elseif (str_starts_with($code, '69')) {
                    $fsRubric = 'ERF_COSTO_VENTAS';
                } elseif (str_starts_with($code, '94')) {
                    $fsRubric = 'ERF_GASTOS_ADMIN_94';
                } elseif (str_starts_with($code, '95')) {
                    $fsRubric = 'ERF_GASTOS_VENTAS_95';
                } elseif (str_starts_with($code, '79')) {
                    $fsRubric = 'CONTROL_CARGAS_IMPUTABLES_79';
                }
            }

            $rows[] = [
                'plan_version' => $version,
                'code' => $code,
                'name' => $acc['name'],
                'level' => $level,
                'parent_code' => $parentCode,
                'path' => $path,
                'account_type' => $acc['account_type'],
                'balance_type' => $acc['balance_type'],
                'currency_mode' => $currencyMode,
                'entity_requirement_type' => $entityReq,
                'requires_cost_center' => $reqCostCenter,
                'has_automatic_destination' => $hasAutoDest,
                'destination_debit_code' => $destDebit,
                'destination_credit_code' => $destCredit,
                'requires_cash_flow_area' => $reqCashFlow,
                'cash_flow_category' => $cashFlowCat,
                'financial_statement_rubric' => $fsRubric,
                'is_operational' => ($isLeaf && $level >= 3),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
