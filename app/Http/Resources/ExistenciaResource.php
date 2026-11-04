<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Existencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Existencia (RN-01-AC9). `vencido` se calcula con Lote::estaVencido (S-01: vence hoy = vencido).
 * `fecha_vencimiento` va también en la raíz por comodidad del cliente (igual a lote.fecha_vencimiento).
 *
 * @mixin Existencia
 */
class ExistenciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fecha = $this->lote->fecha_vencimiento->toDateString();

        return [
            'id' => $this->id,
            'bodega_id' => $this->bodega_id,
            'producto_id' => $this->producto_id,
            'lote_id' => $this->lote_id,
            'bodega' => $this->whenLoaded('bodega', fn (): array => [
                'id' => $this->bodega->id,
                'codigo' => $this->bodega->codigo,
                'nombre' => $this->bodega->nombre,
            ]),
            'producto' => $this->whenLoaded('producto', fn (): array => [
                'id' => $this->producto->id,
                'codigo' => $this->producto->codigo,
                'nombre' => $this->producto->nombre,
            ]),
            'lote' => [
                'id' => $this->lote->id,
                'codigo' => $this->lote->codigo,
                'fecha_vencimiento' => $fecha,
            ],
            'fecha_vencimiento' => $fecha,
            'cantidad' => $this->cantidad,
            'vencido' => $this->lote->estaVencido(now()),
        ];
    }
}
