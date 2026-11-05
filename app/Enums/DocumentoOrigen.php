<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Documento que origina un movimiento de kardex (columna `movimientos_kardex.documento_tipo`).
 * ENTRADA y AJUSTE no llevan documento (ver TipoMovimiento::documentoRequerido()).
 */
enum DocumentoOrigen: string
{
    case Dispensacion = 'dispensacion';
    case Traslado = 'traslado';
}
