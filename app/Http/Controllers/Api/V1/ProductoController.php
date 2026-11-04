<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogos\StoreProductoRequest;
use App\Http\Resources\ProductoResource;
use App\Models\Producto;
use App\Services\Catalogos\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductoController extends Controller
{
    public function __construct(private readonly ProductoService $productos) {}

    public function index(): AnonymousResourceCollection
    {
        return ProductoResource::collection($this->productos->listar());
    }

    public function store(StoreProductoRequest $request): JsonResponse
    {
        /** @var array{codigo: string, nombre: string, presentacion?: string|null, unidad_medida: string, control_especial: bool, activo?: bool} $datos */
        $datos = $request->validated();

        return (new ProductoResource($this->productos->crear($datos)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Producto $producto): ProductoResource
    {
        return new ProductoResource($producto);
    }
}
