<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

/** Solicitud de dispensación ya validada en forma (Form Request); las reglas de negocio van en el servicio. */
final readonly class DatosDispensacion
{
    /**
     * @param  list<ItemSolicitado>  $items  ítems prescritos distintos, en el orden del cliente
     */
    public function __construct(
        public int $usuarioId,
        public int $pacienteId,
        public int $prescripcionId,
        public int $bodegaId,
        public array $items,
    ) {}
}
