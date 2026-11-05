<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\CantidadExcedeMaximo;
use App\Exceptions\Dominio\StockInsuficiente;
use App\Models\Existencia;
use App\Models\MovimientoKardex;
use Illuminate\Support\Facades\DB;

/**
 * RN-06-AC6: corrección de una existencia con un movimiento AJUSTE y motivo. Bloquea la existencia
 * dentro de la transacción y delega saldo, validación y escritura en RegistradorKardex.
 */
final class AjusteInventarioService
{
    public function __construct(private readonly RegistradorKardex $kardex) {}

    /**
     * @throws StockInsuficiente si el saldo quedaría negativo (409, sin cambios).
     * @throws CantidadExcedeMaximo si el saldo superaría el máximo (422, sin cambios).
     */
    public function registrar(DatosAjuste $datos): MovimientoKardex
    {
        $movimiento = DB::transaction(function () use ($datos): MovimientoKardex {
            $existencia = Existencia::query()
                ->whereKey($datos->existenciaId)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->kardex->registrar(
                TipoMovimiento::Ajuste,
                $existencia,
                $datos->cantidad,
                $datos->usuarioId,
                motivo: $datos->motivo,
            );
        });

        return $movimiento->load(ConsultaKardexService::RELACIONES);
    }
}
