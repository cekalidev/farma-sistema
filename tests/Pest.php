<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Casos de prueba
|--------------------------------------------------------------------------
|
| - tests/Feature/*Test.php            → TestCase de Laravel + RefreshDatabase (PostgreSQL farma_test).
| - tests/Feature/SinTransaccion/**    → TestCase de Laravel SIN RefreshDatabase: datos con commit
|                                        real (concurrencia RN-03, migrate:fresh --seed de BASE-AC6).
|                                        Cada archivo limpia lo que deja.
| - tests/Unit/**                      → funciones puras, sin aplicación ni BD.
|
*/

pest()->extend(TestCase::class)->in('Feature');

pest()->use(RefreshDatabase::class)->in('Feature/*Test.php');

/*
|--------------------------------------------------------------------------
| Ayudantes compartidos
|--------------------------------------------------------------------------
*/

/**
 * Crea un usuario con el rol dado (valor del enum, p. ej. 'regente_farmacia').
 *
 * @param  array<string, mixed>  $atributos
 */
function usuarioConRol(string $rol, array $atributos = []): User
{
    return User::factory()->create(['rol' => Rol::from($rol)] + $atributos);
}

/**
 * Crea un usuario con el rol dado y lo autentica con Sanctum.
 *
 * @param  array<string, mixed>  $atributos
 */
function actuarComo(string $rol, array $atributos = []): User
{
    $usuario = usuarioConRol($rol, $atributos);
    Sanctum::actingAs($usuario);

    return $usuario;
}

/**
 * Afirma que la respuesta es un error con el formato único de la spec:
 * {"error": {"codigo", "mensaje", "detalles"}} y nada más en la raíz (sin trazas ni "message").
 */
function esperarError(TestResponse $respuesta, int $status, string $codigo): void
{
    $respuesta->assertStatus($status);

    expect($respuesta->headers->get('Content-Type'))->toContain('application/json');

    $json = $respuesta->json();
    expect($json)->toBeArray()
        ->and(array_keys($json))->toBe(['error'])
        ->and($json['error'])->toHaveKeys(['codigo', 'mensaje', 'detalles'])
        ->and($json['error']['codigo'])->toBe($codigo)
        ->and($json['error']['mensaje'])->toBeString()->not->toBe('');
}

/**
 * Cuerpo útil de una respuesta JSON: el contenido de "data" si el recurso viene envuelto
 * (API Resource) o la raíz si no.
 *
 * @return array<string, mixed>
 */
function cuerpoDatos(TestResponse $respuesta): array
{
    $json = $respuesta->json();
    expect($json)->toBeArray();

    return is_array($json['data'] ?? null) ? $json['data'] : $json;
}

/**
 * Olvida los guards resueltos para que la siguiente petición del mismo test vuelva a
 * autenticar el token desde cero (la app de test se reutiliza entre peticiones).
 */
function olvidarAutenticacion(): void
{
    app('auth')->forgetGuards();
}

/**
 * RN-09: cabeceras de una creación de dispensación (POST /api/v1/dispensaciones) con una
 * `Idempotency-Key` NUEVA en cada llamada, para las peticiones que no prueban la idempotencia.
 *
 * @param  array<string, string>  $cabeceras  otras cabeceras a enviar
 * @return array<string, string>
 */
function cabecerasDispensacion(array $cabeceras = []): array
{
    return $cabeceras + ['Idempotency-Key' => (string) Str::uuid()];
}
