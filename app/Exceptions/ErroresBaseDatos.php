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

    /** 23505 unique_violation. */
    public const VIOLACION_UNICA = '23505';

    /** 23001 restrict_violation: ERRCODE del trigger de inmutabilidad del kardex. */
    public const RESTRICCION_VIOLADA = '23001';

    /** 40P01 deadlock_detected: PostgreSQL abortó esta transacción para romper un interbloqueo. */
    public const INTERBLOQUEO = '40P01';

    /** 40001 serialization_failure: la transacción no se pudo serializar con otra concurrente. */
    public const FALLO_SERIALIZACION = '40001';

    /** 55P03 lock_not_available: NOWAIT o lock_timeout sin obtener el bloqueo. */
    public const BLOQUEO_NO_DISPONIBLE = '55P03';

    /**
     * Texto fijo del RAISE de la función movimientos_kardex_inmutable() (migración
     * create_movimientos_kardex_table). Distingue ese trigger de cualquier otro 23001.
     */
    public const MARCA_KARDEX_INMUTABLE = 'movimientos_kardex es inmutable';

    /** Texto fijo del RAISE de auditoria_accesos_inmutable() (migración create_auditoria_accesos_table). */
    public const MARCA_BITACORA_INMUTABLE = 'auditoria_accesos es inmutable';

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
     * S-48: ¿PostgreSQL abortó la operación por un choque con otra transacción concurrente
     * (interbloqueo, fallo de serialización o bloqueo no disponible)? La transacción se revirtió
     * entera, así que el cliente puede reintentar la misma petición.
     */
    public static function esConflictoConcurrencia(Throwable $e): bool
    {
        $consulta = self::consultaFallida($e);

        return $consulta !== null && in_array((string) $consulta->getCode(), [
            self::INTERBLOQUEO,
            self::FALLO_SERIALIZACION,
            self::BLOQUEO_NO_DISPONIBLE,
        ], true);
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

        return str_contains(self::mensajeDriver($consulta), self::MARCA_KARDEX_INMUTABLE);
    }

    /**
     * ¿El fallo es el trigger de inmutabilidad de la bitácora (RN-10-AC10)? Si llega a una
     * petición se responde 409 BITACORA_INMUTABLE, como S-39 para el kardex.
     */
    public static function esBitacoraInmutable(Throwable $e): bool
    {
        $consulta = self::consultaFallida($e);

        if ($consulta === null || (string) $consulta->getCode() !== self::RESTRICCION_VIOLADA) {
            return false;
        }

        return str_contains(self::mensajeDriver($consulta), self::MARCA_BITACORA_INMUTABLE);
    }

    /**
     * ¿El fallo es un 23505 sobre ESE índice/restricción única? Lo usa quien sabe resolver ese
     * choque concreto (RN-09: misma Idempotency-Key); cualquier otro 23505 sigue siendo un fallo
     * interno (500).
     *
     * Independiente del idioma del servidor (lc_messages): la plantilla del mensaje y las comillas
     * se traducen ("…" en inglés, «…» en español), el nombre del índice nunca. Por eso busca el
     * nombre como identificador completo (sin letras, dígitos ni _ pegados) y solo en la primera
     * línea del mensaje: las siguientes (DETAIL) contienen los valores, que vienen del cliente.
     */
    public static function violaIndiceUnico(Throwable $e, string $indice): bool
    {
        $consulta = self::consultaFallida($e);

        if ($consulta === null || (string) $consulta->getCode() !== self::VIOLACION_UNICA) {
            return false;
        }

        $primeraLinea = strtok(self::mensajeDriver($consulta), "\r\n");

        return is_string($primeraLinea)
            && preg_match('/(?<![\p{L}\p{N}_$])'.preg_quote($indice, '/').'(?![\p{L}\p{N}_$])/u', $primeraLinea) === 1;
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

        // Esperables y sin fallo del servidor: dato mal formado del cliente o choque de concurrencia
        // (S-48, reintentable). El resto es un fallo interno.
        $nivel = self::esConflictoConcurrencia($e) || ($request !== null && self::esDatoInvalidoDelCliente($e, $request))
            ? 'warning'
            : 'error';
        Log::log($nivel, 'Error de base de datos (SQL y valores omitidos).', $contexto);

        return false;
    }

    /** Texto del error de PostgreSQL (errorInfo[2]); '' si el driver no lo dio. */
    private static function mensajeDriver(QueryException $consulta): string
    {
        return is_array($consulta->errorInfo) && is_string($consulta->errorInfo[2] ?? null)
            ? $consulta->errorInfo[2]
            : '';
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
