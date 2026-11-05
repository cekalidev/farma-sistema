<?php

declare(strict_types=1);

namespace App\Enums;

/** Entidad sobre la que actúa una fila de `auditoria_accesos` (RN-10-AC9). */
enum EntidadAuditada: string
{
    case Dispensacion = 'dispensacion';
    case MovimientoKardex = 'movimiento_kardex';
    case Traslado = 'traslado';
    case Discrepancia = 'discrepancia';
}
