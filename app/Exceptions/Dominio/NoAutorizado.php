<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/** 403 lanzado desde un servicio cuando la acción no está permitida al usuario. */
final class NoAutorizado extends ExcepcionDominio
{
    public function codigo(): string
    {
        return 'NO_AUTORIZADO';
    }

    public function status(): int
    {
        return 403;
    }

    protected function mensajePorDefecto(): string
    {
        return 'No tiene permiso para realizar esta acción.';
    }
}
