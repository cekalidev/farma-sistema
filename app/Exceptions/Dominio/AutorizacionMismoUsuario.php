<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 403 AUTORIZACION_MISMO_USUARIO (RN-05-AC4, S-06): quien creó una dispensación de control
 * especial no puede autorizarla ni rechazarla, aunque sea regente. La BD lo respalda con CHECK
 * (RN-05-AC9). Detalles: solo el id de la dispensación.
 */
final class AutorizacionMismoUsuario extends ExcepcionDominio
{
    public static function paraDispensacion(int $dispensacionId): self
    {
        return new self('', ['dispensacion_id' => $dispensacionId]);
    }

    public function codigo(): string
    {
        return 'AUTORIZACION_MISMO_USUARIO';
    }

    public function status(): int
    {
        return 403;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La dispensación debe autorizarla o rechazarla un regente distinto de quien la creó.';
    }
}
