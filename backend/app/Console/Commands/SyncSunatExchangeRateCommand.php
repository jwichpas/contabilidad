<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ExchangeRates\SunatExchangeRateSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CLI para importación controlada. La programación usa la allowlist del entorno:
 * el usuario app_backend no puede enumerar compañías globalmente bajo RLS.
 */
final class SyncSunatExchangeRateCommand extends Command
{
    protected $signature = 'accounting:sync-sunat-fx
        {--company=* : Identificadores de empresa para ejecución manual}
        {--date= : Fecha del documento YYYY-MM-DD (solo consulta del valor publicado)}
        {--scheduled : Utilizar únicamente las empresas declaradas para el scheduler}';

    protected $description = 'Importa USD/PEN exacto desde SUNAT para empresas autorizadas sin sobrescribir cotizaciones existentes.';

    public function handle(SunatExchangeRateSynchronizer $synchronizer): int
    {
        // Nunca ejecutar la sincronización programada como neondb_owner ni bypass de RLS.
        if (DB::connection()->getConfig('username') !== 'app_backend') {
            $this->error('Este comando debe usar la conexión restringida app_backend, no la conexión de migraciones.');
            return self::FAILURE;
        }

        $scheduled = (bool) $this->option('scheduled');
        if ($scheduled && $this->option('company') !== []) {
            $this->error('No combine --scheduled con --company.');
            return self::INVALID;
        }

        $rawCompanyIds = $scheduled
            ? explode(',', (string) config('accounting_jobs.sunat_fx.company_ids', ''))
            : (array) $this->option('company');
        $companyIds = [];
        foreach ($rawCompanyIds as $rawId) {
            $trimmed = trim((string) $rawId);
            if ($trimmed === '') {
                continue;
            }
            if (! ctype_digit($trimmed) || (int) $trimmed < 1) {
                $this->error('La lista de empresas contiene un identificador no válido.');
                return self::INVALID;
            }
            $companyIds[(int) $trimmed] = (int) $trimmed;
        }
        if ($companyIds === []) {
            $this->error('Indique --company=ID o configure la lista de empresas del scheduler.');
            return self::INVALID;
        }

        $date = (string) ($this->option('date') ?: CarbonImmutable::now('America/Lima')->toDateString());
        $parts = explode('-', $date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || count($parts) !== 3
            || ! checkdate((int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0), (int) ($parts[0] ?? 0))) {
            $this->error('Fecha inválida. Formato requerido YYYY-MM-DD.');
            return self::INVALID;
        }

        $anyFailures = false;
        foreach ($companyIds as $companyId) {
            try {
                $result = $synchronizer->sync($companyId, $date);
                $status = $result['status'];
                $this->line("Empresa {$companyId}: {$status} ({$date})");
                if (! in_array($status, ['imported', 'existing', 'unsupported'], true)) {
                    $anyFailures = true;
                }
            } catch (\Throwable $exception) {
                $anyFailures = true;
                $this->error("Empresa {$companyId}: fallo seguro (".$exception::class.').');
            }
        }
        return $anyFailures ? self::FAILURE : self::SUCCESS;
    }
}
