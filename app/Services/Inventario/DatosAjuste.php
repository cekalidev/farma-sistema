<?php

declare(strict_types=1);

namespace App\Services\Inventario;

/** Ajuste de inventario ya validado (RN-06-AC6): cantidad con signo (≠ 0) y motivo. */
final readonly class DatosAjuste
{
    public function __construct(
        public int $usuarioId,
        public int $existenciaId,
        public int $cantidad,
        public string $motivo,
    ) {}
}
