<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ítem de traslado con lote explícito (S-12). `cantidad` = lo que sale del origen al despachar;
 * `cantidad_recibida` se fija al recibir (CHECK BETWEEN 0 AND cantidad).
 *
 * @property int $id
 * @property int $traslado_id
 * @property int $producto_id
 * @property int $lote_id
 * @property int $cantidad
 * @property int|null $cantidad_recibida
 * @property-read Traslado $traslado
 * @property-read Producto $producto
 * @property-read Lote $lote
 * @property-read Discrepancia|null $discrepancia
 */
class TrasladoItem extends Model
{
    protected $table = 'traslado_items';

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cantidad' => 'integer', 'cantidad_recibida' => 'integer'];
    }

    /** @return BelongsTo<Traslado, $this> */
    public function traslado(): BelongsTo
    {
        return $this->belongsTo(Traslado::class);
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

    /** @return HasOne<Discrepancia, $this> */
    public function discrepancia(): HasOne
    {
        return $this->hasOne(Discrepancia::class);
    }
}
