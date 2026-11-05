<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Enums\EstadoDispensacion;
use App\Exceptions\Dominio\PrescripcionNoCorresponde;
use App\Exceptions\Dominio\StockInsuficiente;
use App\Models\Dispensacion;
use App\Models\DispensacionItem;
use App\Models\Existencia;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Services\Inventario\ConsultaKardexService;
use App\Support\Negocio;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dispensación (RN-02): crea la dispensación y descuenta stock FEFO (SalidaStock), o calcula la
 * asignación sin efectos (previsualizar, RN-02-AC6).
 *
 * Reglas que llegan en commits posteriores (puntos marcados con "RN-0x:" abajo):
 *  - RN-09 idempotencia (Idempotency-Key + request_hash; UNIQUE(dispensado_por_id, idempotency_key)).
 *  - RN-04 vigencia de la prescripción (S-05) y tope acumulado por ítem, con lockForUpdate de los
 *    prescripcion_items.
 *  - RN-05 control especial: PENDIENTE_AUTORIZACION sin mover stock (S-06).
 */
final class DispensacionService
{
    public function __construct(
        private readonly SalidaStock $salida,
        private readonly AsignadorFefo $asignador,
    ) {}

    /**
     * @throws PrescripcionNoCorresponde (422)
     * @throws StockInsuficiente (409, sin cambios)
     */
    public function crear(DatosDispensacion $datos): Dispensacion
    {
        $hoy = Negocio::hoy();

        $dispensacion = DB::transaction(function () use ($datos, $hoy): Dispensacion {
            // RN-09: aquí irá la idempotencia. La clave (Idempotency-Key) y el hash canónico del
            // cuerpo se guardan en esta misma inserción; la carrera entre reintentos la resuelve
            // UNIQUE(dispensado_por_id, idempotency_key), no un exists() previo (S-10, S-11).
            $prescritos = $this->itemsPrescritos($datos);

            // RN-04: aquí irá, con los prescripcion_items bloqueados (lockForUpdate, orden por id),
            // la vigencia (PRESCRIPCION_NO_VIGENTE, S-05) y el tope acumulado por ítem
            // (CANTIDAD_EXCEDE_PRESCRITO, sumando CONFIRMADA + PENDIENTE_AUTORIZACION).

            // RN-05: aquí irá requiereAutorizacion(items): si algún producto es de control especial,
            // la dispensación nace PENDIENTE_AUTORIZACION y NO se llama a SalidaStock (S-06).
            $dispensacion = new Dispensacion;
            $dispensacion->forceFill([
                'paciente_id' => $datos->pacienteId,
                'prescripcion_id' => $datos->prescripcionId,
                'bodega_id' => $datos->bodegaId,
                'dispensado_por_id' => $datos->usuarioId,
                'estado' => EstadoDispensacion::Confirmada,
            ])->save();

            foreach ($datos->items as $solicitado) {
                $prescrito = $prescritos[$solicitado->prescripcionItemId];

                $item = new DispensacionItem;
                $item->forceFill([
                    'dispensacion_id' => $dispensacion->id,
                    'prescripcion_id' => $prescrito->prescripcion_id,
                    'prescripcion_item_id' => $prescrito->id,
                    'producto_id' => $prescrito->producto_id,
                    'cantidad' => $solicitado->cantidad,
                ])->save();
            }

            $this->salida->descontar($dispensacion, $datos->usuarioId, $hoy);

            return $dispensacion;
        });

        return $this->cargar($dispensacion);
    }

