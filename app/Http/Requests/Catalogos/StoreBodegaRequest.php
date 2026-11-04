<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogos;

use App\Models\Bodega;
use Illuminate\Foundation\Http\FormRequest;

class StoreBodegaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Bodega::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:20', 'unique:bodegas,codigo'],
            'nombre' => ['required', 'string', 'max:120', 'unique:bodegas,nombre'],
            'activa' => ['sometimes', 'boolean'],
        ];
    }
}
