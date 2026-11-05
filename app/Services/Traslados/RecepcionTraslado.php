<?php

declare(strict_types=1);

namespace App\Services\Traslados;

use App\Enums\AccionTraslado;
use App\Enums\DocumentoOrigen;
use App\Enums\EstadoDiscrepancia;
use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\CantidadExcedeMaximo;
use App\Exceptions\Dominio\CantidadRecibidaExcede;
use App\Exceptions\Dominio\TransicionInvalida;
use App\Models\Discrepancia;
use App\Models\Existencia;
use App\Models\Traslado;
use App\Models\TrasladoItem;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RN-07-AC5/AC6/AC7, S-12: recepción EN_TRANSITO → RECIBIDO | RECIBIDO_PARCIAL. Entra al destino,
 * en el mismo lote, lo recibido (> 0) de cada ítem con ENTRADA_TRASLADO; lo que falte queda como
 * discrepancia PENDIENTE (recibir 0 → discrepancia por el total). Un lote que venció en tránsito
 * se acepta (S-12): entra, pero no será dispensable.
 *
 * Orden de bloqueo: traslado (FOR UPDATE) → creación de las existencias de destino que falten
 * (INSERT … ON CONFLICT DO NOTHING, en orden de lote_id) → FOR UPDATE de todas las existencias de
 * destino en orden de id. El INSERT puede esperar a otra transacción que inserte la misma terna
 * sin confirmar (otra recepción o una entrada); esa otra transacción no está esperando nada que
 * tengamos nosotros todavía (aún no hemos bloqueado existencias con FOR UPDATE, y dos recepciones
 * insertan en el mismo orden de lote_id), así que no se forma un ciclo. Si PostgreSQL detectara
 * uno igualmente, la transacción entera se revierte y responde 409 CONFLICTO_CONCURRENCIA (S-48).
 */
final class RecepcionTraslado
{
    public function __construct(
        private readonly BloqueoTraslado $bloqueo,
        private readonly RegistradorKardex $kardex,
    ) {}

    /**
     * @param  list<ItemRecibido>  $recibidos
     *
     * @throws TransicionInvalida
     * @throws ValidationException (falta un ítem o sobra uno ajeno al traslado)
     * @throws CantidadRecibidaExcede
     * @throws CantidadExcedeMaximo
     */
    public function recibir(Traslado $traslado, int $usuarioId, array $recibidos): Traslado
    {
        $recibido = DB::transaction(function () use ($traslado, $usuarioId, $recibidos): Traslado {
            $bloqueado = $this->bloqueo->bloquear($traslado, AccionTraslado::Recibir);
            $items = $bloqueado->items()->get();

            $cantidades = self::cantidadesPorItem($items, $recibidos);

            $faltantes = [];
            foreach ($items as $item) {
                $faltantes[$item->id] = CalculadorRecepcion::faltante($item->id, $item->cantidad, $cantidades[$item->id]);
            }

            $existencias = $this->existenciasDestinoBloqueadas(
                $bloqueado,
                $items->filter(fn (TrasladoItem $item): bool => $cantidades[$item->id] > 0),
            );

            foreach ($items as $item) {
                $cantidad = $cantidades[$item->id];

                if ($cantidad > 0) {
                    $existencia = $existencias->get($item->lote_id) ?? throw new \LogicException('Existencia de destino no bloqueada.');
                    $this->kardex->registrar(
                        TipoMovimiento::EntradaTraslado,
                        $existencia,
                        $cantidad,
                        $usuarioId,
                        documentoTipo: DocumentoOrigen::Traslado,
                        documentoId: $bloqueado->id,
                    );
                }

                $item->forceFill(['cantidad_recibida' => $cantidad])->save();

                if ($faltantes[$item->id] > 0) {
                    $discrepancia = new Discrepancia;
                    $discrepancia->forceFill([
                        'traslado_item_id' => $item->id,
                        'cantidad' => $faltantes[$item->id],
                        'estado' => EstadoDiscrepancia::Pendiente,
                    ])->save();
                }
            }

            $bloqueado->forceFill([
                'estado' => CalculadorRecepcion::estadoResultante(array_values($faltantes)),
                'recibido_por_id' => $usuarioId,
                'recibido_en' => now(),
            ])->save();

            return $bloqueado;
        });

        return $recibido->load(TrasladoService::relacionesRespuesta());
    }

    /**
     * RN-07-AC7: la recepción trae TODOS los ítems del traslado y ninguno ajeno (422 VALIDACION).
     * El Form Request ya lo comprueba; se repite con los ítems leídos tras el bloqueo.
     *
     * @param  Collection<int, TrasladoItem>  $items
     * @param  list<ItemRecibido>  $recibidos
     * @return array<int, int> traslado_item_id => cantidad recibida
     *
     * @throws ValidationException
     */
    public static function cantidadesPorItem(Collection $items, array $recibidos): array
    {
        $cantidades = [];
        foreach ($recibidos as $recibido) {
            $cantidades[$recibido->trasladoItemId] = $recibido->cantidadRecibida;
        }

        $esperados = $items->modelKeys();
        $faltan = array_values(array_diff($esperados, array_keys($cantidades)));
        $sobran = array_values(array_diff(array_keys($cantidades), $esperados));

        if ($faltan !== [] || $sobran !== []) {
            $errores = [];
            if ($faltan !== []) {
                $errores['items'] = 'Faltan ítems del traslado en la recepción: '.implode(', ', $faltan).'.';
            }
            if ($sobran !== []) {
                $errores['items.traslado_item_id'] = 'Ítems que no son de este traslado: '.implode(', ', $sobran).'.';
            }

            throw ValidationException::withMessages($errores);
        }

        return $cantidades;
    }

    /**
     * Crea (si faltan) y bloquea las existencias de destino de los lotes recibidos.
     *
     * @param  Collection<int, TrasladoItem>  $items
     * @return \Illuminate\Support\Collection<int, Existencia> indexadas por lote_id
     */
    private function existenciasDestinoBloqueadas(Traslado $traslado, Collection $items): \Illuminate\Support\Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        $ahora = now();
        $filas = $items->sortBy('lote_id')->map(fn (TrasladoItem $item): array => [
            'bodega_id' => $traslado->bodega_destino_id,
            'producto_id' => $item->producto_id,
            'lote_id' => $item->lote_id,
            'cantidad' => 0,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ])->values()->all();

        Existencia::query()->insertOrIgnore($filas);

        $existencias = Existencia::query()
            ->where('bodega_id', $traslado->bodega_destino_id)
            ->whereIn('lote_id', $items->pluck('lote_id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var \Illuminate\Support\Collection<int, Existencia> */
        return $existencias->keyBy('lote_id')->toBase();
    }
}
