<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;

final class ProductoPolicy extends CatalogoPolicy
{
    protected function habilidadLectura(): Habilidad
    {
        return Habilidad::ProductoConsultar;
    }
}
