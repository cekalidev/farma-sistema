<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tramo FEFO (RN-02): cantidad tomada de un lote para un ítem, con su movimiento
 * SALIDA_DISPENSACION. Lo crea solo App\Services\Dispensacion\SalidaStock.
 *
 * @property int $id
 * @property int $dispensacion_id
 * @property int $dispensacion_item_id
 * @property int $existencia_id
 * @property int $bodega_id
 * @property int $producto_id
 * @property int $lote_id
 * @property int $movimiento_kardex_id
 * @property int $cantidad
 * @property-read Dispensacion $dispensacion
 * @property-read DispensacionItem $item
 * @property-read Existencia $existencia
 * @property-read Lote $lote
 * @property-read MovimientoKardex $movimiento
 */
class DispensacionDetalle extends Model
{
    protected $table = 'dispensacion_detalles';

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

    /** @return BelongsTo<DispensacionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(DispensacionItem::class, 'dispensacion_item_id');
    }

    /** @return BelongsTo<Existencia, $this> */
    public function existencia(): BelongsTo
    {
        return $this->belongsTo(Existencia::class);
    }

    /** @return BelongsTo<Lote, $this> */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /** @return BelongsTo<MovimientoKardex, $this> */
    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoKardex::class, 'movimiento_kardex_id');
    }
}
