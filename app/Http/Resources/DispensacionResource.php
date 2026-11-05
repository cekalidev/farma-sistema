<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Dispensacion;
use App\Models\DispensacionDetalle;
use App\Models\DispensacionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dispensación (RN-02; RN-05: estado y datos de autorización o rechazo): cabecera, ítems solicitados y tramos FEFO por lote (`detalles`, en orden de
 * descuento), cada uno con su movimiento SALIDA_DISPENSACION. Del paciente, el id; y en el detalle
 * (GET /dispensaciones/{id}) el paciente anidado vía PacienteResource (RN-10-AC4: enmascarado para el
 * auditor). Crear, autorizar y rechazar no lo cargan, así que no lo devuelven.
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
            'paciente' => $this->whenLoaded('paciente', fn (): PacienteResource => new PacienteResource($this->paciente)),
            'prescripcion_id' => $this->prescripcion_id,
            'bodega_id' => $this->bodega_id,
            'dispensado_por_id' => $this->dispensado_por_id,
            'autorizado_por_id' => $this->autorizado_por_id,
            'autorizado_en' => $this->autorizado_en?->toIso8601String(),
            'rechazado_por_id' => $this->rechazado_por_id,
            'rechazado_en' => $this->rechazado_en?->toIso8601String(),
            'motivo_rechazo' => $this->motivo_rechazo,
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
