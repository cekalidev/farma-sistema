<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pacientes\BuscarPacienteRequest;
use App\Http\Resources\PacienteResource;
use App\Http\Resources\PrescripcionResource;
use App\Models\Paciente;
use App\Services\Pacientes\ConsultaPacienteService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * RN-10: consulta de pacientes. Rol: Policy de la ruta / Form Request (admin → 403); bitácora:
 * servicio (PERMITIDO) y middleware RegistrarAccesoDenegado (DENEGADO); enmascarado: PacienteResource.
 */
class PacienteController extends Controller
{
    public function __construct(private readonly ConsultaPacienteService $pacientes) {}

    /** RN-10-AC1/AC3. */
    public function show(Request $request, Paciente $paciente): PacienteResource
    {
        return new PacienteResource($this->pacientes->detalle($paciente, self::usuarioId($request)));
    }

    /** RN-10-AC7: 200 con la lista (posiblemente vacía) de pacientes con ese documento. */
    public function buscar(BuscarPacienteRequest $request): AnonymousResourceCollection
    {
        return PacienteResource::collection($this->pacientes->buscar(
            $request->tipo(),
            $request->numeroDocumento(),
            self::usuarioId($request),
        ));
    }

    /** RN-10-AC4: prescripciones con ítems, pendiente y el paciente anidado. */
    public function prescripciones(Request $request, Paciente $paciente): AnonymousResourceCollection
    {
        return PrescripcionResource::collection($this->pacientes->prescripciones($paciente, self::usuarioId($request)));
    }

    private static function usuarioId(Request $request): int
    {
        return $request->user()->id ?? throw new AuthenticationException;
    }
}
