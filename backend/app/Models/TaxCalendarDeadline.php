<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para las fechas de vencimiento tributario SUNAT (Libros Electrónicos/SIRE y Declaraciones).
 *
 * @property int $id
 * @property int $fiscal_year
 * @property int $fiscal_month
 * @property string $last_digit_ruc
 * @property string $electronic_books_deadline
 * @property string $tax_returns_deadline
 * @property string|null $legal_resolution
 */
class TaxCalendarDeadline extends Model
{
    protected $table = 'tax_calendar_deadlines';

    protected $fillable = [
        'fiscal_year',
        'fiscal_month',
        'last_digit_ruc',
        'electronic_books_deadline',
        'tax_returns_deadline',
        'legal_resolution',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'fiscal_month' => 'integer',
            'electronic_books_deadline' => 'date',
            'tax_returns_deadline' => 'date',
        ];
    }
}
