<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Traslados\AnularTrasladoRequest;
use App\Http\Requests\Traslados\IndexTrasladoRequest;
use App\Http\Requests\Traslados\RecibirTrasladoRequest;
use App\Http\Requests\Traslados\StoreTrasladoRequest;
use App\Http\Requests\Traslados\UpdateItemsTrasladoRequest;
use App\Http\Resources\TrasladoResource;
use App\Models\Traslado;
use App\Models\User;
use App\Services\Traslados\ConsultaTrasladoService;
use App\Services\Traslados\DespachoTraslado;
use App\Services\Traslados\RecepcionTraslado;
use App\Services\Traslados\TrasladoService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RN-07: traslados entre bodegas. Rol y creador: Policy de cada ruta; estado: servicios (409). */
class TrasladoController extends Controller
{
    public function __construct(
        private readonly TrasladoService $traslados,
        private readonly DespachoTraslado $despacho,
        private readonly RecepcionTraslado $recepcion,
        private readonly ConsultaTrasladoService $consulta,
    ) {}

    public function index(IndexTrasladoRequest $request): AnonymousResourceCollection
    {
        return TrasladoResource::collection($this->consulta->listar($request->filtros()));
    }

    public function show(Traslado $traslado): TrasladoResource
    {
        return new TrasladoResource($this->consulta->detalle($traslado));
    }

    /** RN-07-AC1: 201 en BORRADOR sin movimientos. */
    public function store(StoreTrasladoRequest $request): JsonResponse
    {
        return (new TrasladoResource($this->traslados->crear($request->datos())))
            ->response()
            ->setStatusCode(201);
    }

    /** RN-07-AC9: reemplaza los ítems de un BORRADOR. */
    public function updateItems(UpdateItemsTrasladoRequest $request, Traslado $traslado): TrasladoResource
    {
        return new TrasladoResource($this->traslados->editarItems($traslado, $this->usuario($request)->id, $request->items()));
    }

    public function solicitar(Request $request, Traslado $traslado): TrasladoResource
    {
        return new TrasladoResource($this->traslados->solicitar($traslado, $this->usuario($request)->id));
    }

    public function aprobar(Request $request, Traslado $traslado): TrasladoResource
    {
        return new TrasladoResource($this->traslados->aprobar($traslado, $this->usuario($request)->id));
    }

    public function despachar(Request $request, Traslado $traslado): TrasladoResource
    {
        return new TrasladoResource($this->despacho->despachar($traslado, $this->usuario($request)->id));
    }

    public function recibir(RecibirTrasladoRequest $request, Traslado $traslado): TrasladoResource
    {
        return new TrasladoResource($this->recepcion->recibir($traslado, $this->usuario($request)->id, $request->recibidos()));
    }

    public function anular(AnularTrasladoRequest $request, Traslado $traslado): TrasladoResource
    {
        return new TrasladoResource($this->traslados->anular($traslado, $this->usuario($request), $request->motivo()));
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();

        return $usuario instanceof User ? $usuario : throw new AuthenticationException;
    }
}
