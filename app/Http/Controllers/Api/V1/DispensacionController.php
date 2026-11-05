<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dispensacion\PrevisualizarDispensacionRequest;
use App\Http\Requests\Dispensacion\StoreDispensacionRequest;
use App\Http\Resources\DispensacionResource;
use App\Http\Resources\PrevisualizacionDispensacionResource;
use App\Services\Dispensacion\DispensacionService;
use Illuminate\Http\JsonResponse;

class DispensacionController extends Controller
{
    public function __construct(private readonly DispensacionService $dispensaciones) {}

    public function store(StoreDispensacionRequest $request): JsonResponse
    {
        return (new DispensacionResource($this->dispensaciones->crear($request->datos())))
            ->response()
            ->setStatusCode(201);
    }

    public function previsualizar(PrevisualizarDispensacionRequest $request): PrevisualizacionDispensacionResource
    {
        return new PrevisualizacionDispensacionResource($this->dispensaciones->previsualizar($request->datos()));
    }
}
