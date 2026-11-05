<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

/** Ítem pedido en una dispensación: el producto sale del ítem prescrito (RN-04-AC11). */
final readonly class ItemSolicitado
{
    public function __construct(
        public int $prescripcionItemId,
        public int $cantidad,
    ) {}
}
