<?php

declare(strict_types=1);

namespace App\Support\Tenant;

use App\Exceptions\MissingTenantContextException;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de estado de tenant aislado por ciclo de vida de petición (Octane-safe via `scoped`).
 * Coordina el ID de la empresa activa tanto para Eloquent (`TenantScope`) como para PostgreSQL RLS (`set_config`).
 */
final class TenantContext
{
    private ?int $companyId = null;

    /**
     * Indica si el bypass explícito está habilitado para procesos administrativos de consola/seeders.
     */
    private bool $bypassed = false;

    public function setCompanyId(?int $companyId): void
    {
        if ($companyId !== null && $companyId <= 0) {
            throw new \InvalidArgumentException('El identificador de empresa debe ser un entero positivo.');
        }

        $this->companyId = $companyId;
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function requireCompanyId(string $modelClass = 'Model'): int
    {
        if ($this->companyId === null) {
            throw MissingTenantContextException::forModel($modelClass);
        }

        return $this->companyId;
    }

    public function hasCompany(): bool
    {
        return $this->companyId !== null;
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    public function clear(): void
    {
        $this->companyId = null;
        $this->bypassed = false;
    }

    /**
     * Ejecuta un callback dentro de una transacción de base de datos fijando el contexto
     * tanto en PHP como en PostgreSQL (`SET LOCAL app.current_company_id`).
     * Ideal para comandos Artisan, Jobs en cola y Seeders multi-tenant.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(int $companyId, callable $callback): mixed
    {
        $previousCompanyId = $this->companyId;
        $previousBypass = $this->bypassed;

        $this->setCompanyId($companyId);
        $this->bypassed = false;

        try {
            return DB::transaction(function () use ($companyId, $callback): mixed {
                DB::select("SELECT set_config('app.current_company_id', ?, true)", [(string) $companyId]);

                return $callback();
            });
        } finally {
            $this->companyId = $previousCompanyId;
            $this->bypassed = $previousBypass;
        }
    }

    /**
     * Permite ejecutar operaciones administrativas en consola sin filtro de `TenantScope` en Eloquent.
     * Nota: A nivel de BD, RLS sigue activo para `app_backend` salvo que se use la conexión administrativa.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runBypassing(callable $callback): mixed
    {
        $previousBypass = $this->bypassed;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previousBypass;
        }
    }
}