    /**
     * RN-02-AC6: la misma asignación FEFO que haría crear(), sin bloquear ni escribir. Orientativa:
     * entre la previsualización y la dispensación otra operación puede cambiar el stock.
     *
     * @throws PrescripcionNoCorresponde (422)
     * @throws StockInsuficiente (409)
     */
    public function previsualizar(DatosDispensacion $datos): Previsualizacion
    {
        $hoy = Negocio::hoy();
        $prescritos = $this->itemsPrescritos($datos);

        // RN-04 / RN-05: cuando existan, la previsualización aplicará las mismas validaciones.
        $existencias = Existencia::query()
            ->where('bodega_id', $datos->bodegaId)
            ->whereIn('producto_id', array_values(array_unique(array_map(
                static fn (PrescripcionItem $p): int => $p->producto_id,
                $prescritos,
            ))))
            ->orderBy('id')
            ->with('lote:id,producto_id,codigo,fecha_vencimiento')
            ->get();

        $porProducto = $existencias->groupBy('producto_id');
        $items = [];

        foreach ($datos->items as $solicitado) {
            $prescrito = $prescritos[$solicitado->prescripcionItemId];

            try {
                $candidatas = $porProducto->get($prescrito->producto_id);
                $tramos = $this->asignador->asignar($candidatas?->all() ?? [], $solicitado->cantidad, $hoy);
            } catch (StockInsuficiente $e) {
                throw $e->conContexto([
                    'producto_id' => $prescrito->producto_id,
                    'bodega_id' => $datos->bodegaId,
                    'prescripcion_item_id' => $prescrito->id,
                ]);
            }

            $items[] = [
                'item' => $prescrito,
                'cantidad' => $solicitado->cantidad,
                'tramos' => $this->tramos($candidatas?->keyBy('lote_id')->all() ?? [], $tramos),
            ];
        }

        return new Previsualizacion($datos->bodegaId, $items);
    }

    /**
     * Ítems prescritos pedidos, por id. Comprueba que la prescripción es del paciente y que cada
     * ítem es de la prescripción: lo exigen también las FK compuestas de la BD, así que sin esta
     * comprobación el cliente recibiría un 500 en vez de 422 (RN-04-AC4).
     *
     * @return array<int, PrescripcionItem>
     *
     * @throws PrescripcionNoCorresponde
     */
    private function itemsPrescritos(DatosDispensacion $datos): array
    {
        $prescripcion = Prescripcion::query()->findOrFail($datos->prescripcionId);

        if ($prescripcion->paciente_id !== $datos->pacienteId) {
            throw PrescripcionNoCorresponde::paraPaciente($datos->prescripcionId, $datos->pacienteId);
        }

        $ids = array_map(static fn (ItemSolicitado $i): int => $i->prescripcionItemId, $datos->items);

        /** @var Collection<int, PrescripcionItem> $encontrados */
        $encontrados = PrescripcionItem::query()
            ->where('prescripcion_id', $prescripcion->id)
            ->whereIn('id', $ids)
            // Una sola consulta de productos para todos los ítems (la previsualización los muestra).
            ->with('producto:id,codigo,nombre,control_especial')
            ->get();

        $porId = $encontrados->keyBy('id')->all();
        $ajenos = array_values(array_diff($ids, array_keys($porId)));

        if ($ajenos !== []) {
            throw PrescripcionNoCorresponde::paraItems($prescripcion->id, $ajenos);
        }

        return $porId;
    }

    /**
     * @param  array<int, Existencia>  $porLote
     * @param  array<int, int>  $asignacion  lote_id => cantidad
     * @return list<array{existencia: Existencia, cantidad: int}>
     */
    private function tramos(array $porLote, array $asignacion): array
    {
        $tramos = [];
        foreach ($asignacion as $loteId => $cantidad) {
            $tramos[] = [
                'existencia' => $porLote[$loteId] ?? throw new \LogicException('Tramo FEFO sin existencia.'),
                'cantidad' => $cantidad,
            ];
        }

        return $tramos;
    }

    /** Relaciones que muestra DispensacionResource (columnas mínimas, sin N+1). */
    private function cargar(Dispensacion $dispensacion): Dispensacion
    {
        $kardex = [];
        foreach (ConsultaKardexService::RELACIONES as $relacion) {
            $kardex[] = 'detalles.movimiento.'.$relacion;
        }

        return $dispensacion->load([
            'items.producto:id,codigo,nombre,control_especial',
            'detalles.item:id,prescripcion_item_id',
            'detalles.lote:id,producto_id,codigo,fecha_vencimiento',
            ...$kardex,
        ]);
    }
}
