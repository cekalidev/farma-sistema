<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Enums\EstadoDispensacion;

/** Filtros de GET /api/v1/dispensaciones (combinables, S-89). */
final readonly class FiltrosDispensacion
{
    public function __construct(
        public ?EstadoDispensacion $estado,
        public ?int $bodegaId,
        public int $porPagina,
    ) {}
}
