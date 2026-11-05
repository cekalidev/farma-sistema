<?php

declare(strict_types=1);

namespace App\Services\Traslados;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Enums\EstadoDiscrepancia;
use App\Enums\TipoMovimiento;
use App\Enums\TipoResolucionDiscrepancia;
use App\Exceptions\Dominio\CantidadExcedeMaximo;
use App\Exceptions\Dominio\SegregacionFunciones;
use App\Exceptions\Dominio\TransicionInvalida;
use App\Models\Discrepancia;
use App\Models\Existencia;
use App\Models\TrasladoItem;
use App\Services\Auditoria\Bitacora;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Support\Facades\DB;

/**
 * RN-07-AC12: un regente resuelve una discrepancia PENDIENTE una sola vez.
 *  - PERDIDA: sin stock ni kardex.
 *  - REINTEGRO_ORIGEN / ENTREGA_DESTINO: AJUSTE positivo por la cantidad de la discrepancia en la
 *    existencia del mismo lote en la bodega origen / destino (se crea si no existe). S-35: el
 *    AJUSTE no lleva documento origen; la referencia al traslado va en el motivo del kardex, y la
 *    discrepancia guarda el id del movimiento. Se admite aunque el lote esté vencido o la bodega
 *    inactiva (S-34: es una corrección).
 *
 * S-71 (segregación de RN-08): quien solicitó el traslado no resuelve sus discrepancias → 403
 * SEGREGACION_FUNCIONES, antes del 409 del estado (S-57). La Policy ya lo exige; se repite aquí
 * con la discrepancia bloqueada. Sin CHECK en la BD: cruza discrepancias y traslados.
 *
 * Orden de bloqueo: discrepancia (FOR UPDATE; dos resoluciones simultáneas se serializan aquí y la
 * segunda responde 409 TRANSICION_INVALIDA) → existencia (INSERT ON CONFLICT DO NOTHING + FOR
 * UPDATE). El traslado no se bloquea: está en RECIBIDO_PARCIAL, que es final.
 */
final class ResolucionDiscrepancia
{
    /**
     * Espacio que deja el prefijo del motivo de kardex ("Discrepancia N del traslado M (TIPO): ")
     * dentro de RegistradorKardex::MOTIVO_MAXIMO: el motivo del usuario admite hasta este largo.
     */
    public const MOTIVO_MAXIMO = 400;

    public function __construct(
        private readonly RegistradorKardex $kardex,
        private readonly Bitacora $bitacora,
    ) {}

    /**
     * @throws SegregacionFunciones
     * @throws TransicionInvalida
     * @throws CantidadExcedeMaximo
     */
    public function resolver(Discrepancia $discrepancia, int $usuarioId, TipoResolucionDiscrepancia $tipo, string $motivo): Discrepancia
    {
        $resuelta = DB::transaction(function () use ($discrepancia, $usuarioId, $tipo, $motivo): Discrepancia {
            $bloqueada = Discrepancia::query()->whereKey($discrepancia->id)->lockForUpdate()->firstOrFail();

            $item = TrasladoItem::query()->with('traslado')->findOrFail($bloqueada->traslado_item_id);

            if ($item->traslado->solicitado_por_id === $usuarioId) {
                throw SegregacionFunciones::paraTraslado($item->traslado_id, SegregacionFunciones::ACCION_RESOLVER_DISCREPANCIA);
            }

            if ($bloqueada->estado !== EstadoDiscrepancia::Pendiente) {
                throw TransicionInvalida::paraDiscrepancia($bloqueada);
            }

            $movimientoId = null;
            if ($tipo !== TipoResolucionDiscrepancia::Perdida) {
                $bodegaId = $tipo === TipoResolucionDiscrepancia::ReintegroOrigen
                    ? $item->traslado->bodega_origen_id
                    : $item->traslado->bodega_destino_id;

                $movimientoId = $this->kardex->registrar(
                    TipoMovimiento::Ajuste,
                    $this->existenciaBloqueada($bodegaId, $item),
                    $bloqueada->cantidad,
                    $usuarioId,
                    motivo: self::motivoKardex($bloqueada, $item, $tipo, $motivo),
                )->id;
            }

            $bloqueada->forceFill([
                'estado' => EstadoDiscrepancia::Resuelta,
                'tipo_resolucion' => $tipo,
                'motivo' => $motivo,
                'resuelto_por_id' => $usuarioId,
                'resuelto_en' => now(),
                'movimiento_kardex_id' => $movimientoId,
            ])->save();

            // RN-10-AC9: operación sensible en la bitácora, en esta misma transacción.
            $this->bitacora->operacion($usuarioId, AccionAuditoria::DiscrepanciaResolver, EntidadAuditada::Discrepancia, $bloqueada->id);

            return $bloqueada;
        });

        return $resuelta->load('item');
    }

    public static function motivoKardex(Discrepancia $discrepancia, TrasladoItem $item, TipoResolucionDiscrepancia $tipo, string $motivo): string
    {
        return "Discrepancia {$discrepancia->id} del traslado {$item->traslado_id} ({$tipo->value}): {$motivo}";
    }

    private function existenciaBloqueada(int $bodegaId, TrasladoItem $item): Existencia
    {
        $ahora = now();

        Existencia::query()->insertOrIgnore([
            'bodega_id' => $bodegaId,
            'producto_id' => $item->producto_id,
            'lote_id' => $item->lote_id,
            'cantidad' => 0,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        return Existencia::query()
            ->where('bodega_id', $bodegaId)
            ->where('producto_id', $item->producto_id)
            ->where('lote_id', $item->lote_id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
