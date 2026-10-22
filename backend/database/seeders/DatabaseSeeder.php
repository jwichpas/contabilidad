<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Proteccion fail-closed.
 *
 * Nunca crear usuarios con contrasenas predecibles, nuevas empresas ficticias
 * ni PCGE arbitrario al ejecutar php artisan db:seed en una base nueva.
 *
 * La instalacion de referencias debe ser versionada, verificable, obligatoria
 * y parte del instalador Nexus, no un efecto secundario de db:seed.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        throw new \RuntimeException(
            'db:seed general deshabilitado por seguridad. '
            .'Para Neon contabilidad-dev use '
            .'php scripts/phase2-neon-global-reference.php --plan / --verify / --apply=contabilidad-dev. '
            .'No se crearon empresas, usuarios ni datos.'
        );
    }
}
