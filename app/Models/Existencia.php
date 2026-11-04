<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExistenciaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existencia = bodega + producto + lote (RN-01). La cantidad solo cambia desde
 * un servicio de inventario con DB::transaction + lockForUpdate; la BD la
 * respalda con CHECK (cantidad >= 0).
 *
 * @property int $id
 * @property int $bodega_id
 * @property int $producto_id
 * @property int $lote_id
 * @property int $cantidad
 * @property-read Bodega $bodega
 * @property-read Producto $producto
 * @property-read Lote $lote
 */
class Existencia extends Model
{
    /** @use HasFactory<ExistenciaFactory> */
    use HasFactory;

    protected $table = 'existencias';

    /** @var array<string, mixed> */
    protected $attributes = ['cantidad' => 0];

    /**
     * Sin `cantidad`: el stock nunca se asigna en masa; solo lo cambia un servicio de inventario
     * (forceFill dentro de la transacción con lockForUpdate). `producto_id` sí: identifica la
     * existencia y la FK compuesta (lote_id, producto_id) impide que no case con el lote.
     *
     * @var list<string>
     */
    protected $fillable = ['bodega_id', 'producto_id', 'lote_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cantidad' => 'integer'];
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
}
