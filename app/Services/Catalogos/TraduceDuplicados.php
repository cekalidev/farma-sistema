<?php

declare(strict_types=1);

namespace App\Services\Catalogos;

use App\Exceptions\Dominio\CodigoDuplicado;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Altas de catálogo: la validación `unique` no cubre la carrera entre dos peticiones simultáneas;
 * la restricción única de la BD sí. Aquí se traduce esa violación a 409 CODIGO_DUPLICADO.
 */
trait TraduceDuplicados
{
    /**
     * @template T
     *
     * @param  Closure(): T  $insertar
     * @param  array<string, string>  $restricciones  nombre de la restricción única => campo
     * @return T
     */
    private function insertarSinDuplicados(Closure $insertar, array $restricciones): mixed
    {
        try {
            // Transacción propia: si ya hay una abierta es un savepoint, y el fallo no deja
            // abortada la transacción exterior de PostgreSQL.
            return DB::transaction($insertar);
        } catch (UniqueConstraintViolationException $e) {
            // Se busca el nombre de la restricción (no depende del idioma de los mensajes de PG).
            foreach ($restricciones as $restriccion => $campo) {
                if (str_contains($e->getMessage(), $restriccion)) {
                    throw new CodigoDuplicado($campo, $e);
                }
            }

            throw $e;
        }
    }
}
