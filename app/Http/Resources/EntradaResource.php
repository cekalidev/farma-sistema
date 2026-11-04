<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Inventario\ResultadoEntrada;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta de POST /api/v1/inventario/entradas: la existencia resultante (mismo formato que
 * GET /api/v1/inventario, `cantidad` = saldo tras la entrada) más el bloque `entrada` con lo aplicado.
 *
 * @property ResultadoEntrada $resource
 */
class EntradaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new ExistenciaResource($this->resource->existencia))->toArray($request),
            'entrada' => [
                'cantidad' => $this->resource->cantidad,
                'saldo_anterior' => $this->resource->saldoAnterior,
                'saldo_posterior' => $this->resource->saldoPosterior,
                'lote_creado' => $this->resource->loteCreado,
            ],
        ];
    }
}
