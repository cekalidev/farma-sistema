<?php

declare(strict_types=1);

namespace App\Enums;

/** Estado de una discrepancia de traslado (RN-07-AC6/AC12). */
enum EstadoDiscrepancia: string
{
    case Pendiente = 'PENDIENTE';
    case Resuelta = 'RESUELTA';
}
