<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoPrescripcion: string
{
    case Activa = 'ACTIVA';
    case Anulada = 'ANULADA';
}
