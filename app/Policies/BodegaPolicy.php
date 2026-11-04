<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;

final class BodegaPolicy extends CatalogoPolicy
{
    protected function habilidadLectura(): Habilidad
    {
        return Habilidad::BodegaConsultar;
    }
}
