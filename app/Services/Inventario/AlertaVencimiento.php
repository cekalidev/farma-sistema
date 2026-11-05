<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\TipoAlertaVencimiento;
use App\Models\Existencia;

/** Una fila de GET /alertas/vencimientos: la existencia (con lote, bodega y producto) y su clasificación. */
final readonly class AlertaVencimiento
{
    public function __construct(
        public Existencia $existencia,
        public TipoAlertaVencimiento $tipo,
        public int $diasRestantes,
    ) {}
}
