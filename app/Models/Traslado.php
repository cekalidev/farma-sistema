<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TrasladoEstado;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Traslado entre bodegas (RN-07). Solo lo crean y cambian los servicios de App\Services\Traslados;
 * las transiciones las decide App\Enums\TrasladoEstado::puede() sobre la fila bloqueada.
 *
 * @property int $id
 * @property int $bodega_origen_id
 * @property int $bodega_destino_id
 * @property TrasladoEstado $estado
 * @property string|null $observaciones
 * @property int $creado_por_id
 * @property int|null $solicitado_por_id
 * @property Carbon|null $solicitado_en
 * @property int|null $aprobado_por_id
 * @property Carbon|null $aprobado_en
 * @property int|null $despachado_por_id
 * @property Carbon|null $despachado_en
 * @property int|null $recibido_por_id
 * @property Carbon|null $recibido_en
 * @property int|null $anulado_por_id
 * @property Carbon|null $anulado_en
 * @property string|null $motivo_anulacion
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Bodega $bodegaOrigen
 * @property-read Bodega $bodegaDestino
 * @property-read Collection<int, TrasladoItem> $items
 */
class Traslado extends Model
{
    protected $table = 'traslados';

    /**
     * Nada en masa: estado y usuarios de cada acción los fija el servicio con forceFill.
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
            'estado' => TrasladoEstado::class,
            'solicitado_en' => 'datetime',
            'aprobado_en' => 'datetime',
            'despachado_en' => 'datetime',
            'recibido_en' => 'datetime',
            'anulado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bodega, $this> */
    public function bodegaOrigen(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_origen_id');
    }

    /** @return BelongsTo<Bodega, $this> */
    public function bodegaDestino(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_destino_id');
    }

    /** @return HasMany<TrasladoItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TrasladoItem::class)->orderBy('id');
    }
}
