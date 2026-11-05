<?php

declare(strict_types=1);

namespace App\Http\Requests\Alertas;

use App\Enums\Habilidad;
use App\Enums\TipoAlertaVencimiento;
use App\Http\Requests\Concerns\ReglasPaginacion;
use App\Providers\AppServiceProvider;
use App\Services\Inventario\FiltrosAlertaVencimiento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/alertas/vencimientos?bodega_id=&producto_id=&tipo=&por_pagina=&page= (RN-11-AC4).
 * Mismas reglas de ids y paginación que GET /inventario (S-25, S-28); `tipo` ∈ {VENCIDO, POR_VENCER}.
 */
class IndexAlertaVencimientoRequest extends FormRequest
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
            'tipo' => ['sometimes', 'string', Rule::in(TipoAlertaVencimiento::valores())],
            ...$this->reglasPaginacion(),
        ];
    }

    public function filtros(): FiltrosAlertaVencimiento
    {
        /** @var array{bodega_id?: int|string, producto_id?: int|string, tipo?: string, por_pagina?: int|string} $validados */
        $validados = $this->validated();

        return new FiltrosAlertaVencimiento(
            bodegaId: isset($validados['bodega_id']) ? (int) $validados['bodega_id'] : null,
            productoId: isset($validados['producto_id']) ? (int) $validados['producto_id'] : null,
            tipo: isset($validados['tipo']) ? TipoAlertaVencimiento::from($validados['tipo']) : null,
            porPagina: $this->porPagina(),
        );
    }
}
