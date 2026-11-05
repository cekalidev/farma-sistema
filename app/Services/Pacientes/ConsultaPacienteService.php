<?php

declare(strict_types=1);

namespace App\Services\Pacientes;

use App\Enums\AccionAuditoria;
use App\Enums\TipoDocumento;
use App\Models\Paciente;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Services\Auditoria\Bitacora;
use App\Services\Dispensacion\CalculadorPendiente;
use Illuminate\Database\Eloquent\Collection;

/**
 * RN-10: consultas de pacientes. Cada consulta exitosa queda en la bitácora con resultado
 * PERMITIDO (AC5): una fila por paciente devuelto. El rol ya lo decidió la Policy de la ruta; el
 * enmascarado lo aplica PacienteResource. Nada de esto se escribe en logs.
 */
final class ConsultaPacienteService
{
    public function __construct(
        private readonly Bitacora $bitacora,
        private readonly CalculadorPendiente $pendientes,
    ) {}

    /** RN-10-AC1/AC3: detalle de un paciente. */
    public function detalle(Paciente $paciente, int $usuarioId): Paciente
    {
        $this->bitacora->consultaPermitida($usuarioId, AccionAuditoria::PacienteConsultar, [$paciente->id]);

        return $paciente;
    }

    /**
     * RN-10-AC7: búsqueda exacta por documento (llega en el cuerpo de un POST, nunca en la URL).
     * Sin tipo de documento puede haber varios (uno por tipo). Una fila de bitácora por paciente
     * devuelto; sin resultados no se escribe ninguna (AC5, S-78).
     *
     * @return Collection<int, Paciente>
     */
    public function buscar(?TipoDocumento $tipo, string $numeroDocumento, int $usuarioId): Collection
    {
        $pacientes = Paciente::query()
            ->where('numero_documento', $numeroDocumento)
            ->when($tipo !== null, fn ($q) => $q->where('tipo_documento', $tipo))
            ->orderBy('id')
            ->get();

        $this->bitacora->consultaPermitida(
            $usuarioId,
            AccionAuditoria::PacienteBuscar,
            array_values($pacientes->map(fn (Paciente $p): int => $p->id)->all()),
        );

        return $pacientes;
    }

    /**
     * Prescripciones del paciente (más recientes primero) con ítems, producto y pendiente por
     * dispensar (RN-04, CalculadorPendiente), cada una con el paciente anidado (AC4). Una fila de
     * bitácora por consulta (el paciente es uno solo), aunque no tenga prescripciones.
     *
     * @return Collection<int, Prescripcion>
     */
    public function prescripciones(Paciente $paciente, int $usuarioId): Collection
    {
        $prescripciones = Prescripcion::query()
            ->where('paciente_id', $paciente->id)
            ->with(['items' => fn ($q) => $q->orderBy('id'), 'items.producto:id,codigo,nombre,control_especial'])
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->get();

        /** @var list<PrescripcionItem> $items */
        $items = array_values($prescripciones->flatMap(fn (Prescripcion $p) => $p->items)->all());
        $pendientes = $this->pendientes->pendientes($items);

        foreach ($prescripciones as $prescripcion) {
            $prescripcion->setRelation('paciente', $paciente);
            foreach ($prescripcion->items as $item) {
                $item->pendienteCalculado = $pendientes[$item->id] ?? null;
            }
        }

        $this->bitacora->consultaPermitida($usuarioId, AccionAuditoria::PacientePrescripciones, [$paciente->id]);

        return $prescripciones;
    }
}
