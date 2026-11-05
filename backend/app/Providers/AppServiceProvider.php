<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

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

        // Registered ONCE per application bootstrap, not once per HTTP
        // request. This avoids leaking event listeners in long-lived workers.
        // The request attribute confines collection to the Sanctum stage
        // of journal editor bootstrap; no SQL or bindings are retained.
        DB::listen(static function (QueryExecuted $query): void {
            if (! app()->bound('request')) {
                return;
            }

            $request = app('request');
            if ($request->attributes->get('journal_auth_sql_active') !== true) {
                return;
            }

            $elapsed = max(0.0, (float) ($query->time ?? 0.0));
            $count = (int) $request->attributes->get('journal_auth_sql_count', 0);
            $request->attributes->set('journal_auth_sql_count', $count + 1);
            $request->attributes->set(
                'journal_auth_sql_total_ms',
                (float) $request->attributes->get('journal_auth_sql_total_ms', 0.0) + $elapsed
            );
            if ($count === 0) {
                $request->attributes->set('journal_auth_sql_first_ms', $elapsed);
            }
            $request->attributes->set(
                'journal_auth_sql_max_ms',
                max((float) $request->attributes->get('journal_auth_sql_max_ms', 0.0), $elapsed)
            );

            // Classify only to detect whether Sanctum's token lookup or the
            // user hydration query dominates. Never expose the SQL text.
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'personal_access_tokens')) {
                $request->attributes->set(
                    'journal_auth_sql_token_ms',
                    (float) $request->attributes->get('journal_auth_sql_token_ms', 0.0) + $elapsed
                );
            } elseif (str_contains($sql, 'from "users"') || str_contains($sql, 'from users')) {
                $request->attributes->set(
                    'journal_auth_sql_user_ms',
                    (float) $request->attributes->get('journal_auth_sql_user_ms', 0.0) + $elapsed
                );
            }
        });
    }
}
