<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Inventario\AlertaStockMinimo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RN-11-AC5/AC6 (S-15): producto por debajo del stock mínimo de la bodega. `actual` solo cuenta
 * lotes no vencidos; `faltante` = minimo − actual (siempre > 0).
 *
 * @property AlertaStockMinimo $resource
 */
class AlertaStockMinimoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $minimo = $this->resource->stockMinimo;

        return [
            'stock_minimo_id' => $minimo->id,
            'bodega' => [
                'id' => $minimo->bodega->id,
                'codigo' => $minimo->bodega->codigo,
                'nombre' => $minimo->bodega->nombre,
            ],
            'producto' => [
                'id' => $minimo->producto->id,
                'codigo' => $minimo->producto->codigo,
                'nombre' => $minimo->producto->nombre,
            ],
            'minimo' => $minimo->cantidad_minima,
            'actual' => $this->resource->actual,
            'faltante' => $this->resource->faltante(),
        ];
    }
}
