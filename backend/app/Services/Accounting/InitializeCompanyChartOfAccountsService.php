<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountTemplate;
use App\Models\Company;
use App\Support\Accounting\SixDigitChartExpander;
use App\Support\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Servicio de inicialización y clonación de Planes Contables por Empresa.
 *
 * Implementa el patrón Matriz -> Tenant:
 * - El Plan Contable General Empresarial (PCGE 2019 o PCGE 2026) reside como catálogo matriz
 *   inmutable en `accounting_account_templates`.
 * - Cada empresa (tenant) obtiene una copia aislada e independiente en `accounting_accounts`
 *   con discriminación estricta por `company_id`.
 * - La clonación se ejecuta de forma descendente (Top-Down, Nivel 1 -> 2 -> 3 -> 4 -> 5 -> 6)
 *   en lotes vectorizados por nivel, mapeando los IDs autogenerados de los padres locales
 *   y respetando las restricciones de integridad jerárquica y RLS.
 */
final readonly class InitializeCompanyChartOfAccountsService
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return array{
     *     company_id: int,
     *     plan_version: string,
     *     total_accounts: int,
     *     initialized_at: string
     * }
     */
    public function initialize(int $companyId, string $planVersion = 'PCGE_2019', bool $force = false): array
    {
        $company = Company::query()->find($companyId);
        if ($company === null) {
            throw new DomainException("La empresa con ID {$companyId} no existe en el sistema.");
        }

        if (! in_array($planVersion, ['PCGE_2019', 'PCGE_2026'], true)) {
            throw new DomainException("La versión del Plan Contable '{$planVersion}' no es válida. Opciones: PCGE_2019, PCGE_2026.");
        }

        // 1. Verificar si existen movimientos o asientos contables asociados a la empresa
        $hasJournalEntries = DB::table('journal_entry_lines')
            ->where('company_id', $companyId)
            ->exists();

        if ($hasJournalEntries) {
            throw new DomainException(
                'No se puede reiniciar ni modificar el Plan Contable matriz de la empresa porque ya existen asientos contables registrados.'
            );
        }

        // 2. Si ya existen cuentas en la empresa y no es forzado, rechazar para evitar duplicidad
        $existingAccountsCount = DB::table('accounting_accounts')
            ->where('company_id', $companyId)
            ->count();

        if ($existingAccountsCount > 0 && ! $force) {
            throw new DomainException(
                "La empresa ya cuenta con {$existingAccountsCount} cuentas contables registradas. Utilice la opción de forzar reinicialización si desea reemplazarlas."
            );
        }

        // 3. Obtener las plantillas oficiales del catálogo matriz
        $templates = AccountingAccountTemplate::query()
            ->where('plan_version', $planVersion)
            ->whereRaw('accounting_account_templates.is_active IS TRUE')
            ->orderBy('level')
            ->orderBy('code')
            ->get();

        if ($templates->isEmpty()) {
            throw new DomainException(
                "No se encontraron plantillas registradas para la versión {$planVersion} en el catálogo matriz."
            );
        }

        // Clonación pura: la matriz global es inmutable y cada empresa recibe
        // sus propias hojas operativas de 6 dígitos, sin colisiones.
        $expandedTemplates = SixDigitChartExpander::expand($templates);

        // 4. Ejecutar la clonación aislada bajo el contexto del Tenant en una transacción
        $totalInserted = $this->tenantContext->run($companyId, function () use ($companyId, $planVersion, $expandedTemplates, $existingAccountsCount): int {
            return DB::transaction(function () use ($companyId, $expandedTemplates, $existingAccountsCount): int {
                // Si existían cuentas previas y estamos en modo forzado (sin movimientos contables),
                // desvincular parámetros de configuración y centros de costo antes de eliminar en cascada ascendente
                if ($existingAccountsCount > 0) {
                    DB::table('company_settings')
                        ->where('company_id', $companyId)
                        ->update([
                            'sales_vat_account_id' => null,
                            'purchase_vat_account_id' => null,
                            'fx_gain_account_id' => null,
                            'fx_loss_account_id' => null,
                            'cost_absorption_account_id' => null,
                            'retained_earnings_account_id' => null,
                        ]);

                    DB::table('cost_centers')
                        ->where('company_id', $companyId)
                        ->delete();

                    for ($lvl = 6; $lvl >= 1; $lvl--) {
                        DB::table('accounting_accounts')
                            ->where('company_id', $companyId)
                            ->where('level', $lvl)
                            ->delete();
                    }
                }

                $groupedByLevel = $expandedTemplates->groupBy('level');
                $codeToIdMap = [];
                $codeToPathMap = [];
                $insertedCount = 0;
                $now = Carbon::now()->toIso8601String();

                for ($level = 1; $level <= 6; $level++) {
                    $levelTemplates = $groupedByLevel->get($level);
                    if ($levelTemplates === null || $levelTemplates->isEmpty()) {
                        continue;
                    }

                    $batchRows = [];
                    foreach ($levelTemplates as $tpl) {
                        $parentId = null;
                        $path = $tpl->code;

                        if ($level > 1) {
                            $parentCode = $tpl->parent_code;
                            if ($parentCode === null || ! isset($codeToIdMap[$parentCode])) {
                                throw new DomainException(
                                    "Error jerárquico: la cuenta {$tpl->code} (Nivel {$level}) requiere a su cuenta padre {$parentCode}, pero no fue encontrada en la empresa."
                                );
                            }

                            $parentId = $codeToIdMap[$parentCode];
                            $path = $codeToPathMap[$parentCode] . '.' . $tpl->code;
                        }

                        $batchRows[] = [
                            'uuid' => (string) Str::uuid(),
                            'company_id' => $companyId,
                            'parent_id' => $parentId,
                            'code' => $tpl->code,
                            'name' => $tpl->name,
                            'level' => $tpl->level,
                            'path' => $path,
                            'account_type' => $tpl->account_type->value,
                            'balance_type' => $tpl->balance_type->value,
                            'currency_mode' => $tpl->currency_mode->value,
                            'requires_currency' => $tpl->currency_mode->requiresForeignExchange(),
                            'requires_auxiliary' => $tpl->entity_requirement_type->requiresEntity(),
                            'entity_requirement_type' => $tpl->entity_requirement_type->value,
                            'requires_cost_center' => $tpl->requires_cost_center,
                            'has_automatic_destination' => $tpl->has_automatic_destination,
                            'destination_debit_code' => $tpl->destination_debit_code,
                            'destination_credit_code' => $tpl->destination_credit_code,
                            'requires_cash_flow_area' => $tpl->requires_cash_flow_area,
                            'cash_flow_category' => $tpl->cash_flow_category->value,
                            'financial_statement_rubric' => $tpl->financial_statement_rubric->value,
                            'is_operational' => $tpl->is_operational && strlen((string) $tpl->code) === 6,
                            'is_active' => $tpl->is_active,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    // Inserción en bloques con RETURNING id, code, path para resolver los padres de niveles subsecuentes
                    $chunks = array_chunk($batchRows, 200);
                    foreach ($chunks as $chunk) {
                        $columns = array_keys($chunk[0]);
                        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $columns));

                        $paramIndex = 1;
                        $valueGroups = [];
                        $bindings = [];

                        foreach ($chunk as $row) {
                            $rowPlaceholders = [];
                            foreach ($columns as $col) {
                                $rowPlaceholders[] = '?';
                                $val = $row[$col];
                                if (is_bool($val)) {
                                    $val = $val ? 'true' : 'false';
                                }
                                $bindings[] = $val;
                            }
                            $valueGroups[] = '(' . implode(', ', $rowPlaceholders) . ')';
                        }

                        $sql = sprintf(
                            'INSERT INTO public.accounting_accounts (%s) VALUES %s RETURNING id, code, path',
                            $colList,
                            implode(', ', $valueGroups)
                        );

                        /** @var list<object{id: int, code: string, path: string}> $results */
                        $results = DB::select($sql, $bindings);

                        foreach ($results as $res) {
                            $codeToIdMap[$res->code] = (int) $res->id;
                            $codeToPathMap[$res->code] = $res->path;
                        }
                        $insertedCount += count($results);
                    }
                }

                // Las cuentas 94/95 no forman parte del PCGE matriz global.
                // La contabilidad analitica se instala como ampliacion privada
                // del tenant y solo usa movimientos en seis digitos.
                // El propio servicio valida la cuenta 791000.
                app(InitializeCompanyAnalyticAccountsService::class)
                    ->installForCompany($companyId);

                // Re-vincular cuentas operativas por defecto en company_settings
                // Solo referencias operativas de seis dígitos para automatismos.
                $vatAccount = $codeToIdMap['401110'] ?? null;
                $fxGain = $codeToIdMap['776000'] ?? null;
                $fxLoss = $codeToIdMap['676000'] ?? null;
                $costAbs = $codeToIdMap['791000'] ?? null;
                $retEarn = $codeToIdMap['591100'] ?? null;

                DB::table('company_settings')
                    ->where('company_id', $companyId)
                    ->update([
                        'sales_vat_account_id' => $vatAccount,
                        'purchase_vat_account_id' => $vatAccount,
                        'fx_gain_account_id' => $fxGain,
                        'fx_loss_account_id' => $fxLoss,
                        'cost_absorption_account_id' => $costAbs,
                        'retained_earnings_account_id' => $retEarn,
                    ]);

                // Incluir las cuentas analiticas privadas creadas por la misma
                // transaccion en el total informado al usuario.
                return (int) DB::table('accounting_accounts')
                    ->where('company_id', $companyId)->count();
            });
        });

        return [
            'company_id' => $companyId,
            'plan_version' => $planVersion,
            'total_accounts' => $totalInserted,
            'initialized_at' => Carbon::now()->toIso8601String(),
        ];
    }
}
