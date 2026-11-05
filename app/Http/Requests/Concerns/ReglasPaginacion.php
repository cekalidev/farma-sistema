<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Paginación común de todos los listados (S-25, S-28, S-88): `por_pagina` 1–100 (25 por defecto)
 * y `page` ≤ 1.000.000. Para Form Requests (usa `validated()`).
 */
trait ReglasPaginacion
{
    public const POR_PAGINA_DEFECTO = 25;

    public const POR_PAGINA_MAXIMO = 100;

    /** Evita offsets absurdos (page * por_pagina) que PostgreSQL tendría que recorrer o rechazar. */
    public const PAGINA_MAXIMA = 1_000_000;

    /**
     * `numeric` además de `integer`: FILTER_VALIDATE_INT sola aceptaría booleanos (true → 1).
     *
     * @return array{por_pagina: list<string>, page: list<string>}
     */
    protected function reglasPaginacion(): array
    {
        return [
            'por_pagina' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.self::POR_PAGINA_MAXIMO],
            'page' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.self::PAGINA_MAXIMA],
        ];
    }

    public function porPagina(): int
    {
        /** @var array{por_pagina?: int|string} $validados */
        $validados = $this->validated();

        return (int) ($validados['por_pagina'] ?? self::POR_PAGINA_DEFECTO);
    }
}
