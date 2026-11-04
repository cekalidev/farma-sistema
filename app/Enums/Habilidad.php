<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Acciones de la matriz de roles (sección 3). Cada caso se registra como Gate
 * con su valor (p. ej. Gate::allows('inventario.consultar')).
 *
 * Las reglas que dependen de otro usuario (segundo regente RN-05, solicitante ≠
 * aprobador RN-08, anular solo lo propio) NO van aquí: se validan en Policy/servicio.
 */
enum Habilidad: string
{
    /** Crear dispensación. */
    case DispensacionCrear = 'dispensacion.crear';
    /** Autorizar/rechazar dispensación de control especial (RN-05). */
    case DispensacionAutorizar = 'dispensacion.autorizar';
    /** Consultar inventario, kardex y alertas. */
    case InventarioConsultar = 'inventario.consultar';
    /** Registrar entradas y ajustes de inventario. */
    case InventarioRegistrar = 'inventario.registrar';
    /** Crear, solicitar, despachar y recibir traslados. */
    case TrasladoOperar = 'traslado.operar';
    /** Aprobar traslados y resolver discrepancias (RN-08). */
    case TrasladoAprobar = 'traslado.aprobar';
    /** Anular traslados (el auxiliar solo los que creó: se valida en Policy). */
    case TrasladoAnular = 'traslado.anular';
    /** Consultar traslados. */
    case TrasladoConsultar = 'traslado.consultar';
    /** Crear prescripciones. */
    case PrescripcionCrear = 'prescripcion.crear';
    /** Consultar pacientes y prescripciones (auditor: enmascarado, RN-10). */
    case PacienteConsultar = 'paciente.consultar';
    /** Consultar bitácora de auditoría. */
    case AuditoriaConsultar = 'auditoria.consultar';
    /** Consultar el catálogo de bodegas (S-18). */
    case BodegaConsultar = 'bodega.consultar';
    /** Consultar el catálogo de productos (S-18; el médico lo necesita para prescribir). */
    case ProductoConsultar = 'producto.consultar';
    /** Gestión de usuarios y catálogos (bodegas, productos, pacientes, stock mínimo). */
    case CatalogoGestionar = 'catalogo.gestionar';
}
