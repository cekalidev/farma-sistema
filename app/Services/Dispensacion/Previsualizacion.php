<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Models\Existencia;
use App\Models\PrescripcionItem;

/**
 * RN-02-AC6: asignación FEFO que haría la dispensación, por ítem. Orientativa: se calcula sin
 * bloquear, así que otra operación puede cambiar el stock antes de dispensar.
 */
final readonly class Previsualizacion
{
    /**
     * @param  list<array{item: PrescripcionItem, cantidad: int, tramos: list<array{existencia: Existencia, cantidad: int}>}>  $items
     */
    public function __construct(
        public int $bodegaId,
        public array $items,
    ) {}
}
