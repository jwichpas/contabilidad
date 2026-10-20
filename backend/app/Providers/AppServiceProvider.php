<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, static fn (): TenantContext => new TenantContext());
    }

    public function boot(): void
    {
        // Modo estricto de Eloquent: previene N+1 silenciosos, atributos descartados en fill()
        // y acceso a propiedades no hidratadas desde la base de datos.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
