<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cantidad solicitada de un ítem prescrito en una dispensación. Existe aunque la dispensación no
 * haya movido stock (PENDIENTE_AUTORIZACION): RN-04 suma estas cantidades para el pendiente.
 * `producto_id` se copia del ítem prescrito (RN-04-AC11), nunca del cliente.
 *
 * @property int $id
 * @property int $dispensacion_id
 * @property int $prescripcion_id
 * @property int $prescripcion_item_id
 * @property int $producto_id
 * @property int $cantidad
 * @property-read Dispensacion $dispensacion
 * @property-read PrescripcionItem $prescripcionItem
 * @property-read Producto $producto
 * @property-read Collection<int, DispensacionDetalle> $detalles
 */
class DispensacionItem extends Model
{
    protected $table = 'dispensacion_items';

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cantidad' => 'integer'];
    }

    /** @return BelongsTo<Dispensacion, $this> */
    public function dispensacion(): BelongsTo
    {
        return $this->belongsTo(Dispensacion::class);
    }

    /** @return BelongsTo<PrescripcionItem, $this> */
    public function prescripcionItem(): BelongsTo
    {
        return $this->belongsTo(PrescripcionItem::class);
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** @return HasMany<DispensacionDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(DispensacionDetalle::class)->orderBy('id');
    }
}
