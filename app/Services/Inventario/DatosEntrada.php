<?php

declare(strict_types=1);

namespace App\Services\Inventario;

/**
 * Entrada de inventario ya validada (RN-01-AC8). El lote llega de una de dos formas:
 *  - loteId: un lote ya registrado del producto;
 *  - loteCodigo + fechaVencimiento: el lote de la compra; se crea si no existe para el producto.
 */
final readonly class DatosEntrada
{
    public function __construct(
        public int $usuarioId,
        public int $bodegaId,
        public int $productoId,
        public int $cantidad,
        public ?int $loteId = null,
        public ?string $loteCodigo = null,
        public ?string $fechaVencimiento = null,
    ) {}
}
