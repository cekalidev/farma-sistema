<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Enums\ResultadoAcceso;
use App\Exceptions\Dominio\BitacoraInmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fila de la bitácora de accesos y operaciones sensibles (RN-10). Solo INSERT, y solo desde
 * App\Services\Auditoria\Bitacora.
 *
 * Inmutable en dos capas (RN-10-AC10), como MovimientoKardex: save()/update()/delete() de una fila
 * guardada y el evento `updating` lanzan BitacoraInmutable; la BD lo respalda con el trigger
 * auditoria_accesos_inmutable (UPDATE/DELETE/TRUNCATE → 409 BITACORA_INMUTABLE si llega a una petición).
 *
 * @property int $id
 * @property int $usuario_id
 * @property int|null $paciente_id
 * @property AccionAuditoria $accion
 * @property ResultadoAcceso $resultado
 * @property EntidadAuditada|null $entidad
 * @property int|null $entidad_id
 * @property string|null $ruta
 * @property string|null $metodo
 * @property string|null $ip
 * @property string $correlation_id
 * @property Carbon $created_at
 * @property-read User $usuario
 */
class AuditoriaAcceso extends Model
{
    protected $table = 'auditoria_accesos';

    /** Sin updated_at: una fila nunca cambia. */
    public const UPDATED_AT = null;

    /**
     * Nada se asigna en masa: la bitácora fija cada columna con forceFill.
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
            'accion' => AccionAuditoria::class,
            'resultado' => ResultadoAcceso::class,
            'entidad' => EntidadAuditada::class,
            'entidad_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // increment()/decrement() no pasan por save(): los frena este evento.
        static::updating(function (self $registro): never {
            throw BitacoraInmutable::paraOperacion('update', $registro->id);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw BitacoraInmutable::paraOperacion('update', $this->id);
        }

        return parent::update($attributes, $options);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw BitacoraInmutable::paraOperacion('update', $this->id);
        }

        return parent::updateQuietly($attributes, $options);
    }

    /**
     * @param  array{touch?: bool|null}  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw BitacoraInmutable::paraOperacion('update', $this->id);
        }

        return parent::save($options);
    }

    public function delete(): bool
    {
        if ($this->exists) {
            throw BitacoraInmutable::paraOperacion('delete', $this->id);
        }

        return false;
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
