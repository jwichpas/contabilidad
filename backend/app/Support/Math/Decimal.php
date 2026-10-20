<?php

declare(strict_types=1);

namespace App\Support\Math;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Value Object inmutable basado exclusivamente en BCMath para aritmética financiera sin punto flotante.
 *
 * Escalas estandarizadas del sistema:
 * - MONETARY_SCALE = 4 (`NUMERIC(14,4)`): Evita pérdidas por redondeo en asientos bimonetarios y prorrateos.
 * - EXCHANGE_RATE_SCALE = 6 (`NUMERIC(14,6)`): Estándar bancario para tipos de cambio compra/venta.
 */
final readonly class Decimal implements JsonSerializable, Stringable
{
    public const MONETARY_SCALE = 4;

    public const EXCHANGE_RATE_SCALE = 6;

    public const INTERNAL_CALCULATION_SCALE = 8;

    private string $value;

    private function __construct(
        string $normalizedValue,
        private int $scale,
    ) {
        $this->value = $normalizedValue;
    }

    /**
     * Crea una instancia Decimal con escala explícita o por defecto monetaria (4 decimales).
     */
    public static function of(string|int|self $amount, int $scale = self::MONETARY_SCALE): self
    {
        return self::from($amount, $scale);
    }

    /**
     * Crea una instancia para montos monetarios con escala 4 (`0.0000`).
     */
    public static function ofMonetary(string|int|self $amount): self
    {
        return self::from($amount, self::MONETARY_SCALE);
    }

    /**
     * Crea una instancia para tasas de cambio con escala 6 (`0.000000`).
     */
    public static function ofExchangeRate(string|int|self $rate): self
    {
        return self::from($rate, self::EXCHANGE_RATE_SCALE);
    }

    public static function zero(int $scale = self::MONETARY_SCALE): self
    {
        return self::from('0', $scale);
    }

    /**
     * Construye un Decimal validando que no provenga de un `float` y normalizando su escala con BCMath.
     */
    public static function from(string|int|self $input, int $scale = self::MONETARY_SCALE): self
    {
        if ($scale < 0 || $scale > 12) {
            throw new InvalidArgumentException(sprintf('Escala decimal inválida [%d].', $scale));
        }

        $raw = $input instanceof self ? $input->value : (string) $input;
        $trimmed = trim($raw);

        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException(sprintf(
                'El valor [%s] no es una representación decimal exacta válida.',
                $raw
            ));
        }

        $normalized = bcadd($trimmed, '0', $scale);

        // Evita "-0.0000"
        if (bccomp($normalized, '0', $scale) === 0) {
            $normalized = bcadd('0', '0', $scale);
        }

        return new self($normalized, $scale);
    }

    public function add(self|string|int $other): self
    {
        $operand = self::from($other, $this->scale);

        return new self(
            bcadd($this->value, $operand->value, $this->scale),
            $this->scale
        );
    }

    public function sub(self|string|int $other): self
    {
        $operand = self::from($other, $this->scale);

        return new self(
            bcsub($this->value, $operand->value, $this->scale),
            $this->scale
        );
    }

    /**
     * Multiplica con precisión intermedia extendida y redondeo contable Half-Up al corte de escala.
     */
    public function multiply(self|string|int $factor, int $targetScale = self::MONETARY_SCALE): self
    {
        $factorDecimal = $factor instanceof self
            ? $factor
            : self::from($factor, self::INTERNAL_CALCULATION_SCALE);

        $rawProduct = bcmul($this->value, $factorDecimal->value, self::INTERNAL_CALCULATION_SCALE);

        return self::roundHalfUp($rawProduct, $targetScale);
    }

    /**
     * Divide con precisión intermedia extendida y redondeo contable Half-Up.
     */
    public function divide(self|string|int $divisor, int $targetScale = self::MONETARY_SCALE): self
    {
        $divisorDecimal = $divisor instanceof self
            ? $divisor
            : self::from($divisor, self::INTERNAL_CALCULATION_SCALE);

        if ($divisorDecimal->isZero()) {
            throw new InvalidArgumentException('División por cero no permitida en cálculo contable.');
        }

        $rawQuotient = bcdiv($this->value, $divisorDecimal->value, self::INTERNAL_CALCULATION_SCALE);

        return self::roundHalfUp($rawQuotient, $targetScale);
    }

    public function equals(self|string|int $other): bool
    {
        $operand = self::from($other, $this->scale);

        return bccomp($this->value, $operand->value, $this->scale) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', $this->scale) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', $this->scale) === 1;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', $this->scale) === -1;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Redondeo simétrico comercial (Half-Up) implementado puramente en BCMath.
     */
    private static function roundHalfUp(string $number, int $scale): self
    {
        $increment = '0.' . str_repeat('0', $scale) . '5';

        $adjusted = str_starts_with($number, '-')
            ? bcsub($number, $increment, $scale + 1)
            : bcadd($number, $increment, $scale + 1);

        return self::from(bcadd($adjusted, '0', $scale), $scale);
    }
}
