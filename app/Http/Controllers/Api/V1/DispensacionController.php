<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dispensacion\IndexDispensacionRequest;
use App\Http\Requests\Dispensacion\PrevisualizarDispensacionRequest;
use App\Http\Requests\Dispensacion\RechazarDispensacionRequest;
use App\Http\Requests\Dispensacion\StoreDispensacionRequest;
use App\Http\Resources\DispensacionResource;
use App\Http\Resources\PrevisualizacionDispensacionResource;
use App\Models\Dispensacion;
use App\Services\Dispensacion\AutorizacionDispensacion;
use App\Services\Dispensacion\ConsultaDispensacionService;
use App\Services\Dispensacion\DispensacionService;
use App\Services\Dispensacion\RespuestaIdempotente;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DispensacionController extends Controller
{
    public function __construct(
        private readonly DispensacionService $dispensaciones,
        private readonly AutorizacionDispensacion $autorizacion,
        private readonly ConsultaDispensacionService $consulta,
    ) {}

    /** S-89: listado paginado sin el paciente anidado (no pasa por la bitácora). Rol: Policy de la ruta. */
    public function index(IndexDispensacionRequest $request): AnonymousResourceCollection
    {
        return DispensacionResource::collection($this->consulta->listar($request->filtros()));
    }

    /** RN-10-AC4: detalle con el paciente anidado (enmascarado para el auditor). Rol: Policy de la ruta. */
    public function show(Request $request, Dispensacion $dispensacion): DispensacionResource
    {
        return new DispensacionResource($this->consulta->detalle(
            $dispensacion,
            $request->user()->id ?? throw new AuthenticationException,
        ));
    }

    /**
     * RN-09: la respuesta (status + JSON literal) la guarda el servicio con la dispensación; un
     * reintento con la misma Idempotency-Key y el mismo cuerpo recibe esa misma respuesta con
     * `Idempotent-Replayed: true`.
     */
    public function store(StoreDispensacionRequest $request): JsonResponse
    {
        $respuesta = $this->dispensaciones->crear(
            $request->datos(),
            $request->claveIdempotencia(),
            fn (Dispensacion $dispensacion): RespuestaIdempotente => new RespuestaIdempotente(
                201,
                (string) (new DispensacionResource($dispensacion))->response($request)->getContent(),
            ),
        );

        return new JsonResponse(
            $respuesta->cuerpo,
            $respuesta->status,
            $respuesta->reproducida ? ['Idempotent-Replayed' => 'true'] : [],
            json: true,
        );
    }

    public function previsualizar(PrevisualizarDispensacionRequest $request): PrevisualizacionDispensacionResource
    {
        return new PrevisualizacionDispensacionResource($this->dispensaciones->previsualizar($request->datos()));
    }

    /** RN-05-AC3: 200 con la dispensación CONFIRMADA y sus tramos FEFO. Rol y segundo regente: Policy de la ruta. */
    public function autorizar(Request $request, Dispensacion $dispensacion): DispensacionResource
    {
        return new DispensacionResource($this->autorizacion->autorizar(
            $dispensacion,
            $request->user()->id ?? throw new AuthenticationException,
        ));
    }

    /** RN-05-AC8: 200 con la dispensación RECHAZADA, sin movimientos. */
    public function rechazar(RechazarDispensacionRequest $request, Dispensacion $dispensacion): DispensacionResource
    {
        return new DispensacionResource($this->autorizacion->rechazar(
            $dispensacion,
            $request->user()->id ?? throw new AuthenticationException,
            $request->motivo(),
        ));
    }
}
