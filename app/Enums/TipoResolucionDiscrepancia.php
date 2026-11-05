<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ConValores;

/**
 * RN-07-AC12: cómo se resuelve lo no recibido. PERDIDA no mueve stock; REINTEGRO_ORIGEN y
 * ENTREGA_DESTINO generan un AJUSTE positivo en la existencia del mismo lote en esa bodega.
 */
enum TipoResolucionDiscrepancia: string
{
    use ConValores;

    case Perdida = 'PERDIDA';
    case ReintegroOrigen = 'REINTEGRO_ORIGEN';
    case EntregaDestino = 'ENTREGA_DESTINO';
}
