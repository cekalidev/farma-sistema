<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Habilidad;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Id numérico positivo de hasta 18 dígitos: siempre cabe en bigint (máx. 9223372036854775807,
     * 19 dígitos), así PostgreSQL nunca recibe un texto (22P02) ni un número fuera de rango (22003).
     */
    public const PATRON_ID = '[1-9][0-9]{0,17}';

    /** Mayor id que admite PATRON_ID: tope de las reglas `max:` de ids en query y cuerpo. */
    public const ID_MAXIMO = 999_999_999_999_999_999;

    /**
     * Parámetros de ruta que son ids de modelo. Toda RN que añada un parámetro lo registra aquí;
     * el test BASE-AC4 "todo parámetro de ruta de /api/v1 restringe su formato" falla si falta.
     *
     * @var list<string>
     */
    public const PARAMETROS_ID = [
        'bodega',
        'producto',
        'lote',
        'existencia',
        'paciente',
        'prescripcion',
        'dispensacion',
        'traslado',
        'usuario',
        'id',
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registrarHabilidades();
        $this->registrarLimitesDeTasa();
        $this->registrarPatronesDeRuta();
    }

    /**
     * Un id con formato inválido no llega al route model binding: el router responde 404
     * NO_ENCONTRADO. Se registra antes de cargar routes/ (boot de los providers).
     */
    private function registrarPatronesDeRuta(): void
    {
        foreach (self::PARAMETROS_ID as $parametro) {
            Route::pattern($parametro, self::PATRON_ID);
        }
    }

    /**
     * Cada Habilidad es un Gate con su valor (p. ej. 'inventario.consultar').
     * La matriz rol → habilidades está en App\Enums\Rol::habilidades().
     */
    private function registrarHabilidades(): void
    {
        foreach (Habilidad::cases() as $habilidad) {
            Gate::define($habilidad->value, fn (User $user): bool => $user->tieneHabilidad($habilidad));
        }
    }

    private function registrarLimitesDeTasa(): void
    {
        // Login, tres contadores (todos → 429 DEMASIADAS_SOLICITUDES):
        //  - correo + IP: frena la fuerza bruta sin bloquear a otros usuarios de la misma IP;
        //  - correo: frena el ataque distribuido desde muchas IP contra una cuenta;
        //  - IP: frena el barrido de muchos correos desde una misma IP.
        // Las claves llevan prefijo distinto para que los contadores no se mezclen.
        RateLimiter::for('login', function (Request $request): array {
            $entrada = $request->input('email');
            $correo = Str::lower(trim(is_string($entrada) ? $entrada : ''));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute((int) config('farma.login_intentos_por_minuto', 5))
                    ->by('login:correo_ip:'.sha1($correo.'|'.$ip)),
                Limit::perMinute((int) config('farma.login_intentos_por_correo_por_minuto', 10))
                    ->by('login:correo:'.sha1($correo)),
                Limit::perMinute((int) config('farma.login_intentos_por_ip_por_minuto', 20))
                    ->by('login:ip:'.sha1($ip)),
            ];
        });

        // General de /api/v1 autenticado: por usuario; por IP si no hay usuario.
        RateLimiter::for('api', function (Request $request): Limit {
            $usuarioId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute((int) config('farma.api_peticiones_por_minuto', 60))
                ->by($usuarioId !== null ? 'usuario:'.$usuarioId : 'ip:'.$request->ip());
        });
    }
}
