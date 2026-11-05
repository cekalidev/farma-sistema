<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ConValores;

/**
 * Acción registrada en la bitácora `auditoria_accesos` (RN-10). Respaldada por un CHECK en la BD.
 *
 *  - Consultas de datos de paciente (RN-10-AC5/AC6): detalle, búsqueda, prescripciones del
 *    paciente y detalle de dispensación (anida al paciente).
 *  - Operaciones sensibles (RN-10-AC9): autorizar/rechazar control especial, ajuste de
 *    inventario, aprobar/anular traslado y resolver discrepancia.
 */
enum AccionAuditoria: string
{
    use ConValores;

    case PacienteConsultar = 'PACIENTE_CONSULTAR';
    case PacienteBuscar = 'PACIENTE_BUSCAR';
    case PacientePrescripciones = 'PACIENTE_PRESCRIPCIONES';
    case DispensacionConsultar = 'DISPENSACION_CONSULTAR';
    case DispensacionAutorizar = 'DISPENSACION_AUTORIZAR';
    case DispensacionRechazar = 'DISPENSACION_RECHAZAR';
    case InventarioAjustar = 'INVENTARIO_AJUSTAR';
    case TrasladoAprobar = 'TRASLADO_APROBAR';
    case TrasladoAnular = 'TRASLADO_ANULAR';
    case DiscrepanciaResolver = 'DISCREPANCIA_RESOLVER';

    /** Acción de consulta según el nombre de la ruta; null si la ruta no expone pacientes. */
    public static function deRuta(?string $nombreRuta): ?self
    {
        return match ($nombreRuta) {
            'api.v1.pacientes.show' => self::PacienteConsultar,
            'api.v1.pacientes.buscar' => self::PacienteBuscar,
            'api.v1.pacientes.prescripciones' => self::PacientePrescripciones,
            'api.v1.dispensaciones.show' => self::DispensacionConsultar,
            default => null,
        };
    }
}
