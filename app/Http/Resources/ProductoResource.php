<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Producto
 */
class ProductoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'presentacion' => $this->presentacion,
            'unidad_medida' => $this->unidad_medida,
            'control_especial' => $this->control_especial,
            'activo' => $this->activo,
        ];
    }
}
