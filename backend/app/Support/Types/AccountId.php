<?php

declare(strict_types=1);

namespace App\Support\Types;

/**
 * Identificador nominal fuertemente tipado `AccountId` (equivalente a `readonly record struct AccountId(long Value)` en C#).
 */
final readonly class AccountId extends StronglyTypedLongId {}
