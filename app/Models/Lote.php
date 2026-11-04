<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\LoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $producto_id
 * @property string $codigo
 * @property Carbon $fecha_vencimiento
 */
class Lote extends Model
{
    /** @use HasFactory<LoteFactory> */
    use HasFactory;

    protected $table = 'lotes';

    /** @var list<string> */
    protected $fillable = ['producto_id', 'codigo', 'fecha_vencimiento'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fecha_vencimiento' => 'date'];
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** @return HasMany<Existencia, $this> */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /**
     * S-01: vence hoy = vencido (fecha_vencimiento <= hoy). Se compara por fecha
     * calendario en la zona de negocio (America/Bogota).
     */
    public function estaVencido(CarbonInterface $hoy): bool
    {
        $hoyNegocio = $hoy->copy()->setTimezone((string) config('farma.zona_horaria'))->toDateString();

        return $this->fecha_vencimiento->toDateString() <= $hoyNegocio;
    }
}
