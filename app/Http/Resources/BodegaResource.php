<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bodega;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bodega
 */
class BodegaResource extends JsonResource
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
            'activa' => $this->activa,
        ];
    }
}
