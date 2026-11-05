<?php

declare(strict_types=1);

namespace App\Services\Traslados;

use App\Enums\AccionTraslado;
use App\Enums\Habilidad;
use App\Enums\TrasladoEstado;
use App\Exceptions\Dominio\LoteVencido;
use App\Exceptions\Dominio\NoAutorizado;
use App\Exceptions\Dominio\TransicionInvalida;
use App\Models\Bodega;
use App\Models\Lote;
use App\Models\Traslado;
use App\Models\TrasladoItem;
use App\Models\User;
use App\Support\Negocio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RN-07: acciones del traslado que no mueven stock: crear (BORRADOR), editar ítems, solicitar,
 * aprobar y anular. Despachar y recibir: DespachoTraslado y RecepcionTraslado.
 *
 * Crear NO comprueba stock en el origen (decisión: el stock no se reserva y puede cambiar hasta el
 * despacho; se comprueba una sola vez, con las filas bloqueadas, al despachar, RN-07-AC10).
 *
 * Cada acción sobre un traslado existente: transacción → FOR UPDATE de la cabecera →
 * TrasladoEstado::puede() sobre la fila bloqueada (409 TRANSICION_INVALIDA) → validaciones de
 * negocio (422) → escritura con usuario y fecha de la acción.
 */
final class TrasladoService
{
    public const MENSAJE_BODEGA_NO_DISPONIBLE = 'La bodega no existe o está inactiva.';

    public const MENSAJE_BODEGAS_IGUALES = 'La bodega destino debe ser distinta de la de origen.';

    public const MENSAJE_LOTE_NO_CORRESPONDE = 'El lote no pertenece al producto.';

    public function __construct(private readonly BloqueoTraslado $bloqueo) {}

    /**
     * RN-07-AC1/AC9, RN-01-AC6: 201 en BORRADOR, sin stock ni kardex.
     *
     * @throws ValidationException (422 VALIDACION: bodega inactiva, lote de otro producto)
     * @throws LoteVencido (422, primer ítem con lote vencido en orden de la petición)
     */
    public function crear(DatosTraslado $datos): Traslado
    {
        if ($datos->bodegaOrigenId === $datos->bodegaDestinoId) {
            throw ValidationException::withMessages(['bodega_destino_id' => self::MENSAJE_BODEGAS_IGUALES]);
        }
        $this->exigirBodegasActivas($datos->bodegaOrigenId, $datos->bodegaDestinoId);
        $this->validarItems($datos->items, Negocio::hoy());

        $traslado = DB::transaction(function () use ($datos): Traslado {
            $traslado = new Traslado;
            $traslado->forceFill([
                'bodega_origen_id' => $datos->bodegaOrigenId,
                'bodega_destino_id' => $datos->bodegaDestinoId,
                'estado' => TrasladoEstado::inicial(),
                'observaciones' => $datos->observaciones,
                'creado_por_id' => $datos->usuarioId,
            ])->save();

            $this->guardarItems($traslado, $datos->items);

            return $traslado;
        });

        return $traslado->load(self::relacionesRespuesta());
    }

    /**
     * RN-07-AC9: reemplaza la lista de ítems; solo en BORRADOR (si no, 409 TRANSICION_INVALIDA).
     * S-63: solo el creador (la Policy ya lo exige; se repite aquí como en solicitar/anular).
     *
     * @param  list<ItemTraslado>  $items
     *
     * @throws NoAutorizado
     * @throws TransicionInvalida
     * @throws ValidationException
     * @throws LoteVencido
     */
    public function editarItems(Traslado $traslado, int $usuarioId, array $items): Traslado
    {
        if ($traslado->creado_por_id !== $usuarioId) {
            throw new NoAutorizado;
        }

        $editado = DB::transaction(function () use ($traslado, $items): Traslado {
            $bloqueado = $this->bloqueo->bloquear($traslado, AccionTraslado::EditarItems);
            $this->validarItems($items, Negocio::hoy());

            TrasladoItem::query()->where('traslado_id', $bloqueado->id)->delete();
            $this->guardarItems($bloqueado, $items);
            $bloqueado->touch();

            return $bloqueado;
        });

        return $editado->load(self::relacionesRespuesta());
    }

    /**
     * S-08 / RN-08-AC2: solo el creador solicita (la Policy ya lo exige; se repite aquí porque
     * es una regla que depende de otro usuario). creado_por_id no cambia nunca.
     *
     * @throws NoAutorizado
     * @throws TransicionInvalida
     */
    public function solicitar(Traslado $traslado, int $usuarioId): Traslado
    {
        if ($traslado->creado_por_id !== $usuarioId) {
            throw new NoAutorizado;
        }

        return $this->transicionar($traslado, AccionTraslado::Solicitar, [
            'solicitado_por_id' => $usuarioId,
            'solicitado_en' => now(),
        ]);
    }

