<?php

declare(strict_types=1);

namespace App\Services\Catalogos;

use App\Exceptions\Dominio\CodigoDuplicado;
use App\Models\Producto;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ProductoService
{
    use TraduceDuplicados;

    /**
     * @return LengthAwarePaginator<int, Producto>
     */
    public function listar(int $porPagina = 25): LengthAwarePaginator
    {
        return Producto::query()->orderBy('nombre')->paginate($porPagina);
    }

    /**
     * @param  array{codigo: string, nombre: string, presentacion?: string|null, unidad_medida: string, control_especial: bool, activo?: bool}  $datos
     *
     * @throws CodigoDuplicado si otra petición creó el mismo valor único a la vez.
     */
    public function crear(array $datos): Producto
    {
        return $this->insertarSinDuplicados(
            fn (): Producto => Producto::query()->create($datos),
            ['productos_codigo_unique' => 'codigo'],
        );
    }
}
