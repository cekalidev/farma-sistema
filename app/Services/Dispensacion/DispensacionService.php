<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Enums\EstadoDispensacion;
use App\Exceptions\Dominio\CantidadExcedePrescrito;
use App\Exceptions\Dominio\IdempotencyKeyReutilizada;
use App\Exceptions\Dominio\PrescripcionNoCorresponde;
use App\Exceptions\Dominio\PrescripcionNoVigente;
use App\Exceptions\Dominio\StockInsuficiente;
use App\Exceptions\ErroresBaseDatos;
use App\Models\Bodega;
use App\Models\Dispensacion;
use App\Models\DispensacionItem;
use App\Models\Existencia;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Services\Inventario\ConsultaKardexService;
use App\Support\Negocio;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dispensación (RN-02): crea la dispensación y descuenta stock FEFO (SalidaStock), o calcula la
 * asignación sin efectos (previsualizar, RN-02-AC6).
 *
 * RN-09 idempotencia: la creación guarda, en la misma transacción, la Idempotency-Key, el hash
 * canónico del cuerpo y la respuesta enviada; un reintento del mismo usuario con la misma clave
 * la reproduce (mismo hash) o responde 422 IDEMPOTENCY_KEY_REUTILIZADA (otro hash). Ver crear().
 *
 * RN-04 precedencia de errores de negocio (crear y previsualizar, tras la validación de forma y
 * S-46): PRESCRIPCION_NO_CORRESPONDE → PRESCRIPCION_NO_VIGENTE → CANTIDAD_EXCEDE_PRESCRITO (422)
 * → STOCK_INSUFICIENTE (409). El tope va antes que el stock: pedir más de lo prescrito es un error
 * de la solicitud que no se arregla reponiendo stock.
 *
 * Orden global de bloqueo (evita interbloqueos; RN-05 al autorizar debe respetarlo):
 *  1. índice único de idempotencia (INSERT de la cabecera, sin otras filas bloqueadas, RN-09-AC6);
 *  2. prescripcion_items afectados, FOR UPDATE por id ascendente (tope, RN-04-AC8);
 *  3. existencias de la bodega, FOR UPDATE por id ascendente (SalidaStock, RN-03).
 *
 * RN-05 (S-06): si algún producto es de control especial (ControlEspecial::requiereAutorizacion)
 * la dispensación nace PENDIENTE_AUTORIZACION con sus dispensacion_items (cuentan para el tope,
 * RN-04-AC9) pero sin SalidaStock ni kardex; solo se comprueba que el stock vigente alcanza en
 * ese momento (S-61, SalidaStock::comprobar, sin reservar). La salida FEFO la hace
 * AutorizacionDispensacion al autorizar. La respuesta 201 (estado pendiente) se guarda igual para la idempotencia (S-52).
 */
final class DispensacionService
{
    /** Índice único (dispensado_por_id, idempotency_key) de create_dispensaciones_table. */
    public const INDICE_IDEMPOTENCIA = 'dispensaciones_usuario_idempotency_unique';

    /** Mismo texto para bodega inexistente (Form Request) e inactiva (aquí), S-46. */
    public const MENSAJE_BODEGA_NO_DISPONIBLE = 'La bodega no existe o está inactiva.';

    public function __construct(
        private readonly SalidaStock $salida,
        private readonly AsignadorFefo $asignador,
        private readonly CalculadorPendiente $pendiente,
    ) {}

