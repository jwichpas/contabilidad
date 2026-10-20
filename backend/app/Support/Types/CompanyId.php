<?php

declare(strict_types=1);

namespace App\Support\Types;

/**
 * Identificador nominal fuertemente tipado `CompanyId` (equivalente a `readonly record struct CompanyId(long Value)` en C#).
 */
final readonly class CompanyId extends StronglyTypedLongId {}
