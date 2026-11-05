<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\TipoAlertaVencimiento;

/** Filtros de GET /api/v1/alertas/vencimientos (RN-11-AC4); null = sin filtrar. */
final readonly class FiltrosAlertaVencimiento
{
    public function __construct(
        public ?int $bodegaId = null,
        public ?int $productoId = null,
        public ?TipoAlertaVencimiento $tipo = null,
        public int $porPagina = 25,
    ) {}
}
