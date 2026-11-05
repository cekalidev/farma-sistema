<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\StockMinimo;

/** Una fila de GET /alertas/stock-minimo (S-15): `actual` = stock de lotes no vencidos, siempre < mínimo. */
final readonly class AlertaStockMinimo
{
    public function __construct(
        public StockMinimo $stockMinimo,
        public int $actual,
    ) {}

    public function faltante(): int
    {
        return $this->stockMinimo->cantidad_minima - $this->actual;
    }
}
