<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Habilidad;
use App\Enums\Rol;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Rol $rol
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'rol'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'rol' => Rol::class,
        ];
    }

    /**
     * Correo siempre en minúsculas y sin espacios. La BD respalda la unicidad con un índice
     * único sobre lower(email); las búsquedas usan el scope porCorreo().
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $valor): string => Str::lower(trim($valor)),
        );
    }

    /**
     * Búsqueda por correo sin distinguir mayúsculas: `lower(email) = ?` usa el índice único de
     * expresión users_email_lower_unique y encuentra también filas escritas por SQL directo con
     * mayúsculas. El valor va siempre como parámetro ligado.
     *
     * @param  Builder<User>  $query
     */
    public function scopePorCorreo(Builder $query, string $correo): void
    {
        $query->whereRaw('lower(email) = ?', [Str::lower(trim($correo))]);
    }

    public function tieneHabilidad(Habilidad $habilidad): bool
    {
        return $this->rol->puede($habilidad);
    }

    public function esRol(Rol $rol): bool
    {
        return $this->rol === $rol;
    }
}
