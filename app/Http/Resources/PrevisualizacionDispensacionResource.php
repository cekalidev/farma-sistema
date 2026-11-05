<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Dispensacion\Previsualizacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RN-02-AC6: asignación FEFO por lote que haría la dispensación (`detalles`, en orden de consumo). `orientativa: true` recuerda que
 * se calculó sin bloquear: el stock puede cambiar antes de dispensar.
 *
 * @property Previsualizacion $resource
 */
class PrevisualizacionDispensacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detalles = [];
        foreach ($this->resource->items as $item) {
            foreach ($item['tramos'] as $tramo) {
                $existencia = $tramo['existencia'];
                $detalles[] = [
                    'prescripcion_item_id' => $item['item']->id,
                    'existencia_id' => $existencia->id,
                    'producto_id' => $existencia->producto_id,
                    'lote_id' => $existencia->lote_id,
                    'lote' => [
                        'id' => $existencia->lote->id,
                        'codigo' => $existencia->lote->codigo,
                        'fecha_vencimiento' => $existencia->lote->fecha_vencimiento->toDateString(),
                    ],
                    'cantidad' => $tramo['cantidad'],
                ];
            }
        }

        return [
            'bodega_id' => $this->resource->bodegaId,
            'orientativa' => true,
            'items' => array_map(static fn (array $item): array => [
                'prescripcion_item_id' => $item['item']->id,
                'producto_id' => $item['item']->producto_id,
                'producto' => [
                    'id' => $item['item']->producto->id,
                    'codigo' => $item['item']->producto->codigo,
                    'nombre' => $item['item']->producto->nombre,
                    'control_especial' => $item['item']->producto->control_especial,
                ],
                'cantidad' => $item['cantidad'],
            ], $this->resource->items),
            // Mismo formato que `detalles` de DispensacionResource (sin id ni movimiento: nada se escribe).
            'detalles' => $detalles,
        ];
    }
}
