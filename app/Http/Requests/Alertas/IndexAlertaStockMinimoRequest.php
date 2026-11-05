<?php

declare(strict_types=1);

namespace App\Http\Requests\Alertas;

use App\Enums\Habilidad;
use App\Http\Requests\Concerns\ReglasPaginacion;
use App\Providers\AppServiceProvider;
use App\Services\Inventario\FiltrosAlertaStockMinimo;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/alertas/stock-minimo?bodega_id=&producto_id=&por_pagina=&page= (RN-11-AC5/AC6).
 * Mismas reglas de ids y paginación que GET /inventario (S-25, S-28).
 */
class IndexAlertaStockMinimoRequest extends FormRequest
{
    use ReglasPaginacion;

    public function authorize(): bool
    {
        return $this->user()?->can(Habilidad::InventarioConsultar->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO];

        return [
            'bodega_id' => $id,
            'producto_id' => $id,
            ...$this->reglasPaginacion(),
        ];
    }

    public function filtros(): FiltrosAlertaStockMinimo
    {
        /** @var array{bodega_id?: int|string, producto_id?: int|string, por_pagina?: int|string} $validados */
        $validados = $this->validated();

        return new FiltrosAlertaStockMinimo(
            bodegaId: isset($validados['bodega_id']) ? (int) $validados['bodega_id'] : null,
            productoId: isset($validados['producto_id']) ? (int) $validados['producto_id'] : null,
            porPagina: $this->porPagina(),
        );
    }
}
