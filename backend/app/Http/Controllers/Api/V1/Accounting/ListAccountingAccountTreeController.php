<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Resources\Api\V1\Accounting\AccountingAccountResource;
use App\Models\AccountingAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Single Action Controller que devuelve el árbol completo del Plan de Cuentas
 * en una única consulta a PostgreSQL (evitando N+1 mediante reconstrucción en memoria en O(n)).
 */
final readonly class ListAccountingAccountTreeController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $onlyActive = $request->boolean('only_active', false);
        $prefix = $request->query('code_prefix');

        $query = AccountingAccount::query()->orderBy('code');

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        if (is_string($prefix) && $prefix !== '') {
            $query->whereCodePrefix($prefix);
        }

        /** @var Collection<int, AccountingAccount> $allAccounts */
        $allAccounts = $query->get();

        $treeRoots = $this->buildTreeInMemory($allAccounts);

        return AccountingAccountResource::collection($treeRoots);
    }

    /**
     * Ensambla la jerarquía de hasta 4 niveles en memoria en tiempo lineal O(n)
     * utilizando el resultado de una sola consulta indexada a la base de datos.
     *
     * @param  Collection<int, AccountingAccount>  $flatAccounts
     * @return Collection<int, AccountingAccount>
     */
    private function buildTreeInMemory(Collection $flatAccounts): Collection
    {
        /** @var array<int, AccountingAccount> $indexedById */
        $indexedById = [];
        /** @var array<int, array<int, AccountingAccount>> $childrenByParentId */
        $childrenByParentId = [];

        foreach ($flatAccounts as $account) {
            $indexedById[$account->id] = $account;
            $childrenByParentId[$account->id] = [];
        }

        $roots = new Collection();

        foreach ($flatAccounts as $account) {
            $parentId = $account->parent_id;

            if ($parentId !== null && isset($indexedById[$parentId])) {
                $childrenByParentId[$parentId][] = $account;
            } else {
                $roots->push($account);
            }
        }

        foreach ($flatAccounts as $account) {
            $account->setRelation(
                'childrenRecursive',
                new Collection($childrenByParentId[$account->id])
            );
        }

        return $roots;
    }
}
