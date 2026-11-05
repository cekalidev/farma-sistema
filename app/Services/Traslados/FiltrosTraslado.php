<?php

declare(strict_types=1);

namespace App\Services\Traslados;

use App\Enums\TrasladoEstado;

/** Filtros de GET /api/v1/traslados (combinables). `bodegaId` = origen O destino. */
final readonly class FiltrosTraslado
{
    public function __construct(
        public ?TrasladoEstado $estado,
        public ?int $bodegaOrigenId,
        public ?int $bodegaDestinoId,
        public ?int $bodegaId,
        public int $porPagina,
    ) {}
}
