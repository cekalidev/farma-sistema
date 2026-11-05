<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alertas\IndexAlertaStockMinimoRequest;
use App\Http\Requests\Alertas\IndexAlertaVencimientoRequest;
use App\Http\Resources\AlertaStockMinimoResource;
use App\Http\Resources\AlertaVencimientoResource;
use App\Services\Inventario\ConsultaAlertasService;
use App\Support\Negocio;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RN-11: alertas de solo lectura (inventario.consultar: auxiliar, regente, auditor). */
class AlertaController extends Controller
{
    public function __construct(private readonly ConsultaAlertasService $alertas) {}

    public function vencimientos(IndexAlertaVencimientoRequest $request): AnonymousResourceCollection
    {
        $hoy = Negocio::hoy();

        return AlertaVencimientoResource::collection($this->alertas->vencimientos($request->filtros(), $hoy))
            ->additional(['meta' => [
                'hoy' => $hoy->toDateString(),
                'dias_alerta_vencimiento' => $this->alertas->umbralDias(),
            ]]);
    }

    public function stockMinimo(IndexAlertaStockMinimoRequest $request): AnonymousResourceCollection
    {
        $hoy = Negocio::hoy();

        return AlertaStockMinimoResource::collection($this->alertas->stockMinimo($request->filtros(), $hoy))
            ->additional(['meta' => ['hoy' => $hoy->toDateString()]]);
    }
}
