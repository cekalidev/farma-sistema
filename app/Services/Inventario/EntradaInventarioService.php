<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Exceptions\Dominio\CantidadExcedeMaximo;
use App\Exceptions\Dominio\LoteNoCoincide;
use App\Exceptions\Dominio\LoteVencido;
use App\Models\Existencia;
use App\Models\Lote;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * RN-01-AC8 / S-17: entrada de stock (compra) en bodega + producto + lote.
 *
 * Todo ocurre en una sola transacción: resolver/crear el lote, crear la existencia si no
 * existe, bloquearla (lockForUpdate) y sumar la cantidad sobre el valor bloqueado.
 */
final class EntradaInventarioService
{
    /** Máximo de la columna `existencias.cantidad` (integer de PostgreSQL). */
    public const SALDO_MAXIMO = 2_147_483_647;

    /**
     * @throws LoteNoCoincide si el lote ya existe para el producto con otra fecha de vencimiento
     *                        (S-23; tiene prioridad sobre LOTE_VENCIDO).
     * @throws LoteVencido si el lote (existente o el que se crearía) vence <= hoy en Bogotá (S-01).
     * @throws CantidadExcedeMaximo si el saldo resultante superaría SALDO_MAXIMO.
     */
    public function registrar(DatosEntrada $datos): ResultadoEntrada
    {
        $hoy = now();

        // Lote nombrado por código: se rechaza antes de abrir la transacción. Si ya existe, manda
        // su fecha guardada (discrepancia → LOTE_NO_COINCIDE); si no, la fecha enviada.
        if ($datos->loteCodigo !== null && $datos->fechaVencimiento !== null) {
            $this->validarLotePorCodigo($this->loteExistente($datos), $datos, $hoy);
        }

        $resultado = DB::transaction(function () use ($datos, $hoy): ResultadoEntrada {
            [$lote, $loteCreado] = $this->resolverLote($datos, $hoy);

            $existencia = $this->existenciaBloqueada($datos->bodegaId, $datos->productoId, $lote->id);

            $saldoAnterior = $existencia->cantidad;
            // Sobre el valor bloqueado: si se desborda, la excepción revierte la transacción
            // (incluido el lote o la existencia en 0 creados en esta petición).
            if ($datos->cantidad > self::SALDO_MAXIMO - $saldoAnterior) {
                throw CantidadExcedeMaximo::paraSaldo($saldoAnterior, $datos->cantidad, self::SALDO_MAXIMO);
            }

            $saldoPosterior = $saldoAnterior + $datos->cantidad;
            $existencia->forceFill(['cantidad' => $saldoPosterior])->save();

            // RN-06: aquí se registrará el movimiento de kardex ENTRADA, en esta misma transacción
            // y con la existencia aún bloqueada:
            //   $this->kardex->registrar(TipoMovimiento::Entrada, $existencia, +$datos->cantidad,
            //       $saldoAnterior, $saldoPosterior, $datos->usuarioId, referencia: …);
            // Si falla, la transacción revierte también el cambio de stock (RN-06-AC7).

            $existencia->setRelation('lote', $lote);

            return new ResultadoEntrada($existencia, $datos->cantidad, $saldoAnterior, $saldoPosterior, $loteCreado);
        });

        // Misma forma que cada fila de GET /inventario; se carga tras liberar el bloqueo.
        $resultado->existencia->load(['bodega:id,codigo,nombre', 'producto:id,codigo,nombre']);

        return $resultado;
    }

    /**
     * Orden de las comprobaciones de un lote nombrado por código:
     *  1. existe con otra fecha → LOTE_NO_COINCIDE (aunque la enviada esté vencida, S-23);
     *  2. el lote (existente, o el candidato si no existe) está vencido → LOTE_VENCIDO con sus datos.
     */
    private function validarLotePorCodigo(?Lote $existente, DatosEntrada $datos, CarbonInterface $hoy): void
    {
        if ($existente !== null && $existente->fecha_vencimiento->toDateString() !== $datos->fechaVencimiento) {
            throw LoteNoCoincide::porFecha($existente, (string) $datos->fechaVencimiento);
        }

        $lote = $existente ?? new Lote([
            'producto_id' => $datos->productoId,
            'codigo' => $datos->loteCodigo,
            'fecha_vencimiento' => $datos->fechaVencimiento,
        ]);

        if ($lote->estaVencido($hoy)) {
            throw LoteVencido::paraLote($lote);
        }
    }

    /**
     * Lote por id (validado como del producto) o por código del producto; si no existe por
     * código, se crea de forma segura ante concurrencia (INSERT … ON CONFLICT DO NOTHING sobre
     * UNIQUE(producto_id, codigo) y relectura).
     *
     * @return array{0: Lote, 1: bool} [lote, se creó en esta petición]
     */
    private function resolverLote(DatosEntrada $datos, CarbonInterface $hoy): array
    {
        if ($datos->loteId !== null) {
            $lote = Lote::query()
                ->whereKey($datos->loteId)
                ->where('producto_id', $datos->productoId)
                ->firstOrFail();

            if ($lote->estaVencido($hoy)) {
                throw LoteVencido::paraLote($lote);
            }

            return [$lote, false];
        }

        $creado = Lote::query()->insertOrIgnore([
            'producto_id' => $datos->productoId,
            'codigo' => $datos->loteCodigo,
            'fecha_vencimiento' => $datos->fechaVencimiento,
            'created_at' => $hoy,
            'updated_at' => $hoy,
        ]) === 1;

        $lote = $this->loteExistente($datos);
        if ($lote === null) {
            // No puede ocurrir: o se insertó o ya existía con ese (producto_id, codigo).
            throw new \LogicException('Lote no encontrado tras insertarlo.');
        }

        // Repetido dentro de la transacción: otra petición pudo crear el lote entre la
        // comprobación previa y el INSERT; la regla se evalúa siempre sobre el lote guardado.
        $this->validarLotePorCodigo($lote, $datos, $hoy);

        return [$lote, $creado];
    }

    private function loteExistente(DatosEntrada $datos): ?Lote
    {
        if ($datos->loteCodigo === null) {
            return null;
        }

        return Lote::query()
            ->where('producto_id', $datos->productoId)
            ->where('codigo', $datos->loteCodigo)
            ->first();
    }

    /**
     * Crea la existencia si no existe (ON CONFLICT DO NOTHING sobre la terna única, RN-01-AC1) y
     * la relee con FOR UPDATE: dos entradas simultáneas a la misma terna se serializan aquí.
     */
    private function existenciaBloqueada(int $bodegaId, int $productoId, int $loteId): Existencia
    {
        $ahora = now();

        Existencia::query()->insertOrIgnore([
            'bodega_id' => $bodegaId,
            'producto_id' => $productoId,
            'lote_id' => $loteId,
            'cantidad' => 0,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        return Existencia::query()
            ->where('bodega_id', $bodegaId)
            ->where('producto_id', $productoId)
            ->where('lote_id', $loteId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
