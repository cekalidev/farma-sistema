<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventario;

use App\Http\Requests\Concerns\ReglasPaginacion;
use App\Models\Existencia;
use App\Providers\AppServiceProvider;
use App\Services\Inventario\FiltrosInventario;
use Illuminate\Foundation\Http\FormRequest;

/** GET /api/v1/inventario?bodega_id=&producto_id=&lote_id=&por_pagina= (RN-01-AC9). */
class IndexInventarioRequest extends FormRequest
{
    use ReglasPaginacion;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Existencia::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // `numeric` además de `integer`: FILTER_VALIDATE_INT sola aceptaría booleanos (true → 1).
        $id = ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO];

        return [
            'bodega_id' => $id,
            'producto_id' => $id,
            'lote_id' => $id,
            ...$this->reglasPaginacion(),
        ];
    }

    public function filtros(): FiltrosInventario
    {
        /** @var array{bodega_id?: int|string, producto_id?: int|string, lote_id?: int|string, por_pagina?: int|string} $validados */
        $validados = $this->validated();

        return new FiltrosInventario(
            bodegaId: isset($validados['bodega_id']) ? (int) $validados['bodega_id'] : null,
            productoId: isset($validados['producto_id']) ? (int) $validados['producto_id'] : null,
            loteId: isset($validados['lote_id']) ? (int) $validados['lote_id'] : null,
            porPagina: $this->porPagina(),
        );
    }
}
