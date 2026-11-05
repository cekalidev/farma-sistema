<?php

declare(strict_types=1);

namespace App\Http\Requests\Traslados;

use App\Enums\TrasladoEstado;
use App\Http\Requests\Inventario\IndexInventarioRequest;
use App\Models\Traslado;
use App\Providers\AppServiceProvider;
use App\Services\Traslados\FiltrosTraslado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/traslados?estado=&bodega_origen_id=&bodega_destino_id=&bodega_id=&por_pagina=&page=
 * (RN-07-AC13; paginación como S-25/S-28). `bodega_id` filtra por origen O destino.
 */
class IndexTrasladoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Traslado::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO];

        return [
            'estado' => ['sometimes', 'string', Rule::in(TrasladoEstado::valores())],
            'bodega_origen_id' => $id,
            'bodega_destino_id' => $id,
            'bodega_id' => $id,
            'por_pagina' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.IndexInventarioRequest::POR_PAGINA_MAXIMO],
            'page' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.IndexInventarioRequest::PAGINA_MAXIMA],
        ];
    }

    public function filtros(): FiltrosTraslado
    {
        /** @var array{estado?: string, bodega_origen_id?: int|string, bodega_destino_id?: int|string, bodega_id?: int|string, por_pagina?: int|string} $validados */
        $validados = $this->validated();

        return new FiltrosTraslado(
            estado: isset($validados['estado']) ? TrasladoEstado::from($validados['estado']) : null,
            bodegaOrigenId: isset($validados['bodega_origen_id']) ? (int) $validados['bodega_origen_id'] : null,
            bodegaDestinoId: isset($validados['bodega_destino_id']) ? (int) $validados['bodega_destino_id'] : null,
            bodegaId: isset($validados['bodega_id']) ? (int) $validados['bodega_id'] : null,
            porPagina: (int) ($validados['por_pagina'] ?? IndexInventarioRequest::POR_PAGINA_DEFECTO),
        );
    }
}
