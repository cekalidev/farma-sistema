<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventario\IndexInventarioRequest;
use App\Http\Requests\Inventario\StoreAjusteRequest;
use App\Http\Requests\Inventario\StoreEntradaRequest;
use App\Http\Resources\EntradaResource;
use App\Http\Resources\ExistenciaResource;
use App\Http\Resources\MovimientoKardexResource;
use App\Services\Inventario\AjusteInventarioService;
use App\Services\Inventario\ConsultaInventarioService;
use App\Services\Inventario\EntradaInventarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventarioController extends Controller
{
    public function __construct(
        private readonly ConsultaInventarioService $consulta,
        private readonly EntradaInventarioService $entradas,
        private readonly AjusteInventarioService $ajustes,
    ) {}

    public function index(IndexInventarioRequest $request): AnonymousResourceCollection
    {
        return ExistenciaResource::collection($this->consulta->listar($request->filtros()));
    }

    public function storeEntrada(StoreEntradaRequest $request): JsonResponse
    {
        return (new EntradaResource($this->entradas->registrar($request->datos())))
            ->response()
            ->setStatusCode(201);
    }

    public function storeAjuste(StoreAjusteRequest $request): JsonResponse
    {
        return (new MovimientoKardexResource($this->ajustes->registrar($request->datos())))
            ->response()
            ->setStatusCode(201);
    }
}
