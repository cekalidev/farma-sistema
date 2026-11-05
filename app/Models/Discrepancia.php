<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoDiscrepancia;
use App\Enums\TipoResolucionDiscrepancia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lo no recibido de un ítem de traslado (RN-07-AC6). La resuelve un regente (RN-07-AC12) con
 * App\Services\Traslados\ResolucionDiscrepancia.
 *
 * @property int $id
 * @property int $traslado_item_id
 * @property int $cantidad
 * @property EstadoDiscrepancia $estado
 * @property TipoResolucionDiscrepancia|null $tipo_resolucion
 * @property string|null $motivo
 * @property int|null $resuelto_por_id
 * @property Carbon|null $resuelto_en
 * @property int|null $movimiento_kardex_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TrasladoItem $item
 */
class Discrepancia extends Model
{
    protected $table = 'discrepancias';

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'estado' => EstadoDiscrepancia::class,
            'tipo_resolucion' => TipoResolucionDiscrepancia::class,
            'resuelto_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<TrasladoItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(TrasladoItem::class, 'traslado_item_id');
    }
}
