<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * RN-06-AC3: un movimiento de kardex nunca se edita ni se borra (lo lanza MovimientoKardex en
 * save()/update()/delete() y en el evento `updating`). El renderizador también responde con esta
 * excepción cuando es el trigger de BD el que rechaza la operación (S-39).
 *
 * Ninguna ruta lo provoca: si ocurre es un fallo de programación. Por eso, aunque es de dominio
 * (si llegara a una petición responde 409 KARDEX_INMUTABLE y no 500), SÍ se reporta al log
 * (bootstrap/app.php lo excluye del dontReport de ExcepcionDominio).
 */
final class KardexInmutableException extends ExcepcionDominio
{
    public static function paraOperacion(string $operacion, ?int $movimientoId): self
    {
        return new self("Los movimientos de kardex son inmutables: {$operacion} no permitido. Registre un ajuste.", [
            'operacion' => $operacion,
            'movimiento_id' => $movimientoId,
        ]);
    }

    public function codigo(): string
    {
        return 'KARDEX_INMUTABLE';
    }

    public function status(): int
    {
        return 409;
    }

    protected function mensajePorDefecto(): string
    {
        return 'Los movimientos de kardex son inmutables. Registre un ajuste.';
    }
}
