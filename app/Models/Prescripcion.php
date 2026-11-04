<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPrescripcion;
use App\Support\Negocio;
use Carbon\CarbonInterface;
use Database\Factories\PrescripcionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $paciente_id
 * @property int $medico_id
 * @property Carbon $fecha_emision
 * @property Carbon $fecha_vencimiento
 * @property EstadoPrescripcion $estado
 * @property string|null $observaciones
 * @property-read Paciente $paciente
 * @property-read User $medico
 */
class Prescripcion extends Model
{
    /** @use HasFactory<PrescripcionFactory> */
    use HasFactory;

    protected $table = 'prescripciones';

    /** @var array<string, mixed> */
    protected $attributes = ['estado' => 'ACTIVA'];

    /** @var list<string> */
    protected $fillable = ['paciente_id', 'medico_id', 'fecha_emision', 'fecha_vencimiento', 'estado', 'observaciones'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
            'estado' => EstadoPrescripcion::class,
        ];
    }

    /** @return BelongsTo<Paciente, $this> */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /** @return BelongsTo<User, $this> */
    public function medico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medico_id');
    }

    /** @return HasMany<PrescripcionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PrescripcionItem::class);
    }

    /** S-05: vigente si está ACTIVA y hoy <= fecha_vencimiento (inclusiva). */
    public function estaVigente(CarbonInterface $hoy): bool
    {
        $hoyNegocio = $hoy->copy()->setTimezone(Negocio::zonaHoraria())->toDateString();

        return $this->estado === EstadoPrescripcion::Activa
            && $hoyNegocio <= $this->fecha_vencimiento->toDateString();
    }
}
