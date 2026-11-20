<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Illuminate\Validation\ValidationException;

final class PayrollExports
{
    public function plame(array $lines): string
    {
        $out = [];
        foreach ($lines as $row) {
            $regular = (int) $row['attendance']['ordinary_minutes'];
            $extra = (int) $row['attendance']['overtime_25'] + (int) $row['attendance']['overtime_35'];
            if ($regular > 21659 || $extra > 21659) {
                throw ValidationException::withMessages(['export' => 'Las horas superan el máximo de la estructura .jor.']);
            }
            $worker = $row['worker'];
            $out[] = implode('|', ['01', $worker['dni'], intdiv($regular, 60), $regular % 60, intdiv($extra, 60), $extra % 60]).'|';
        }

        return implode("\r\n", $out)."\r\n";
    }

    public function afp(array $lines, string $month): string
    {
        $out = [];
        foreach ($lines as $row) {
            $worker = $row['worker'];
            if ($worker['pension'] !== 'AFP') {
                continue;
            }
            $amount = $row['amounts']['pensionable'];
            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, '9999999.99', 2) > 0) {
                throw ValidationException::withMessages(['export' => 'AFPnet requiere revisar la remuneración asegurable o la excepción de aporte de '.$worker['code'].'.']);
            }
            $out[] = str_pad((string) (count($out) + 1), 5, '0', STR_PAD_LEFT)
                . $this->fixed($worker['cuspp'], 12).'0'.$this->fixed($worker['dni'], 20)
                . $this->fixed($worker['surname'], 20).$this->fixed($worker['second_surname'] ?? '', 20)
                . $this->fixed($worker['given_names'], 20).'S'
                . (str_starts_with($worker['hired_on'], $month) ? 'S' : 'N')
                . (str_starts_with($worker['ended_on'] ?? '', $month) ? 'S' : 'N').' '
                . str_pad(str_replace('.', '', $amount), 9, '0', STR_PAD_LEFT).str_repeat('0', 27)
                . 'N'.$this->fixed($worker['afp'], 2);
        }
        if ($out === []) {
            throw ValidationException::withMessages(['export' => 'No hay trabajadores afiliados al SPP en el periodo.']);
        }

        return implode("\r\n", $out)."\r\n";
    }

    private function fixed(string $value, int $width): string
    {
        $encoded = mb_convert_encoding(mb_strtoupper($value, 'UTF-8'), 'Windows-1252', 'UTF-8');
        if (strlen($encoded) > $width || str_contains($encoded, '?') || preg_match('/[\x00-\x1f\x7f]/', $encoded)) {
            throw ValidationException::withMessages(['export' => 'Revise la longitud o caracteres de los datos de identidad en AFPnet.']);
        }

        return str_pad($encoded, $width);
    }
}
