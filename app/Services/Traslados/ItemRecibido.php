<?php

declare(strict_types=1);

namespace App\Services\Traslados;

/** Cantidad recibida (>= 0) de un ítem en la recepción del traslado (RN-07-AC5..AC7). */
final readonly class ItemRecibido
{
    public function __construct(
        public int $trasladoItemId,
        public int $cantidadRecibida,
    ) {}
}