    /**
     * Crea la dispensación (RN-02) de forma idempotente (RN-09) y devuelve la respuesta a enviar.
     *
     * Orden (RN-09-AC6): 1) si el usuario ya tiene una dispensación con esa clave, se resuelve sin
     * transacción (reproducir o 422) y sin revalidar prescripción ni stock, para que un reintento
     * no cambie de resultado aunque el estado haya cambiado (AC8). 2) Si no, en una transacción se
     * inserta la cabecera con la clave ANTES de bloquear existencias: un reintento simultáneo
     * espera en el índice único dispensaciones_usuario_idempotency_unique sin tener bloqueado
     * nada, y cuando la primera confirma recibe 23505; su transacción (o savepoint) se revierte
     * entera y se lee la ganadora fuera de ella. Si la primera se revierte (409/422), la segunda
     * inserta y se procesa de nuevo (los fallos no se guardan, S-11, AC7).
     *
     * @param  Closure(Dispensacion): RespuestaIdempotente  $responder  respuesta de la creación
     *                                                                  (se guarda tal cual)
     *
     * @throws IdempotencyKeyReutilizada (422, sin cambios)
     * @throws ValidationException (422 VALIDACION, bodega inactiva en una dispensación nueva, S-46)
     * @throws PrescripcionNoCorresponde (422)
     * @throws PrescripcionNoVigente (422, sin cambios)
     * @throws CantidadExcedePrescrito (422, sin cambios)
     * @throws StockInsuficiente (409, sin cambios)
     */
    public function crear(DatosDispensacion $datos, ClaveIdempotencia $clave, Closure $responder): RespuestaIdempotente
    {
        $previa = $this->porClave($datos->usuarioId, $clave->clave);

        if ($previa !== null) {
            return $this->reproducir($previa, $clave);
        }

        // S-46 solo para una dispensación nueva: el reintento ya se resolvió arriba (S-52).
        self::exigirBodegaActiva($datos->bodegaId);

        $hoy = Negocio::hoy();

        try {
            return DB::transaction(function () use ($datos, $clave, $responder, $hoy): RespuestaIdempotente {
                // Solo lecturas sin bloqueo: valida la correspondencia antes de insertar (las FK
                // compuestas darían 500 en vez de 422) y la vigencia (S-05).
                $prescritos = $this->itemsPrescritos($datos, $hoy);

                // RN-05 (S-06): con algún producto de control especial la dispensación entera nace
                // PENDIENTE_AUTORIZACION y no mueve stock hasta que la autorice otro regente.
                $requiereAutorizacion = ControlEspecial::requiereAutorizacion(array_map(
                    static fn (PrescripcionItem $p): Producto => $p->producto,
                    array_values($prescritos),
                ));

                // Idempotencia (RN-09-AC6): primera escritura, antes de cualquier bloqueo.
                $dispensacion = new Dispensacion;
                $dispensacion->forceFill([
                    'paciente_id' => $datos->pacienteId,
                    'prescripcion_id' => $datos->prescripcionId,
                    'bodega_id' => $datos->bodegaId,
                    'dispensado_por_id' => $datos->usuarioId,
                    'estado' => $requiereAutorizacion
                        ? EstadoDispensacion::PendienteAutorizacion
                        : EstadoDispensacion::Confirmada,
                    'idempotency_key' => $clave->clave,
                    'request_hash' => $clave->hash,
                ])->save();

                // RN-04-AC8 tope acumulado: bloquea los ítems prescritos (paso 2 del orden global,
                // por id) DESPUÉS de la cabecera (RN-09-AC6) y ANTES de insertar dispensacion_items
                // y de bloquear existencias. Dos dispensaciones simultáneas del mismo ítem se
                // serializan aquí; la segunda suma lo que confirmó la primera.
                /** @var array<int, PrescripcionItem> $bloqueados */
                $bloqueados = PrescripcionItem::query()
                    ->whereKey(array_keys($prescritos))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id')
                    ->all();
                $this->pendiente->exigirDentroDelTope($datos->items, $bloqueados);

                foreach ($datos->items as $solicitado) {
                    $prescrito = $bloqueados[$solicitado->prescripcionItemId];

                    $item = new DispensacionItem;
                    $item->forceFill([
                        'dispensacion_id' => $dispensacion->id,
                        'prescripcion_id' => $prescrito->prescripcion_id,
                        'prescripcion_item_id' => $prescrito->id,
                        'producto_id' => $prescrito->producto_id,
                        'cantidad' => $solicitado->cantidad,
                    ])->save();
                }

                // RN-05-AC2: la pendiente no descuenta stock ni escribe kardex (sin reserva, S-06),
                // pero S-61 exige que el stock vigente alcance ahora (409 y rollback si no; la clave
                // queda libre, S-11). Va tras el tope (S-54) y lee sin bloquear existencias.
                if ($requiereAutorizacion) {
                    $this->salida->comprobar($dispensacion, $hoy);
                } else {
                    $this->salida->descontar($dispensacion, $datos->usuarioId, $hoy);
                }

                // La respuesta se guarda en la misma transacción: nunca hay una dispensación con
                // clave confirmada cuya respuesta no se pueda reproducir.
                $respuesta = $responder($dispensacion->load(self::relacionesRespuesta()));
                $dispensacion->forceFill([
                    'respuesta_status' => $respuesta->status,
                    'respuesta_cuerpo' => $respuesta->cuerpo,
                ])->save();

                return $respuesta;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! ErroresBaseDatos::violaIndiceUnico($e, self::INDICE_IDEMPOTENCIA)) {
                throw $e;
            }

            return $this->reproducir($this->porClave($datos->usuarioId, $clave->clave) ?? throw $e, $clave);
        }
    }

    /**
     * RN-02-AC6: la misma asignación FEFO que haría crear(), sin bloquear ni escribir. Orientativa:
     * entre la previsualización y la dispensación otra operación puede cambiar el stock.
     *
     * @throws ValidationException (422 VALIDACION, bodega inactiva, S-46)
     * @throws PrescripcionNoCorresponde (422)
     * @throws PrescripcionNoVigente (422)
     * @throws CantidadExcedePrescrito (422)
     * @throws StockInsuficiente (409)
     */
    public function previsualizar(DatosDispensacion $datos): Previsualizacion
    {
        self::exigirBodegaActiva($datos->bodegaId);

        $hoy = Negocio::hoy();
        $prescritos = $this->itemsPrescritos($datos, $hoy);

        // RN-04: mismo tope que crear(), sin bloquear (orientativo, S-43).
        $this->pendiente->exigirDentroDelTope($datos->items, $prescritos);
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
     * comprobación el cliente recibiría un 500 en vez de 422 (RN-04-AC4). Después, la vigencia
     * (RN-04-AC2/AC3, S-05): la correspondencia va primero para no informar del estado de una
     * prescripción ajena.
     *
     * @return array<int, PrescripcionItem>
     *
     * @throws PrescripcionNoCorresponde
     * @throws PrescripcionNoVigente
     */
    private function itemsPrescritos(DatosDispensacion $datos, CarbonInterface $hoy): array
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

        if (! $prescripcion->estaVigente($hoy)) {
            throw PrescripcionNoVigente::para($prescripcion);
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

    /**
     * S-46: no se dispensa (ni se previsualiza) desde una bodega inactiva → 422 VALIDACION con
     * bodega_id en detalles.campos, el mismo formato que un fallo del Form Request. Que exista ya
     * lo validó el Form Request. También la usa AutorizacionDispensacion al autorizar una pendiente.
     *
     * @throws ValidationException
     */
    public static function exigirBodegaActiva(int $bodegaId): void
    {
        if (! Bodega::query()->whereKey($bodegaId)->where('activa', true)->exists()) {
            throw ValidationException::withMessages(['bodega_id' => self::MENSAJE_BODEGA_NO_DISPONIBLE]);
        }
    }

    private function porClave(int $usuarioId, string $clave): ?Dispensacion
    {
        return Dispensacion::query()
            ->where('dispensado_por_id', $usuarioId)
            ->where('idempotency_key', $clave)
            ->first();
    }

    /**
     * RN-09-AC1/AC8: la respuesta original, tal cual se envió (aunque la dispensación cambiara
     * después). Otro cuerpo con la misma clave → 422 (AC2, S-10).
     *
     * @throws IdempotencyKeyReutilizada
     */
    private function reproducir(Dispensacion $previa, ClaveIdempotencia $clave): RespuestaIdempotente
    {
        if (! hash_equals((string) $previa->request_hash, $clave->hash)) {
            throw new IdempotencyKeyReutilizada;
        }

        if ($previa->respuesta_status === null || $previa->respuesta_cuerpo === null) {
            throw new \LogicException("La dispensación {$previa->id} tiene Idempotency-Key pero no respuesta guardada.");
        }

        return new RespuestaIdempotente($previa->respuesta_status, $previa->respuesta_cuerpo, reproducida: true);
    }

    /**
     * Relaciones que muestra DispensacionResource (columnas mínimas, sin N+1). También las usa
     * AutorizacionDispensacion para responder al autorizar o rechazar.
     *
     * @return list<string>
     */
    public static function relacionesRespuesta(): array
    {
        $kardex = [];
        foreach (ConsultaKardexService::RELACIONES as $relacion) {
            $kardex[] = 'detalles.movimiento.'.$relacion;
        }

        return [
            'items.producto:id,codigo,nombre,control_especial',
            'detalles.item:id,prescripcion_item_id',
            'detalles.lote:id,producto_id,codigo,fecha_vencimiento',
            ...$kardex,
        ];
    }
}
