<?php

declare(strict_types=1);

use App\Exceptions\ErroresBaseDatos;
use App\Services\Dispensacion\DispensacionService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * RN-09-AC6 — ErroresBaseDatos::violaIndiceUnico() como función pura: el reintento simultáneo con
 * la misma Idempotency-Key se reconoce por el 23505 sobre dispensaciones_usuario_idempotency_unique
 * sea cual sea el idioma de los mensajes del servidor (lc_messages).
 *
 * El caso en español no se puede provocar de verdad contra el PostgreSQL de pruebas: acepta
 * `SET lc_messages = 'es_ES.UTF-8'` pero sigue respondiendo en inglés (servidor sin traducciones
 * NLS). Por eso se simula el errorInfo que daría un servidor en español (comillas «…»).
 *
 * Sin aplicación ni BD (función pura).
 */

const RN09_INDICE = DispensacionService::INDICE_IDEMPOTENCIA;

/** QueryException como la que lanza el driver pgsql, con el SQLSTATE y el texto dados. */
function rn09ErrorBd(string $sqlstate, string $mensaje, string $clase = UniqueConstraintViolationException::class): QueryException
{
    $pdo = new class($sqlstate, $mensaje) extends PDOException
    {
        public function __construct(string $sqlstate, string $mensaje)
        {
            parent::__construct("SQLSTATE[{$sqlstate}]: {$mensaje}");
            $this->code = $sqlstate;
            $this->errorInfo = [$sqlstate, 7, $mensaje];
        }
    };

    /** @var QueryException */
    return new $clase('pgsql', 'insert into "dispensaciones" (...) values (?)', [], $pdo);
}

it('RN-09-AC6: reconoce el 23505 del índice de idempotencia con el mensaje en inglés, en español («…») o sin comillas', function (string $mensaje) {
    expect(ErroresBaseDatos::violaIndiceUnico(rn09ErrorBd('23505', $mensaje), RN09_INDICE))->toBeTrue()
        // También envuelta en otra excepción (se busca en la cadena de previas).
        ->and(ErroresBaseDatos::violaIndiceUnico(new RuntimeException('envoltorio', 0, rn09ErrorBd('23505', $mensaje)), RN09_INDICE))->toBeTrue();
})->with([
    'inglés' => ['ERROR:  duplicate key value violates unique constraint "'.RN09_INDICE."\"\nDETAIL:  Key (dispensado_por_id, idempotency_key)=(1, k) already exists."],
    'español' => ['ERROR:  llave duplicada viola restricción de unicidad «'.RN09_INDICE."»\nDETAIL:  Ya existe la llave (dispensado_por_id, idempotency_key)=(1, k)."],
    'español sin DETAIL' => ['ERROR:  llave duplicada viola restricción de unicidad «'.RN09_INDICE.'»'],
    'otras comillas' => ['ERROR:  doppelter Schlüsselwert verletzt Unique-Constraint „'.RN09_INDICE.'“'],
    'sin comillas' => ['ERROR: unique violation on '.RN09_INDICE],
]);

it('RN-09-AC6: no lo confunde con otro 23505, con un nombre que solo lo contiene, con el nombre solo en DETAIL (valores del cliente) ni con otro SQLSTATE', function (string $sqlstate, string $mensaje) {
    expect(ErroresBaseDatos::violaIndiceUnico(rn09ErrorBd($sqlstate, $mensaje), RN09_INDICE))->toBeFalse();
})->with([
    'otro índice (inglés)' => ['23505', 'ERROR:  duplicate key value violates unique constraint "existencias_bodega_producto_lote_unique"'],
    'otro índice (español)' => ['23505', 'ERROR:  llave duplicada viola restricción de unicidad «existencias_bodega_producto_lote_unique»'],
    'prefijo de otro nombre' => ['23505', 'ERROR:  llave duplicada viola restricción de unicidad «'.RN09_INDICE.'_v2»'],
    'sufijo de otro nombre' => ['23505', 'ERROR:  duplicate key value violates unique constraint "x'.RN09_INDICE.'"'],
    'solo en DETAIL' => ['23505', "ERROR:  llave duplicada viola restricción de unicidad «otro_unique»\nDETAIL:  Ya existe la llave (clave)=(".RN09_INDICE.').'],
    'otro SQLSTATE' => ['23503', 'ERROR:  violación de llave foránea «'.RN09_INDICE.'»'],
    'mensaje vacío' => ['23505', ''],
]);

it('RN-09-AC6: una excepción que no es de BD no es una violación del índice', function () {
    expect(ErroresBaseDatos::violaIndiceUnico(new RuntimeException(RN09_INDICE), RN09_INDICE))->toBeFalse();
});
