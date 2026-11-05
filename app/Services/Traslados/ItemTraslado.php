<?php

declare(strict_types=1);

namespace App\Services\Traslados;

/** Ítem pedido en un traslado: producto, lote explícito (S-12) y cantidad (> 0, S-07). */
final readonly class ItemTraslado
{
    public function __construct(
        public int $productoId,
        public int $loteId,
        public int $cantidad,
    ) {}
}
