<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * Initial internal journal classification. These are ERP subdiaries, not SUNAT
 * codes or electronic-ledger identifiers; tenant-specific masters can follow.
 */
final class JournalSubdiaryCatalog
{
    public const DEFAULT = 'GENERAL';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'GENERAL' => 'Diario general',
            'COMPRAS' => 'Compras',
            'VENTAS' => 'Ventas',
            'CAJA' => 'Caja',
            'BANCOS' => 'Bancos',
            'PLANILLAS' => 'Planillas',
            'AJUSTES' => 'Ajustes',
            'APERTURA_CIERRE' => 'Apertura y cierre',
        ];
    }

    /** @return list<array{code: string, name: string}> */
    public static function options(): array
    {
        $result = [];
        foreach (self::labels() as $code => $name) {
            $result[] = ['code' => $code, 'name' => $name];
        }
        return $result;
    }
}