    /**
     * Regente (Policy traslado.aprobar).
     *
     * @throws TransicionInvalida
     */
    public function aprobar(Traslado $traslado, int $usuarioId): Traslado
    {
        // RN-08: aquí irá la segregación (aprobador ≠ solicitante → 403 SEGREGACION_FUNCIONES),
        // comprobada antes del estado (403 antes que 409) y repetida sobre la fila bloqueada.
        return $this->transicionar($traslado, AccionTraslado::Aprobar, [
            'aprobado_por_id' => $usuarioId,
            'aprobado_en' => now(),
        ]);
    }

    /**
     * RN-07-AC8 / S-09: desde BORRADOR, SOLICITADO o APROBADO; sin kardex (aún no salió stock).
     * El creador o un regente (quien tiene traslado.aprobar); la Policy ya lo exige.
     *
     * @throws NoAutorizado
     * @throws TransicionInvalida
     */
    public function anular(Traslado $traslado, User $usuario, string $motivo): Traslado
    {
        if ($traslado->creado_por_id !== $usuario->id && ! $usuario->tieneHabilidad(Habilidad::TrasladoAprobar)) {
            throw new NoAutorizado;
        }

        return $this->transicionar($traslado, AccionTraslado::Anular, [
            'anulado_por_id' => $usuario->id,
            'anulado_en' => now(),
            'motivo_anulacion' => $motivo,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function relacionesRespuesta(): array
    {
        return [
            'bodegaOrigen:id,codigo,nombre',
            'bodegaDestino:id,codigo,nombre',
            'items.producto:id,codigo,nombre',
            'items.lote:id,producto_id,codigo,fecha_vencimiento',
            'items.discrepancia',
        ];
    }

    /**
     * @param  array<string, mixed>  $columnas
     *
     * @throws TransicionInvalida
     */
    private function transicionar(Traslado $traslado, AccionTraslado $accion, array $columnas): Traslado
    {
        $actualizado = DB::transaction(function () use ($traslado, $accion, $columnas): Traslado {
            $bloqueado = $this->bloqueo->bloquear($traslado, $accion);

            $bloqueado->forceFill(['estado' => $bloqueado->estado->tras($accion), ...$columnas])->save();

            return $bloqueado;
        });

        return $actualizado->load(self::relacionesRespuesta());
    }

    /**
     * @throws ValidationException
     */
    private function exigirBodegasActivas(int $origenId, int $destinoId): void
    {
        $activas = Bodega::query()->whereKey([$origenId, $destinoId])->where('activa', true)->pluck('id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

        $errores = [];
        foreach (['bodega_origen_id' => $origenId, 'bodega_destino_id' => $destinoId] as $campo => $id) {
            if (! in_array($id, $activas, true)) {
                $errores[$campo] = self::MENSAJE_BODEGA_NO_DISPONIBLE;
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * RN-07-AC9: cada lote es del producto del ítem (422 VALIDACION con todos los ítems que no
     * casan); después RN-01-AC6: ningún lote vencido (S-01; 422 LOTE_VENCIDO del primero en orden
     * de la petición, con su lote_id).
     *
     * @param  list<ItemTraslado>  $items
     *
     * @throws ValidationException
     * @throws LoteVencido
     */
    private function validarItems(array $items, CarbonInterface $hoy): void
    {
        $lotes = Lote::query()
            ->whereKey(array_map(static fn (ItemTraslado $item): int => $item->loteId, $items))
            ->get()
            ->keyBy('id');

        $errores = [];
        foreach ($items as $indice => $item) {
            if ($lotes->get($item->loteId)?->producto_id !== $item->productoId) {
                $errores["items.{$indice}.lote_id"] = self::MENSAJE_LOTE_NO_CORRESPONDE;
            }
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        foreach ($items as $item) {
            $lote = $lotes->get($item->loteId) ?? throw new \LogicException('Lote validado no encontrado.');
            if ($lote->estaVencido($hoy)) {
                throw LoteVencido::paraLote($lote);
            }
        }
    }

    /**
     * @param  list<ItemTraslado>  $items
     */
    private function guardarItems(Traslado $traslado, array $items): void
    {
        foreach ($items as $item) {
            $fila = new TrasladoItem;
            $fila->forceFill([
                'traslado_id' => $traslado->id,
                'producto_id' => $item->productoId,
                'lote_id' => $item->loteId,
                'cantidad' => $item->cantidad,
            ])->save();
        }
    }
}
