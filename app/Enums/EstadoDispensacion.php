<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ConValores;

/**
 * Estados de la dispensación (RN-05, S-06). La BD los respalda con CHECK.
 * Sin control especial nace CONFIRMADA; con control especial, PENDIENTE_AUTORIZACION, y solo desde
 * ahí pasa a CONFIRMADA (autorizar) o RECHAZADA (rechazar), RN-05-AC7.
 */
enum EstadoDispensacion: string
{
    use ConValores;

    /** Stock descontado (sin control especial, o autorizada por un segundo regente). */
    case Confirmada = 'CONFIRMADA';
    /** Con control especial: espera al segundo regente; aún no mueve stock (RN-05). */
    case PendienteAutorizacion = 'PENDIENTE_AUTORIZACION';
    /** Rechazada por un segundo regente; nunca movió stock (RN-05-AC8). */
    case Rechazada = 'RECHAZADA';
}
