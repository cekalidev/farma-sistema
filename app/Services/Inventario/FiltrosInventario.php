<?php

declare(strict_types=1);

namespace App\Services\Inventario;

/** Filtros combinables de GET /api/v1/inventario (RN-01-AC9); null = sin filtrar. */
final readonly class FiltrosInventario
{
    public function __construct(
        public ?int $bodegaId = null,
        public ?int $productoId = null,
        public ?int $loteId = null,
        public int $porPagina = 25,
    ) {}
}
