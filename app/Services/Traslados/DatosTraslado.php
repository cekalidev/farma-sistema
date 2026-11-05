<?php

declare(strict_types=1);

namespace App\Services\Traslados;

/** Datos validados en forma de POST /api/v1/traslados (RN-07-AC1). */
final readonly class DatosTraslado
{
    /**
     * @param  list<ItemTraslado>  $items
     */
    public function __construct(
        public int $usuarioId,
        public int $bodegaOrigenId,
        public int $bodegaDestinoId,
        public ?string $observaciones,
        public array $items,
    ) {}
}
