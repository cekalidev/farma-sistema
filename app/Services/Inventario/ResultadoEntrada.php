<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Existencia;

/** Resultado de una entrada: la existencia actualizada (con su lote), los saldos y el movimiento de kardex. */
final readonly class ResultadoEntrada
{
    public function __construct(
        public Existencia $existencia,
        public int $cantidad,
        public int $saldoAnterior,
        public int $saldoPosterior,
        public bool $loteCreado,
        public int $movimientoId,
    ) {}
}
