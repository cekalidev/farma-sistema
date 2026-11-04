<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/** BASE-AC2: mismo código y mensaje para correo inexistente o contraseña errónea. */
final class CredencialesInvalidas extends ExcepcionDominio
{
    public function codigo(): string
    {
        return 'CREDENCIALES_INVALIDAS';
    }

    public function status(): int
    {
        return 401;
    }

    protected function mensajePorDefecto(): string
    {
        return 'Correo o contraseña incorrectos.';
    }
}
