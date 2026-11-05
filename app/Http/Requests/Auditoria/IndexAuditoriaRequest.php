<?php

declare(strict_types=1);

namespace App\Http\Requests\Auditoria;

use App\Enums\AccionAuditoria;
use App\Enums\ResultadoAcceso;
use App\Http\Requests\Concerns\ReglasPaginacion;
use App\Http\Requests\Inventario\IndexKardexRequest;
use App\Models\AuditoriaAcceso;
use App\Providers\AppServiceProvider;
use App\Services\Auditoria\FiltrosAuditoria;
use App\Support\Negocio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/auditoria?usuario_id=&paciente_id=&resultado=&accion=&desde=&hasta=&por_pagina=&page=
 * (RN-10-AC10). Ids, fechas (Y-m-d de Bogotá, inclusivas) y paginación como GET /kardex (S-25,
 * S-28, S-36). Ningún filtro admite datos personales: el paciente se filtra por id.
 */
class IndexAuditoriaRequest extends FormRequest
{
    use ReglasPaginacion;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AuditoriaAcceso::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO];
        $fecha = ['bail', 'sometimes', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-\d{2}$/', 'after_or_equal:'.IndexKardexRequest::FECHA_MINIMA, 'before:'.IndexKardexRequest::FECHA_LIMITE];

        return [
            'usuario_id' => $id,
            'paciente_id' => $id,
            'resultado' => ['sometimes', 'string', Rule::enum(ResultadoAcceso::class)],
            'accion' => ['sometimes', 'string', Rule::enum(AccionAuditoria::class)],
            'desde' => $fecha,
            'hasta' => $this->filled('desde') ? [...$fecha, 'after_or_equal:desde'] : $fecha,
            ...$this->reglasPaginacion(),
        ];
    }

    public function filtros(): FiltrosAuditoria
    {
        /** @var array{usuario_id?: int|string, paciente_id?: int|string, resultado?: string, accion?: string, desde?: string, hasta?: string, por_pagina?: int|string} $validados */
        $validados = $this->validated();

        return new FiltrosAuditoria(
            usuarioId: isset($validados['usuario_id']) ? (int) $validados['usuario_id'] : null,
            pacienteId: isset($validados['paciente_id']) ? (int) $validados['paciente_id'] : null,
            resultado: isset($validados['resultado']) ? ResultadoAcceso::from($validados['resultado']) : null,
            accion: isset($validados['accion']) ? AccionAuditoria::from($validados['accion']) : null,
            desde: isset($validados['desde']) ? Negocio::inicioDelDia($validados['desde']) : null,
            hastaExclusivo: isset($validados['hasta']) ? Negocio::inicioDelDia($validados['hasta'], 1) : null,
            porPagina: $this->porPagina(),
        );
    }
}
