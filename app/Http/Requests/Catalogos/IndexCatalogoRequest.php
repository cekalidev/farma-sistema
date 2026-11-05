<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogos;

use App\Http\Requests\Concerns\ReglasPaginacion;
use Illuminate\Foundation\Http\FormRequest;

/** Listados de catálogo (bodegas, productos): solo paginación, con los límites de S-25, S-28 y S-88. */
abstract class IndexCatalogoRequest extends FormRequest
{
    use ReglasPaginacion;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->reglasPaginacion();
    }
}
