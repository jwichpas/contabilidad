<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Local, read-only network diagnostic for the RLS application connection.
 *
 * It must not authenticate as the migration/admin role or print connection
 * strings, hosts, database names, users, passwords or query bindings.
 * This command measures a new PDO connection and two simple SELECTs for
 * each sample. It does not alter PostgreSQL, Sanctum or tenant permissions.
 */
final class MeasurePostgresLatency extends Command
{
    protected $signature = 'contabilidad:medir-latencia-db
        {--muestras=5 : Cantidad de conexiones independientes entre 2 y 10}';

    protected $description = 'Mide conexión PostgreSQL y dos consultas SELECT 1 sin revelar credenciales';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Diagnóstico disponible solo con APP_ENV=local.');

            return self::FAILURE;
        }

        if (config('database.default') !== 'pgsql') {
            $this->error('Se requiere la conexión de aplicación pgsql, nunca la administrativa.');

            return self::FAILURE;
        }

        $sampleValue = $this->option('muestras');
        if (! is_scalar($sampleValue) || ! preg_match('/^(?:[2-9]|10)$/', (string) $sampleValue)) {
            $this->error('Use --muestras=2 hasta --muestras=10.');

            return self::INVALID;
        }

        $count = (int) $sampleValue;
        $measurements = ['connect' => [], 'query1' => [], 'query2' => []];
        $db = DB::connection('pgsql');

        $this->line('Diagnóstico local: solo SELECT 1, sin datos contables ni credenciales.');
        $this->line('Las cifras son de este comando CLI; no equivalen al tiempo del navegador.');

        try {
            for ($index = 1; $index <= $count; $index++) {
                // Cold connection on each sample; there are no other requests
                // in this Artisan process. Do not change app PDO options.
                $db->disconnect();
                $started = hrtime(true);
                $db->getPdo();
                $connectMs = self::elapsedMs($started);

                $started = hrtime(true);
                $first = $db->selectOne('SELECT 1 AS ping');
                $query1Ms = self::elapsedMs($started);

                $started = hrtime(true);
                $second = $db->selectOne('SELECT 1 AS ping');
                $query2Ms = self::elapsedMs($started);

                if ((int) ($first?->ping ?? 0) !== 1 || (int) ($second?->ping ?? 0) !== 1) {
                    throw new \RuntimeException('Respuesta de consulta inesperada.');
                }

                $measurements['connect'][] = $connectMs;
                $measurements['query1'][] = $query1Ms;
                $measurements['query2'][] = $query2Ms;

                $this->line(sprintf(
                    'Muestra %d: conexión %.1f ms | SELECT 1 %.1f ms | SELECT 2 %.1f ms',
                    $index, $connectMs, $query1Ms, $query2Ms
                ));
            }
        } catch (Throwable) {
            // Drivers can include connection strings or internal SQL in an
            // exception; intentionally output neither.
            $this->error('No se completó la medición. Revise la configuración local en privado.');

            return self::FAILURE;
        } finally {
            $db->disconnect();
        }

        $this->info(sprintf(
            'MEDIANAS: conexión %.1f ms | SELECT 1 %.1f ms | SELECT 2 %.1f ms',
            self::median($measurements['connect']),
            self::median($measurements['query1']),
            self::median($measurements['query2'])
        ));
        $this->line('Para comparar, el HTTP anterior reportó auth_sql_first=1237.2 ms y auth_sql_user=299.8 ms.');

        return self::SUCCESS;
    }

    private static function elapsedMs(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values, SORT_NUMERIC);

        $n = count($values);
        $middle = intdiv($n, 2);

        return $n % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
