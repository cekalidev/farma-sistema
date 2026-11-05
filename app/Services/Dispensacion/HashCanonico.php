<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

/**
 * RN-09-AC4: huella del cuerpo de una solicitud, independiente del orden de las claves JSON.
 *
 * Función pura. Forma canónica:
 *  - objetos (arrays asociativos): claves ordenadas (orden de bytes), recursivamente;
 *  - listas: se conserva el orden (el orden de los ítems es parte de la solicitud, S-47);
 *  - enteros: un string con forma de entero decimal ("5", "-3") y un float sin parte fraccionaria
 *    dentro del rango de int (5.0) se normalizan a int, igual que los ids que acepta la validación
 *    (`numeric|integer`). El resto de valores se conserva con su tipo.
 *
 * Resultado: sha256 en hexadecimal (64 caracteres, columna dispensaciones.request_hash) del JSON
 * canónico (sin escapar `/` ni Unicode, conservando `.0` en floats no normalizados).
 */
final class HashCanonico
{
    private const OPCIONES_JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param  array<array-key, mixed>  $cuerpo
     */
    public static function hashCanonico(array $cuerpo): string
    {
        return hash('sha256', self::json($cuerpo));
    }

    /**
     * JSON canónico del cuerpo (lo que se resume en el hash).
     *
     * @param  array<array-key, mixed>  $cuerpo
     */
    public static function json(array $cuerpo): string
    {
        return json_encode(self::normalizar($cuerpo), self::OPCIONES_JSON);
    }

    private static function normalizar(mixed $valor): mixed
    {
        if (is_array($valor)) {
            if (array_is_list($valor)) {
                return array_map(self::normalizar(...), $valor);
            }

            $objeto = [];
            foreach ($valor as $clave => $hijo) {
                $objeto[(string) $clave] = self::normalizar($hijo);
            }
            ksort($objeto, SORT_STRING);

            // Un objeto con claves "0", "1"… seguidas sigue siendo objeto en el JSON canónico.
            return (object) $objeto;
        }

        if (is_string($valor) && preg_match('/^-?(0|[1-9]\d*)$/', $valor) === 1) {
            $entero = filter_var($valor, FILTER_VALIDATE_INT);

            return $entero === false ? $valor : $entero;
        }

        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor
            && $valor >= PHP_INT_MIN && $valor < PHP_INT_MAX) {
            return (int) $valor;
        }

        return $valor;
    }
}
