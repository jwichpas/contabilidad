<?php

declare(strict_types=1);

namespace App\Support\Types;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Tipos numéricos enteros de precisión fija e identificadores de dominio fuertemente tipados al estilo C# (`struct`).
 * Impide en tiempo de ejecución y análisis estático mezclar `Int64`/`AccountId`/`CompanyId` o recibir flotantes.
 */
abstract readonly class StronglyTypedLongId implements JsonSerializable, Stringable
{
    public int $value;

    final public function __construct(int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                '[%s] requiere un entero Int64 positivo (> 0), se recibió [%d].',
                static::class,
                $value
            ));
        }

        $this->value = $value;
    }

    public static function fromInt(int $value): static
    {
        return new static($value);
    }

    public static function fromNumericString(string $value): static
    {
        return static::parse($value);
    }

    public static function parse(int|string $raw): static
    {
        if (is_int($raw)) {
            return new static($raw);
        }

        $trimmed = trim($raw);
        if (! preg_match('/^[1-9]\d*$/', $trimmed)) {
            throw new InvalidArgumentException(sprintf(
                'No se pudo convertir [%s] en un identificador entero válido para [%s].',
                $raw,
                static::class
            ));
        }

        return new static((int) $trimmed);
    }

    public function value(): int
    {
        return $this->value;
    }

    public static function tryParse(mixed $raw): ?static
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            if (is_int($raw) || is_string($raw)) {
                return static::parse($raw);
            }

            return null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
