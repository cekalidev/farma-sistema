<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\DocumentoOrigen;
use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\CantidadExcedeMaximo;
use App\Exceptions\Dominio\StockInsuficiente;
use App\Models\Existencia;
use App\Models\MovimientoKardex;
use Illuminate\Support\Facades\DB;

/**
 * RN-06: único punto que cambia `existencias.cantidad` y escribe el kardex.
 *
 * Contrato del llamador: abrir la transacción (DB::transaction) y bloquear la existencia con
 * lockForUpdate ANTES de llamar. Aquí se relee el saldo con FOR UPDATE (idempotente dentro de la
 * misma transacción) y se exige que coincida con el del modelo recibido: si el llamador validó
 * con un valor desactualizado, falla en vez de escribir un saldo incoherente.
 *
 * El movimiento y el cambio de stock quedan en la misma transacción: si algo falla después, el
 * rollback del llamador deshace ambos (RN-06-AC7).
 */
final class RegistradorKardex
{
    /** Máximo de `existencias.cantidad` y de los saldos del kardex (integer de PostgreSQL). */
    public const SALDO_MAXIMO = 2_147_483_647;

    /**
     * Longitud de `movimientos_kardex.motivo`. Acota también `dispensaciones.motivo_rechazo
     * varchar(500)` (RN-05-AC8, RechazarDispensacionRequest): si cambia, revisa ambas columnas.
     */
    public const MOTIVO_MAXIMO = 500;

    /** Longitud mínima del motivo de un ajuste (RN-06-AC6) o de un rechazo, sin espacios en los extremos. */
    public const MOTIVO_MINIMO = 10;

    /** Motivo sin Cc/Cf/Zl/Zp (S-33): ajustes (StoreAjusteRequest) y rechazo de dispensación (RN-05-AC8). */
    public const REGEX_MOTIVO = '/^[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+$/u';

    /**
     * Aplica `cantidad` (con signo) a la existencia y registra el movimiento.
     *
     * @throws \LogicException si no hay transacción abierta o el saldo del modelo no es el bloqueado.
     * @throws \InvalidArgumentException si signo, motivo o documento no corresponden al tipo
     *                                   (fallo del llamador: la entrada HTTP ya se validó antes).
     * @throws StockInsuficiente si el saldo quedaría negativo (409).
     * @throws CantidadExcedeMaximo si el saldo superaría SALDO_MAXIMO (422).
     */
    public function registrar(
        TipoMovimiento $tipo,
        Existencia $existencia,
        int $cantidad,
        int $usuarioId,
        ?string $motivo = null,
        ?DocumentoOrigen $documentoTipo = null,
        ?int $documentoId = null,
    ): MovimientoKardex {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('RegistradorKardex::registrar exige una transacción abierta con la existencia bloqueada.');
        }

        $this->validarMovimiento($tipo, $cantidad, $motivo, $documentoTipo, $documentoId);

        $saldoAnterior = $this->saldoBloqueado($existencia);

        if ($cantidad < 0 && $saldoAnterior + $cantidad < 0) {
            throw StockInsuficiente::paraExistencia($existencia->id, $saldoAnterior, -$cantidad);
        }
        if ($cantidad > 0 && $cantidad > self::SALDO_MAXIMO - $saldoAnterior) {
            throw CantidadExcedeMaximo::paraSaldo($saldoAnterior, $cantidad, self::SALDO_MAXIMO);
        }

        $saldoPosterior = $saldoAnterior + $cantidad;
        $existencia->forceFill(['cantidad' => $saldoPosterior])->save();

        $movimiento = new MovimientoKardex;
        $movimiento->forceFill([
            'tipo' => $tipo,
            'existencia_id' => $existencia->id,
            'bodega_id' => $existencia->bodega_id,
            'producto_id' => $existencia->producto_id,
            'lote_id' => $existencia->lote_id,
            'cantidad' => $cantidad,
            'saldo_anterior' => $saldoAnterior,
            'saldo_posterior' => $saldoPosterior,
            'motivo' => $motivo,
            'usuario_id' => $usuarioId,
            'documento_tipo' => $documentoTipo,
            'documento_id' => $documentoId,
        ])->save();

        return $movimiento;
    }

    /**
     * Reglas de RN-06-AC1/AC6 independientes del saldo (la BD las repite con CHECK).
     *
     * @throws \InvalidArgumentException
     */
    public function validarMovimiento(
        TipoMovimiento $tipo,
        int $cantidad,
        ?string $motivo,
        ?DocumentoOrigen $documentoTipo,
        ?int $documentoId,
    ): void {
        if (! $tipo->admiteCantidad($cantidad)) {
            throw new \InvalidArgumentException("Cantidad {$cantidad} no válida para un movimiento {$tipo->value}.");
        }

        if ($motivo !== null && mb_strlen($motivo) > self::MOTIVO_MAXIMO) {
            throw new \InvalidArgumentException('El motivo supera '.self::MOTIVO_MAXIMO.' caracteres.');
        }
        if ($tipo->exigeMotivo() && mb_strlen(trim($motivo ?? '')) < self::MOTIVO_MINIMO) {
            throw new \InvalidArgumentException("Un movimiento {$tipo->value} exige motivo de al menos ".self::MOTIVO_MINIMO.' caracteres.');
        }

        $requerido = $tipo->documentoRequerido();
        $documentoValido = $requerido === null
            ? $documentoTipo === null && $documentoId === null
            : $documentoTipo === $requerido && $documentoId !== null && $documentoId > 0;

        if (! $documentoValido) {
            throw new \InvalidArgumentException("Documento origen no válido para un movimiento {$tipo->value}.");
        }
    }

    /** Saldo con FOR UPDATE; debe coincidir con el que el llamador tiene en memoria. */
    private function saldoBloqueado(Existencia $existencia): int
    {
        if (! $existencia->exists) {
            throw new \LogicException('La existencia debe estar guardada antes de registrar movimientos.');
        }

        $saldo = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->value('cantidad');

        if (! is_numeric($saldo) || (int) $saldo !== $existencia->cantidad) {
            throw new \LogicException("El saldo de la existencia {$existencia->id} cambió o no se leyó bloqueado: reléala con lockForUpdate.");
        }

        return (int) $saldo;
    }
}
