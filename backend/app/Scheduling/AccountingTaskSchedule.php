<?php

declare(strict_types=1);

namespace App\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Un único punto de extensión para tareas del dominio contable.
 * Los próximos procesos se registran en clases de Scheduling por dominio.
 * No crea crons del sistema operativo ni activa jobs sin consentimiento.
 */
final readonly class AccountingTaskSchedule
{
    public function register(Schedule $schedule): void
    {
        if (! config('accounting_jobs.sunat_fx.enabled', false)
            || trim((string) config('accounting_jobs.sunat_fx.company_ids', '')) === '') {
            return;
        }

        // SUNAT puede publicar tarde: segundo intento el mismo día.
        // El comando es idempotente, no modifica cotizaciones preexistentes.
        $schedule->command('accounting:sync-sunat-fx --scheduled')
            ->twiceDailyAt(10, 16, 30)
            ->timezone('America/Lima')
            ->withoutOverlapping(10)
            ->description('Contabilidad: importar USD/PEN SUNAT a empresas autorizadas');
    }
}
