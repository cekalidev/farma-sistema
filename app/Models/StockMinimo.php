<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockMinimoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S-14: stock mínimo por bodega + producto.
 *
 * @property int $id
 * @property int $bodega_id
 * @property int $producto_id
 * @property int $cantidad_minima
 * @property-read Bodega $bodega
 * @property-read Producto $producto
 */
class StockMinimo extends Model
{
    /** @use HasFactory<StockMinimoFactory> */
    use HasFactory;

    protected $table = 'stock_minimos';

    /** @var list<string> */
    protected $fillable = ['bodega_id', 'producto_id', 'cantidad_minima'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cantidad_minima' => 'integer'];
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
}
