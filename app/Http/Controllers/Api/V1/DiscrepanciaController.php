<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Traslados\ResolverDiscrepanciaRequest;
use App\Http\Resources\DiscrepanciaResource;
use App\Models\Discrepancia;
use App\Services\Traslados\ResolucionDiscrepancia;
use Illuminate\Auth\AuthenticationException;

/** RN-07-AC12: resolución de discrepancias de traslado (regente). */
class DiscrepanciaController extends Controller
{
    public function __construct(private readonly ResolucionDiscrepancia $resolucion) {}

    public function resolver(ResolverDiscrepanciaRequest $request, Discrepancia $discrepancia): DiscrepanciaResource
    {
        return new DiscrepanciaResource($this->resolucion->resolver(
            $discrepancia,
            $request->user()->id ?? throw new AuthenticationException,
            $request->tipo(),
            $request->motivo(),
        ));
    }
}
