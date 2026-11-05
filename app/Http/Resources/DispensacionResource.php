<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Dispensacion;
use App\Models\DispensacionDetalle;
use App\Models\DispensacionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dispensación (RN-02): cabecera, ítems solicitados y tramos FEFO por lote (`detalles`, en orden de
 * descuento), cada uno con su movimiento SALIDA_DISPENSACION. Del paciente solo el id (RN-10).
 *
 * @mixin Dispensacion
 */
class DispensacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estado' => $this->estado->value,
            'paciente_id' => $this->paciente_id,
            'prescripcion_id' => $this->prescripcion_id,
            'bodega_id' => $this->bodega_id,
            'dispensado_por_id' => $this->dispensado_por_id,
            'autorizado_por_id' => $this->autorizado_por_id,
            'autorizado_en' => $this->autorizado_en?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn (DispensacionItem $item): array => [
                'id' => $item->id,
                'prescripcion_item_id' => $item->prescripcion_item_id,
                'producto_id' => $item->producto_id,
                'producto' => $item->relationLoaded('producto') ? [
                    'id' => $item->producto->id,
                    'codigo' => $item->producto->codigo,
                    'nombre' => $item->producto->nombre,
                    'control_especial' => $item->producto->control_especial,
                ] : null,
                'cantidad' => $item->cantidad,
            ])->all()),
            'detalles' => $this->whenLoaded('detalles', fn (): array => $this->detalles->map(fn (DispensacionDetalle $detalle): array => [
                'id' => $detalle->id,
                'dispensacion_item_id' => $detalle->dispensacion_item_id,
                'prescripcion_item_id' => $detalle->relationLoaded('item') ? $detalle->item->prescripcion_item_id : null,
                'existencia_id' => $detalle->existencia_id,
                'producto_id' => $detalle->producto_id,
                'lote_id' => $detalle->lote_id,
                'lote' => $detalle->relationLoaded('lote') ? [
                    'id' => $detalle->lote->id,
                    'codigo' => $detalle->lote->codigo,
                    'fecha_vencimiento' => $detalle->lote->fecha_vencimiento->toDateString(),
                ] : null,
                'cantidad' => $detalle->cantidad,
                'movimiento' => $detalle->relationLoaded('movimiento')
                    ? (new MovimientoKardexResource($detalle->movimiento))->toArray($request)
                    : null,
            ])->all()),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
