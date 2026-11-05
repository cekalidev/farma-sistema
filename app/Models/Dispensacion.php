<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoDispensacion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Dispensación (RN-02). Solo la crea App\Services\Dispensacion\DispensacionService; el stock lo
 * descuenta App\Services\Dispensacion\SalidaStock. RN-05: la autoriza o rechaza
 * App\Services\Dispensacion\AutorizacionDispensacion (CHECK: nunca quien la dispensó). RN-09: idempotency_key + request_hash (único por
 * usuario) y la respuesta original (respuesta_status, respuesta_cuerpo: JSON literal, sin cast).
 *
 * @property int $id
 * @property int $paciente_id
 * @property int $prescripcion_id
 * @property int $bodega_id
 * @property int $dispensado_por_id
 * @property EstadoDispensacion $estado
 * @property int|null $autorizado_por_id
 * @property Carbon|null $autorizado_en
 * @property int|null $rechazado_por_id
 * @property Carbon|null $rechazado_en
 * @property string|null $motivo_rechazo
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property int|null $respuesta_status
 * @property string|null $respuesta_cuerpo
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Paciente $paciente
 * @property-read Prescripcion $prescripcion
 * @property-read Bodega $bodega
 * @property-read User $dispensadoPor
 * @property-read User|null $autorizadoPor
 * @property-read User|null $rechazadoPor
 * @property-read Collection<int, DispensacionItem> $items
 * @property-read Collection<int, DispensacionDetalle> $detalles
 */
class Dispensacion extends Model
{
    protected $table = 'dispensaciones';

    /**
     * Nada en masa: el servicio fija cada columna con forceFill (estado y autorización no deben
     * llegar nunca desde la petición).
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * RN-09: datos internos de la idempotencia; nunca salen en una serialización del modelo
     * (toArray/toJson). La clave y el hash identifican peticiones del cliente y la respuesta
     * guardada es un JSON entero que solo reproduce el servicio.
     *
     * @var list<string>
     */
    protected $hidden = ['idempotency_key', 'request_hash', 'respuesta_status', 'respuesta_cuerpo'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoDispensacion::class,
            'autorizado_en' => 'datetime',
            'rechazado_en' => 'datetime',
            'respuesta_status' => 'integer',
        ];
    }

    /** @return BelongsTo<Paciente, $this> */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /** @return BelongsTo<Prescripcion, $this> */
    public function prescripcion(): BelongsTo
    {
        return $this->belongsTo(Prescripcion::class);
    }

    /** @return BelongsTo<Bodega, $this> */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /** @return BelongsTo<User, $this> */
    public function dispensadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensado_por_id');
    }

    /** @return BelongsTo<User, $this> */
    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rechazadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rechazado_por_id');
    }

    /** @return HasMany<DispensacionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DispensacionItem::class)->orderBy('id');
    }

    /**
     * Tramos FEFO en el orden en que se descontaron (id ascendente = orden del kardex).
     *
     * @return HasMany<DispensacionDetalle, $this>
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(DispensacionDetalle::class)->orderBy('id');
    }
}
