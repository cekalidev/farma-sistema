<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MovimientoKardex;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Movimiento de kardex (RN-06-AC1). `created_at` en ISO 8601 con desplazamiento; `documento` es
 * null para ENTRADA y AJUSTE. Usuario: solo id y nombre (personal, no datos de pacientes).
 *
 * @mixin MovimientoKardex
 */
class MovimientoKardexResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'existencia_id' => $this->existencia_id,
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
            'lote' => $this->whenLoaded('lote', fn (): array => [
                'id' => $this->lote->id,
                'codigo' => $this->lote->codigo,
                'fecha_vencimiento' => $this->lote->fecha_vencimiento->toDateString(),
            ]),
            'cantidad' => $this->cantidad,
            'saldo_anterior' => $this->saldo_anterior,
            'saldo_posterior' => $this->saldo_posterior,
            'motivo' => $this->motivo,
            'usuario_id' => $this->usuario_id,
            'usuario' => $this->whenLoaded('usuario', fn (): array => [
                'id' => $this->usuario->id,
                'nombre' => $this->usuario->name,
            ]),
            'documento' => $this->documento_tipo === null ? null : [
                'tipo' => $this->documento_tipo->value,
                'id' => $this->documento_id,
            ],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
