<?php

declare(strict_types=1);

namespace App\Services\Inventario;

/** Filtros de GET /api/v1/alertas/stock-minimo (RN-11-AC5); null = sin filtrar. */
final readonly class FiltrosAlertaStockMinimo
{
    public function __construct(
        public ?int $bodegaId = null,
        public ?int $productoId = null,
        public int $porPagina = 25,
    ) {}
}
