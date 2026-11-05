<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispensacion;

use App\Enums\EstadoDispensacion;
use App\Http\Requests\Concerns\ReglasPaginacion;
use App\Models\Dispensacion;
use App\Providers\AppServiceProvider;
use App\Services\Dispensacion\FiltrosDispensacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/v1/dispensaciones?estado=&bodega_id=&por_pagina=&page= (S-89; paginación S-25/S-28/S-88). */
class IndexDispensacionRequest extends FormRequest
{
    use ReglasPaginacion;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Dispensacion::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['sometimes', 'string', Rule::in(EstadoDispensacion::valores())],
            'bodega_id' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO],
            ...$this->reglasPaginacion(),
        ];
    }

    public function filtros(): FiltrosDispensacion
    {
        /** @var array{estado?: string, bodega_id?: int|string, por_pagina?: int|string} $validados */
        $validados = $this->validated();

        return new FiltrosDispensacion(
            estado: isset($validados['estado']) ? EstadoDispensacion::from($validados['estado']) : null,
            bodegaId: isset($validados['bodega_id']) ? (int) $validados['bodega_id'] : null,
            porPagina: $this->porPagina(),
        );
    }
}
