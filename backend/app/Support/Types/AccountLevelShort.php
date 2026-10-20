<?php

declare(strict_types=1);

namespace App\Support\Types;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Struct inmutable al estilo `System.Int16` (`short` en C#) para niveles jerárquicos del Plan Contable (1..4).
 */
final readonly class AccountLevelShort implements JsonSerializable
{
    public const int MIN_LEVEL = 1;

    public const int MAX_LEVEL = 6;

    public const int MIN_OPERATIONAL_LEVEL = 3;

    public int $value;

    public function __construct(int $value)
    {
        if ($value < self::MIN_LEVEL || $value > self::MAX_LEVEL) {
            throw new InvalidArgumentException(sprintf(
                'AccountLevelShort fuera de rango: se esperaba un smallint entre %d y %d, se recibió [%d].',
                self::MIN_LEVEL,
                self::MAX_LEVEL,
                $value
            ));
        }

        $this->value = $value;
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public static function parse(int|string $raw): self
    {
        if (is_int($raw)) {
            return new self($raw);
        }

        if (! preg_match('/^[1-6]$/', trim((string) $raw))) {
            throw new InvalidArgumentException(sprintf('Nivel contable inválido [%s].', $raw));
        }

        return new self((int) $raw);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function isRoot(): bool
    {
        return $this->value === self::MIN_LEVEL;
    }

    public function isOperationalLevel(): bool
    {
        return $this->value >= self::MIN_OPERATIONAL_LEVEL;
    }

    public function isLeafRegisterLevel(): bool
    {
        return $this->isOperationalLevel();
    }

    public function isImmediateChildOf(self $parentLevel): bool
    {
        return $this->value === ($parentLevel->value + 1);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }
}
