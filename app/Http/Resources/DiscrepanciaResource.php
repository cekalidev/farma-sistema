<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Discrepancia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Discrepancia de traslado (RN-07-AC6/AC12). `traslado_id`, `producto_id` y `lote_id` salen del
 * ítem si está cargado.
 *
 * @mixin Discrepancia
 */
class DiscrepanciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->relationLoaded('item') ? $this->item : null;

        return [
            'id' => $this->id,
            'traslado_item_id' => $this->traslado_item_id,
            'traslado_id' => $item?->traslado_id,
            'producto_id' => $item?->producto_id,
            'lote_id' => $item?->lote_id,
            'cantidad' => $this->cantidad,
            'estado' => $this->estado->value,
            'tipo_resolucion' => $this->tipo_resolucion?->value,
            'motivo' => $this->motivo,
            'resuelto_por_id' => $this->resuelto_por_id,
            'resuelto_en' => $this->resuelto_en?->toIso8601String(),
            'movimiento_kardex_id' => $this->movimiento_kardex_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
