<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventario\IndexKardexRequest;
use App\Http\Resources\MovimientoKardexResource;
use App\Models\MovimientoKardex;
use App\Services\Inventario\ConsultaKardexService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RN-06: solo lectura. No hay rutas de edición ni borrado (PUT/PATCH/DELETE → 405, RN-06-AC5). */
class KardexController extends Controller
{
    public function __construct(private readonly ConsultaKardexService $consulta) {}

    public function index(IndexKardexRequest $request): AnonymousResourceCollection
    {
        return MovimientoKardexResource::collection($this->consulta->listar($request->filtros()));
    }

    public function show(MovimientoKardex $movimiento): MovimientoKardexResource
    {
        return new MovimientoKardexResource($this->consulta->detalle($movimiento));
    }
}
