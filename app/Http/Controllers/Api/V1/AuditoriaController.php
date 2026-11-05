<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auditoria\IndexAuditoriaRequest;
use App\Http\Resources\AuditoriaAccesoResource;
use App\Services\Auditoria\ConsultaAuditoriaService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RN-10-AC10: bitácora de solo lectura (auditor, admin). Sin rutas de edición ni borrado. */
class AuditoriaController extends Controller
{
    public function __construct(private readonly ConsultaAuditoriaService $consulta) {}

    public function index(IndexAuditoriaRequest $request): AnonymousResourceCollection
    {
        return AuditoriaAccesoResource::collection($this->consulta->listar($request->filtros()));
    }
}
