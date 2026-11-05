<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Models\Producto;

/**
 * RN-05 / S-06: decisión pura de si una dispensación necesita la autorización de un segundo
 * regente. Basta un producto de control especial para que la dispensación ENTERA quede pendiente
 * (mixta → pendiente). No depende del rol de quien dispensa: un regente también necesita a otro.
 */
final class ControlEspecial
{
    /**
     * @param  iterable<Producto>  $productos  productos de los ítems de la dispensación
     */
    public static function requiereAutorizacion(iterable $productos): bool
    {
        foreach ($productos as $producto) {
            if ($producto->control_especial) {
                return true;
            }
        }

        return false;
    }
}
