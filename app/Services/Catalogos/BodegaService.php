<?php

declare(strict_types=1);

namespace App\Services\Catalogos;

use App\Exceptions\Dominio\CodigoDuplicado;
use App\Models\Bodega;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class BodegaService
{
    use TraduceDuplicados;

    /**
     * @return LengthAwarePaginator<int, Bodega>
     */
    public function listar(int $porPagina): LengthAwarePaginator
    {
        return Bodega::query()->orderBy('nombre')->orderBy('id')->paginate($porPagina);
    }

    /**
     * @param  array{codigo: string, nombre: string, activa?: bool}  $datos
     *
     * @throws CodigoDuplicado si otra petición creó el mismo valor único a la vez.
     */
    public function crear(array $datos): Bodega
    {
        return $this->insertarSinDuplicados(
            fn (): Bodega => Bodega::query()->create($datos),
            ['bodegas_codigo_unique' => 'codigo', 'bodegas_nombre_unique' => 'nombre'],
        );
    }
}
