<?php

declare(strict_types=1);

namespace App\Enums;

/** Tipos de documento de identidad (Colombia). */
enum TipoDocumento: string
{
    case CedulaCiudadania = 'CC';
    case TarjetaIdentidad = 'TI';
    case CedulaExtranjeria = 'CE';
    case Pasaporte = 'PA';
    case RegistroCivil = 'RC';
}
