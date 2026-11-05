<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados de la dispensación (RN-05, S-06). La BD los respalda con CHECK.
 * Hasta RN-05 toda dispensación nace CONFIRMADA (stock descontado en la misma transacción).
 */
enum EstadoDispensacion: string
{
    /** Stock descontado (sin control especial, o autorizada por un segundo regente). */
    case Confirmada = 'CONFIRMADA';
    /** Con control especial: espera al segundo regente; aún no mueve stock (RN-05). */
    case PendienteAutorizacion = 'PENDIENTE_AUTORIZACION';
    /** Rechazada por un segundo regente; nunca movió stock (RN-05-AC8). */
    case Rechazada = 'RECHAZADA';
}
