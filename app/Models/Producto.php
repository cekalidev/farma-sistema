<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $presentacion
 * @property string $unidad_medida
 * @property bool $control_especial
 * @property bool $activo
 */
class Producto extends Model
{
    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    protected $table = 'productos';

    /** @var array<string, mixed> */
    protected $attributes = ['control_especial' => false, 'activo' => true];

    /** @var list<string> */
    protected $fillable = ['codigo', 'nombre', 'presentacion', 'unidad_medida', 'control_especial', 'activo'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'control_especial' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Lote, $this> */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /** @return HasMany<Existencia, $this> */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }
}
