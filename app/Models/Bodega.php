<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BodegaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property bool $activa
 */
class Bodega extends Model
{
    /** @use HasFactory<BodegaFactory> */
    use HasFactory;

    protected $table = 'bodegas';

    /** @var array<string, mixed> */
    protected $attributes = ['activa' => true];

    /** @var list<string> */
    protected $fillable = ['codigo', 'nombre', 'activa'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    /** @return HasMany<Existencia, $this> */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /** @return HasMany<StockMinimo, $this> */
    public function stockMinimos(): HasMany
    {
        return $this->hasMany(StockMinimo::class);
    }
}
