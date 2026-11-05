<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/** Para enums respaldados por string: sus valores, en el orden de declaración (p. ej. para Rule::in). */
trait ConValores
{
    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}
