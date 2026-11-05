<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogos;

use App\Models\Bodega;

/** GET /api/v1/bodegas?por_pagina=&page= (S-88). */
class IndexBodegaRequest extends IndexCatalogoRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Bodega::class) ?? false;
    }
}
