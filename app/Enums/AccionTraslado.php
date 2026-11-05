<?php

declare(strict_types=1);

namespace App\Enums;

/** Acciones de la máquina de estados del traslado (RN-07). Las transiciones: TrasladoEstado::puede(). */
enum AccionTraslado: string
{
    case Crear = 'crear';
    case Solicitar = 'solicitar';
    case Aprobar = 'aprobar';
    case Despachar = 'despachar';
    case Recibir = 'recibir';
    case Anular = 'anular';
    case EditarItems = 'editar_items';
}
