<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogos (bodegas, productos…). Lectura: la habilidad de consulta propia de cada
 * catálogo (S-18). Alta y modificación: solo catalogo.gestionar (admin).
 * Las Policies concretas heredan de aquí e indican su habilidad de lectura.
 */
abstract class CatalogoPolicy
{
    abstract protected function habilidadLectura(): Habilidad;

    public function viewAny(User $user): bool
    {
        return $user->tieneHabilidad($this->habilidadLectura());
    }

    public function view(User $user, Model $modelo): bool
    {
        return $user->tieneHabilidad($this->habilidadLectura());
    }

    public function create(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::CatalogoGestionar);
    }

    public function update(User $user, Model $modelo): bool
    {
        return $user->tieneHabilidad(Habilidad::CatalogoGestionar);
    }
}
