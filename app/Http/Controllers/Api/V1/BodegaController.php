<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogos\IndexBodegaRequest;
use App\Http\Requests\Catalogos\StoreBodegaRequest;
use App\Http\Resources\BodegaResource;
use App\Models\Bodega;
use App\Services\Catalogos\BodegaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BodegaController extends Controller
{
    public function __construct(private readonly BodegaService $bodegas) {}

    public function index(IndexBodegaRequest $request): AnonymousResourceCollection
    {
        return BodegaResource::collection($this->bodegas->listar($request->porPagina()));
    }

    public function store(StoreBodegaRequest $request): JsonResponse
    {
        /** @var array{codigo: string, nombre: string, activa?: bool} $datos */
        $datos = $request->validated();

        return (new BodegaResource($this->bodegas->crear($datos)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Bodega $bodega): BodegaResource
    {
        return new BodegaResource($bodega);
    }
}
