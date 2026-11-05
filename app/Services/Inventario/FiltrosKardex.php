<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Carbon\CarbonImmutable;

/**
 * Filtros combinables de GET /api/v1/kardex (RN-06-AC8); null = sin filtrar.
 * `desde` (inclusive) y `hastaExclusivo` ya son instantes en la zona de la aplicación.
 */
final readonly class FiltrosKardex
{
    public function __construct(
        public ?int $productoId = null,
        public ?int $loteId = null,
        public ?int $bodegaId = null,
        public ?int $existenciaId = null,
        public ?CarbonImmutable $desde = null,
        public ?CarbonImmutable $hastaExclusivo = null,
        public int $porPagina = 25,
    ) {}
}
