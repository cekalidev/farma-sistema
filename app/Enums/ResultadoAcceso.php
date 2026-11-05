<?php

declare(strict_types=1);

namespace App\Enums;

/** Resultado de un acceso registrado en `auditoria_accesos` (RN-10-AC5/AC6). */
enum ResultadoAcceso: string
{
    case Permitido = 'PERMITIDO';
    case Denegado = 'DENEGADO';
}
