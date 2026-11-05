<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use JsonSerializable;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * RN-10-AC8: procesador de Monolog que sustituye por `[REDACTADO]` el valor de toda clave sensible
 * (datos personales del paciente, credenciales y cabeceras de autenticación) en `context` y `extra`,
 * a cualquier profundidad. Las claves se comparan sin mayúsculas, guiones ni guiones bajos
 * (`numero_documento` ≡ `numeroDocumento` ≡ `NUMERO-DOCUMENTO`).
 *
 * Es una red de seguridad: el código no debe registrar esos datos (se registran ids). No mira el
 * texto del mensaje, que es fijo en todo el código (los valores van en el contexto).
 *
 * Objetos: Monolog serializa por su cuenta los `JsonSerializable` (y Laravel los `Arrayable`), así
 * que antes de recorrer se convierten a array y se redactan igual que un array:
 *  - Un modelo Eloquent se sustituye ENTERO por `[REDACTADO]`, a cualquier profundidad (también
 *    dentro de una colección o de otro array). No se redacta su toArray() porque la lista de claves
 *    sensibles no puede anticipar cada columna de texto libre de cada modelo (ni sus relaciones
 *    cargadas): un modelo en el log es un error de código; se registran sus ids.
 *  - Una colección (`Enumerable`) se recorre por sus elementos (`all()`), para que los modelos que
 *    contiene se detecten como modelos y no se aplanen antes con su toArray().
 *  - Cualquier otro `Arrayable` → toArray(); cualquier otro `JsonSerializable` → jsonSerialize()
 *    (si no devuelve un array, se deja ese valor escalar).
 */
final class RedactorDatosSensibles implements ProcessorInterface
{
    public const REDACTADO = '[REDACTADO]';

    /** Claves sensibles, ya normalizadas (minúsculas, sin `_` ni `-`). */
    public const CLAVES = [
        // Datos personales del paciente (RN-10).
        'tipodocumento', 'numerodocumento', 'documento', 'nombres', 'apellidos', 'nombre',
        'fechanacimiento', 'telefono', 'correo', 'email', 'direccion', 'observaciones', 'indicaciones',
        'diagnostico',
        // Credenciales y cabeceras de autenticación.
        'password', 'passwordconfirmation', 'currentpassword', 'token', 'accesstoken', 'plaintexttoken',
        'authorization', 'cookie', 'setcookie', 'xxsrftoken', 'xcsrftoken', 'secret', 'apikey',
    ];

    /** Profundidad máxima que se recorre; lo más profundo se redacta entero. */
    private const PROFUNDIDAD_MAXIMA = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redactar($record->context, 0),
            extra: $this->redactar($record->extra, 0),
        );
    }

    public static function esSensible(string|int $clave): bool
    {
        return is_string($clave)
            && in_array(strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $clave)), self::CLAVES, true);
    }

    /**
     * @param  array<mixed>  $datos
     * @return array<mixed>
     */
    private function redactar(array $datos, int $profundidad): array
    {
        foreach ($datos as $clave => $valor) {
            if (self::esSensible($clave)) {
                $datos[$clave] = self::REDACTADO;

                continue;
            }

            $valor = self::comoArray($valor);

            if (is_array($valor)) {
                $valor = $profundidad >= self::PROFUNDIDAD_MAXIMA
                    ? self::REDACTADO
                    : $this->redactar($valor, $profundidad + 1);
            }

            $datos[$clave] = $valor;
        }

        return $datos;
    }

    /**
     * Modelo → `[REDACTADO]`; colección, Arrayable o JsonSerializable → su array (ver la cabecera);
     * cualquier otro valor, tal cual.
     */
    private static function comoArray(mixed $valor): mixed
    {
        return match (true) {
            $valor instanceof Model => self::REDACTADO,
            $valor instanceof Enumerable => $valor->all(),
            $valor instanceof Arrayable => $valor->toArray(),
            $valor instanceof JsonSerializable => $valor->jsonSerialize(),
            default => $valor,
        };
    }
}
