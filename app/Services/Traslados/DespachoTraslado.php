<?php

declare(strict_types=1);

namespace App\Services\Traslados;

use App\Enums\AccionTraslado;
use App\Enums\DocumentoOrigen;
use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\LoteVencido;
use App\Exceptions\Dominio\StockInsuficiente;
use App\Exceptions\Dominio\TransicionInvalida;
use App\Models\Existencia;
use App\Models\Traslado;
use App\Models\TrasladoItem;
use App\Services\Inventario\RegistradorKardex;
use App\Support\Negocio;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RN-07-AC4/AC10/AC11, RN-01-AC7: despacho APROBADO → EN_TRANSITO; el stock sale del origen.
 *
 * Orden de bloqueo (global, como S-56): traslado (FOR UPDATE) → existencias de origen de todos los
 * ítems (FOR UPDATE, id ascendente, una sola consulta). Dispensaciones, ajustes y recepciones
 * bloquean existencias también por id ascendente y nunca bloquean un traslado después de una
 * existencia, así que no hay ciclos.
 *
 * Todo o nada: se comprueban todos los lotes (vencido → 422 LOTE_VENCIDO) y todo el stock
 * (409 STOCK_INSUFICIENTE) sobre las filas bloqueadas ANTES de escribir; si algo falla el
 * traslado sigue APROBADO y no hay movimientos. Dos despachos simultáneos se serializan en el
 * bloqueo del traslado: el segundo ve EN_TRANSITO y responde 409 TRANSICION_INVALIDA (AC11).
 */
final class DespachoTraslado
{
    public function __construct(
        private readonly BloqueoTraslado $bloqueo,
        private readonly RegistradorKardex $kardex,
    ) {}

    /**
     * @throws TransicionInvalida
     * @throws LoteVencido
     * @throws StockInsuficiente
     */
    public function despachar(Traslado $traslado, int $usuarioId): Traslado
    {
        $hoy = Negocio::hoy();

        $despachado = DB::transaction(function () use ($traslado, $usuarioId, $hoy): Traslado {
            $bloqueado = $this->bloqueo->bloquear($traslado, AccionTraslado::Despachar);
            $items = $bloqueado->items()->with('lote')->get();

            $existencias = $this->existenciasOrigenBloqueadas($bloqueado, $items);

            // RN-01-AC7: un lote que venció después de aprobarse no sale (S-01).
            foreach ($items as $item) {
                if ($item->lote->estaVencido($hoy)) {
                    throw LoteVencido::paraLote($item->lote);
                }
            }

            foreach ($items as $item) {
                $existencia = $existencias->get($item->lote_id);
                $disponible = $existencia instanceof Existencia ? $existencia->cantidad : 0;
                if ($disponible < $item->cantidad) {
                    throw StockInsuficiente::paraItemTraslado($item->id, $item->lote_id, $existencia?->id, $disponible, $item->cantidad);
                }
            }

            foreach ($items as $item) {
                $existencia = $existencias->get($item->lote_id) ?? throw new \LogicException('Existencia de origen no bloqueada.');
                $this->kardex->registrar(
                    TipoMovimiento::SalidaTraslado,
                    $existencia,
                    -$item->cantidad,
                    $usuarioId,
                    documentoTipo: DocumentoOrigen::Traslado,
                    documentoId: $bloqueado->id,
                );
            }

            $bloqueado->forceFill([
                'estado' => $bloqueado->estado->tras(AccionTraslado::Despachar),
                'despachado_por_id' => $usuarioId,
                'despachado_en' => now(),
            ])->save();

            return $bloqueado;
        });

        return $despachado->load(TrasladoService::relacionesRespuesta());
    }

    /**
     * Existencias de la bodega origen de los lotes del traslado, con FOR UPDATE en orden de id,
     * indexadas por lote_id (un lote aparece una vez por traslado).
     *
     * @param  Collection<int, TrasladoItem>  $items
     * @return \Illuminate\Support\Collection<int, Existencia>
     */
    private function existenciasOrigenBloqueadas(Traslado $traslado, Collection $items): \Illuminate\Support\Collection
    {
        $existencias = Existencia::query()
            ->where('bodega_id', $traslado->bodega_origen_id)
            ->whereIn('lote_id', $items->pluck('lote_id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var \Illuminate\Support\Collection<int, Existencia> */
        return $existencias->keyBy('lote_id')->toBase();
    }
}
