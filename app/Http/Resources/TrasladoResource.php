<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bodega;
use App\Models\Discrepancia;
use App\Models\Traslado;
use App\Models\TrasladoItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Traslado (RN-07): cabecera con usuario y fecha de cada acción, ítems (con su discrepancia) y la
 * lista plana de discrepancias (RN-07-AC13). `observaciones` es texto libre del usuario y se
 * devuelve tal cual (JSON escapa su contenido; quien lo muestre debe tratarlo como texto).
 *
 * @mixin Traslado
 */
class TrasladoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bodega = static fn (Bodega $b): array => ['id' => $b->id, 'codigo' => $b->codigo, 'nombre' => $b->nombre];

        return [
            'id' => $this->id,
            'estado' => $this->estado->value,
            'bodega_origen_id' => $this->bodega_origen_id,
            'bodega_destino_id' => $this->bodega_destino_id,
            'bodega_origen' => $this->whenLoaded('bodegaOrigen', fn (): array => $bodega($this->bodegaOrigen)),
            'bodega_destino' => $this->whenLoaded('bodegaDestino', fn (): array => $bodega($this->bodegaDestino)),
            'observaciones' => $this->observaciones,
            'creado_por_id' => $this->creado_por_id,
            'solicitado_por_id' => $this->solicitado_por_id,
            'solicitado_en' => $this->solicitado_en?->toIso8601String(),
            'aprobado_por_id' => $this->aprobado_por_id,
            'aprobado_en' => $this->aprobado_en?->toIso8601String(),
            'despachado_por_id' => $this->despachado_por_id,
            'despachado_en' => $this->despachado_en?->toIso8601String(),
            'recibido_por_id' => $this->recibido_por_id,
            'recibido_en' => $this->recibido_en?->toIso8601String(),
            'anulado_por_id' => $this->anulado_por_id,
            'anulado_en' => $this->anulado_en?->toIso8601String(),
            'motivo_anulacion' => $this->motivo_anulacion,
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn (TrasladoItem $item): array => [
                'id' => $item->id,
                'producto_id' => $item->producto_id,
                'producto' => $item->relationLoaded('producto') ? [
                    'id' => $item->producto->id,
                    'codigo' => $item->producto->codigo,
                    'nombre' => $item->producto->nombre,
                ] : null,
                'lote_id' => $item->lote_id,
                'lote' => $item->relationLoaded('lote') ? [
                    'id' => $item->lote->id,
                    'codigo' => $item->lote->codigo,
                    'fecha_vencimiento' => $item->lote->fecha_vencimiento->toDateString(),
                ] : null,
                'cantidad' => $item->cantidad,
                'cantidad_recibida' => $item->cantidad_recibida,
                'discrepancia' => $item->relationLoaded('discrepancia') && $item->discrepancia !== null
                    ? $this->discrepancia($item->discrepancia, $item, $request)
                    : null,
            ])->all()),
            'discrepancias' => $this->whenLoaded('items', fn (): array => $this->items
                ->filter(fn (TrasladoItem $item): bool => $item->relationLoaded('discrepancia') && $item->discrepancia !== null)
                ->map(fn (TrasladoItem $item): array => $this->discrepancia($item->discrepancia ?? throw new \LogicException, $item, $request))
                ->values()
                ->all()),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function discrepancia(Discrepancia $discrepancia, TrasladoItem $item, Request $request): array
    {
        return (new DiscrepanciaResource($discrepancia->setRelation('item', $item)))->toArray($request);
    }
}
