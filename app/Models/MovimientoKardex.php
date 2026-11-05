<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentoOrigen;
use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\KardexInmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Movimiento de kardex (RN-06). Solo INSERT, y solo desde App\Services\Inventario\RegistradorKardex.
 *
 * Inmutable en dos capas (RN-06-AC3/AC4):
 *  - Modelo: save(), update() y delete() sobrescritos lanzan KardexInmutableException para una fila
 *    guardada, también con eventos desactivados (saveQuietly, deleteQuietly, withoutEvents); touch(),
 *    push(), forceDelete() y destroy() pasan por ellos. increment()/decrement()/incrementEach()/
 *    decrementEach() no llaman a save(): los frena el evento `updating` (sus variantes Quietly, sin
 *    eventos, las frena el trigger).
 *  - BD: el trigger movimientos_kardex_inmutable rechaza UPDATE/DELETE/TRUNCATE por SQL directo o
 *    por el query builder; si llega a una petición, el renderizador responde 409 KARDEX_INMUTABLE (S-39).
 *
 * @property int $id
 * @property TipoMovimiento $tipo
 * @property int $existencia_id
 * @property int $bodega_id
 * @property int $producto_id
 * @property int $lote_id
 * @property int $cantidad
 * @property int $saldo_anterior
 * @property int $saldo_posterior
 * @property string|null $motivo
 * @property int $usuario_id
 * @property DocumentoOrigen|null $documento_tipo
 * @property int|null $documento_id
 * @property Carbon $created_at
 * @property-read Existencia $existencia
 * @property-read Bodega $bodega
 * @property-read Producto $producto
 * @property-read Lote $lote
 * @property-read User $usuario
 */
class MovimientoKardex extends Model
{
    protected $table = 'movimientos_kardex';

    /** Sin updated_at: un movimiento nunca cambia. */
    public const UPDATED_AT = null;

    /**
     * Nada se asigna en masa: el registrador fija cada columna con forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'documento_tipo' => DocumentoOrigen::class,
            'cantidad' => 'integer',
            'saldo_anterior' => 'integer',
            'saldo_posterior' => 'integer',
            'documento_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Única vía de Eloquent que modifica una fila sin pasar por save(): increment(), decrement(),
        // incrementEach() y decrementEach() disparan `updating` y luego un UPDATE directo. Sin este
        // evento los frenaría solo el trigger (QueryException en vez de la excepción de dominio).
        // No hay `saving` ni `deleting`: save() y delete() sobrescritos lanzan antes de que se
        // disparen, así que serían código inalcanzable.
        static::updating(function (self $movimiento): never {
            throw KardexInmutableException::paraOperacion('update', $movimiento->id);
        });
    }

    /**
     * Antes que el guard de asignación masiva: update() de un movimiento guardado siempre lanza
     * KardexInmutableException (no MassAssignmentException).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw KardexInmutableException::paraOperacion('update', $this->id);
        }

        return parent::update($attributes, $options);
    }

    /**
     * Igual que update(): KardexInmutableException antes que MassAssignmentException.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw KardexInmutableException::paraOperacion('update', $this->id);
        }

        return parent::updateQuietly($attributes, $options);
    }

    /**
     * También con eventos desactivados (saveQuietly, WithoutModelEvents).
     *
     * @param  array{touch?: bool|null}  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw KardexInmutableException::paraOperacion('update', $this->id);
        }

        return parent::save($options);
    }

    /** También con eventos desactivados (deleteQuietly). */
    public function delete(): bool
    {
        if ($this->exists) {
            throw KardexInmutableException::paraOperacion('delete', $this->id);
        }

        return false;
    }

    /** @return BelongsTo<Existencia, $this> */
    public function existencia(): BelongsTo
    {
        return $this->belongsTo(Existencia::class);
    }

    /** @return BelongsTo<Bodega, $this> */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** @return BelongsTo<Lote, $this> */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
