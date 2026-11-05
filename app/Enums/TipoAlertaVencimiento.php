<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ConValores;

/** RN-11: tipo de alerta de vencimiento de un lote con stock. */
enum TipoAlertaVencimiento: string
{
    use ConValores;

    /** Vence hoy o ya venció (S-01: fecha_vencimiento <= hoy). */
    case Vencido = 'VENCIDO';
    /** Vence entre mañana y hoy + umbral (farma.dias_alerta_vencimiento), ambos inclusive. */
    case PorVencer = 'POR_VENCER';
}
