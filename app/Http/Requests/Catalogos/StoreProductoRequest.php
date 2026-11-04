<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogos;

use App\Models\Producto;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Producto::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:30', 'unique:productos,codigo'],
            'nombre' => ['required', 'string', 'max:150'],
            'presentacion' => ['nullable', 'string', 'max:120'],
            'unidad_medida' => ['required', 'string', 'max:30'],
            'control_especial' => ['required', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }
}
