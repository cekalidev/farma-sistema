<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Inventario\AlertaVencimiento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RN-11-AC1/AC2: existencia en alerta de vencimiento. `dias_restantes` = fecha_vencimiento − hoy
 * (Bogotá): 1..umbral para POR_VENCER; 0 (vence hoy) o negativo (días vencido) para VENCIDO.
 *
 * @property AlertaVencimiento $resource
 */
class AlertaVencimientoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $existencia = $this->resource->existencia;
        $fecha = $existencia->lote->fecha_vencimiento->toDateString();

        return [
            'existencia_id' => $existencia->id,
            'bodega' => [
                'id' => $existencia->bodega->id,
                'codigo' => $existencia->bodega->codigo,
                'nombre' => $existencia->bodega->nombre,
            ],
            'producto' => [
                'id' => $existencia->producto->id,
                'codigo' => $existencia->producto->codigo,
                'nombre' => $existencia->producto->nombre,
            ],
            'lote' => [
                'id' => $existencia->lote->id,
                'codigo' => $existencia->lote->codigo,
                'fecha_vencimiento' => $fecha,
            ],
            'fecha_vencimiento' => $fecha,
            'cantidad' => $existencia->cantidad,
            'tipo' => $this->resource->tipo->value,
            'dias_restantes' => $this->resource->diasRestantes,
        ];
    }
}
