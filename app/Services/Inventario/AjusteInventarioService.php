<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\CantidadExcedeMaximo;
use App\Exceptions\Dominio\StockInsuficiente;
use App\Models\Existencia;
use App\Models\MovimientoKardex;
use App\Services\Auditoria\Bitacora;
use Illuminate\Support\Facades\DB;

/**
 * RN-06-AC6: corrección de una existencia con un movimiento AJUSTE y motivo. Bloquea la existencia
 * dentro de la transacción y delega saldo, validación y escritura en RegistradorKardex. Queda en la
 * bitácora de operaciones sensibles (RN-10-AC9) con el movimiento como entidad.
 */
final class AjusteInventarioService
{
    public function __construct(
        private readonly RegistradorKardex $kardex,
        private readonly Bitacora $bitacora,
    ) {}

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

            $movimiento = $this->kardex->registrar(
                TipoMovimiento::Ajuste,
                $existencia,
                $datos->cantidad,
                $datos->usuarioId,
                motivo: $datos->motivo,
            );

            // RN-10-AC9: operación sensible en la bitácora, en esta misma transacción.
            $this->bitacora->operacion($datos->usuarioId, AccionAuditoria::InventarioAjustar, EntidadAuditada::MovimientoKardex, $movimiento->id);

            return $movimiento;
        });

        return $movimiento->load(ConsultaKardexService::RELACIONES);
    }
}
