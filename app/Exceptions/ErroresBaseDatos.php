<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Errores de BD (QueryException): clasificación para la respuesta y registro en el log SIN
 * SQL ni bindings (RN-10: un binding puede ser un documento, nombre o correo de paciente).
 */
final class ErroresBaseDatos
{
    /** 22P02 invalid_text_representation: texto que no se puede convertir al tipo de la columna. */
    public const TEXTO_INVALIDO = '22P02';

    /** 22003 numeric_value_out_of_range. */
    public const FUERA_DE_RANGO = '22003';

    /** 23001 restrict_violation: ERRCODE del trigger de inmutabilidad del kardex. */
    public const RESTRICCION_VIOLADA = '23001';

    /**
     * Texto fijo del RAISE de la función movimientos_kardex_inmutable() (migración
     * create_movimientos_kardex_table). Distingue ese trigger de cualquier otro 23001.
     */
    public const MARCA_KARDEX_INMUTABLE = 'movimientos_kardex es inmutable';

    /** Marcos de pila que se registran (solo archivo:línea, nunca argumentos). */
    private const MARCOS_EN_LOG = 15;

    /**
     * QueryException de la excepción o de su cadena de previas.
     */
    public static function consultaFallida(Throwable $e): ?QueryException
    {
        for ($actual = $e; $actual !== null; $actual = $actual->getPrevious()) {
            if ($actual instanceof QueryException) {
                return $actual;
            }
        }

        return null;
    }

    /**
     * ¿El fallo se debe a un valor enviado por el cliente que PostgreSQL no puede convertir?
     *
     *  - 22P02: siempre (texto no convertible al tipo de la columna = dato de entrada mal formado).
     *  - 22003: solo si uno de los bindings coincide con un valor crudo del cliente (parámetro de
     *    ruta, query o cuerpo). Un desbordamiento calculado por el servidor es un fallo interno
     *    (500), no un dato inválido.
     */
    public static function esDatoInvalidoDelCliente(Throwable $e, Request $request): bool
    {
        $consulta = self::consultaFallida($e);

        if ($consulta === null) {
            return false;
        }

        return match ((string) $consulta->getCode()) {
            self::TEXTO_INVALIDO => true,
            self::FUERA_DE_RANGO => self::algunBindingVieneDelCliente($consulta, $request),
            default => false,
        };
    }

    /**
     * ¿El fallo es el trigger de inmutabilidad del kardex (RN-06-AC4) rechazando un UPDATE, DELETE
     * o TRUNCATE? S-39: si llega a una petición se responde 409 KARDEX_INMUTABLE. Solo ese trigger:
     * exige el SQLSTATE 23001 y el mensaje propio de movimientos_kardex_inmutable().
     */
    public static function esKardexInmutable(Throwable $e): bool
    {
        $consulta = self::consultaFallida($e);

        if ($consulta === null || (string) $consulta->getCode() !== self::RESTRICCION_VIOLADA) {
            return false;
        }

        $mensajeDriver = is_array($consulta->errorInfo) && is_string($consulta->errorInfo[2] ?? null)
            ? $consulta->errorInfo[2]
            : '';

        return str_contains($mensajeDriver, self::MARCA_KARDEX_INMUTABLE);
    }

    /**
     * Callback de reporte (bootstrap/app.php): registra SQLSTATE, conexión, ruta (plantilla, no
     * la URL con valores) y pila sin argumentos; devuelve false para que Laravel NO haga su
     * registro por defecto (que incluye el SQL con los valores). null = no es un error de BD.
     */
    public static function reportar(Throwable $e): ?bool
    {
        $consulta = self::consultaFallida($e);

        if ($consulta === null) {
            return null;
        }

        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $ruta = $request?->route();

        $contexto = [
            'sqlstate' => (string) $consulta->getCode(),
            'conexion' => $consulta->connectionName,
            'excepcion' => $e::class,
            'metodo' => $request?->method(),
            'ruta' => is_object($ruta) ? $ruta->uri() : null,
            'pila' => self::pilaSinArgumentos($consulta),
        ];

        $nivel = $request !== null && self::esDatoInvalidoDelCliente($e, $request) ? 'warning' : 'error';
        Log::log($nivel, 'Error de base de datos (SQL y valores omitidos).', $contexto);

        return false;
    }

    private static function algunBindingVieneDelCliente(QueryException $consulta, Request $request): bool
    {
        $ruta = $request->route();
        $delCliente = array_merge(
            is_object($ruta) ? array_values($ruta->originalParameters()) : [],
            array_values(Arr::dot($request->query())),
            array_values(Arr::dot($request->all())),
        );

        $valoresCliente = [];
        foreach ($delCliente as $valor) {
            if (is_scalar($valor)) {
                $valoresCliente[(string) $valor] = true;
            }
        }

        foreach ($consulta->getBindings() as $binding) {
            if (is_scalar($binding) && isset($valoresCliente[(string) $binding])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function pilaSinArgumentos(Throwable $e): array
    {
        $pila = [];
        foreach (array_slice($e->getTrace(), 0, self::MARCOS_EN_LOG) as $marco) {
            $pila[] = ($marco['file'] ?? '[interno]').':'.($marco['line'] ?? 0);
        }

        return $pila;
    }
}
