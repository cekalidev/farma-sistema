<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogos;

use App\Models\Producto;

/** GET /api/v1/productos?por_pagina=&page= (S-88). */
class IndexProductoRequest extends IndexCatalogoRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Producto::class) ?? false;
    }
}
