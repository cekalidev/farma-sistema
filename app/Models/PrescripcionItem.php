<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PrescripcionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $prescripcion_id
 * @property int $producto_id
 * @property int $cantidad
 * @property string|null $indicaciones
 */
class PrescripcionItem extends Model
{
    /** @use HasFactory<PrescripcionItemFactory> */
    use HasFactory;

    protected $table = 'prescripcion_items';

    /** @var list<string> */
    protected $fillable = ['prescripcion_id', 'producto_id', 'cantidad', 'indicaciones'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cantidad' => 'integer'];
    }

    /** @return BelongsTo<Prescripcion, $this> */
    public function prescripcion(): BelongsTo
    {
        return $this->belongsTo(Prescripcion::class);
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
