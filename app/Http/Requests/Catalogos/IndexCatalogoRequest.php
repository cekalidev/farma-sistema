<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogos;

use App\Http\Requests\Inventario\IndexInventarioRequest;
use Illuminate\Foundation\Http\FormRequest;

/** Listados de catálogo (bodegas, productos): solo paginación, con los límites de S-25, S-28 y S-88. */
abstract class IndexCatalogoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'por_pagina' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.IndexInventarioRequest::POR_PAGINA_MAXIMO],
            'page' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.IndexInventarioRequest::PAGINA_MAXIMA],
        ];
    }

    public function porPagina(): int
    {
        /** @var array{por_pagina?: int|string} $validados */
        $validados = $this->validated();

        return (int) ($validados['por_pagina'] ?? IndexInventarioRequest::POR_PAGINA_DEFECTO);
    }
}
