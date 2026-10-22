<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Models\AccountingAccountTemplate;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Expands the immutable PCGE matrix into private six-digit tenant posting
 * leaves. Original 3/4/5-digit terminal nodes remain in the hierarchy but
 * never accept movements; their new six-digit child does.
 *
 * This class does not modify source templates or write to the database.
 */
final class SixDigitChartExpander
{
    /**
     * @param Collection<int, AccountingAccountTemplate> $templates
     * @return Collection<int, AccountingAccountTemplate>
     */
    public static function expand(Collection $templates): Collection
    {
        $existing = [];
        foreach ($templates as $tpl) {
            if (isset($existing[(string) $tpl->code])) {
                throw new DomainException('Cuenta duplicada en plantilla PCGE: '.$tpl->code);
            }
            $existing[(string) $tpl->code] = true;
        }

        $aliases = [];
        foreach ($templates as $tpl) {
            if (! $tpl->is_operational) {
                continue;
            }
            $originalCode = (string) $tpl->code;
            $length = strlen($originalCode);
            if ($length === 6) {
                continue;
            }
            if ($length < 3 || $length > 6 || (int) $tpl->level >= 6
                || ! preg_match('/^[0-9]+$/D', $originalCode)) {
                throw new DomainException('Hoja PCGE inválida para seis dígitos: '.$originalCode);
            }

            $code = str_pad($originalCode, 6, '0', STR_PAD_RIGHT);
            if (isset($existing[$code]) || isset($aliases[$code])) {
                throw new DomainException('Colisión de cuenta operativa de seis dígitos: '.$code);
            }

            $child = clone $tpl;
            $child->code = $code;
            $child->name = mb_substr((string) $tpl->name.' - Movimiento', 0, 180);
            $child->level = ((int) $tpl->level) + 1;
            $child->parent_code = $originalCode;
            $child->is_operational = true;
            $aliases[$code] = $child;
        }

        // The original source collection remains untouched. Expanded terminal
        // nodes below 6 digits are non-postable structural parents.
        $canonical = $templates->map(static function (AccountingAccountTemplate $tpl): AccountingAccountTemplate {
            if ($tpl->is_operational && strlen((string) $tpl->code) < 6) {
                $parent = clone $tpl;
                $parent->is_operational = false;
                return $parent;
            }
            return $tpl;
        });

        return $canonical->concat(array_values($aliases));
    }
}
