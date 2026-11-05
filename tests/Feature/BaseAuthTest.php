<?php

declare(strict_types=1);

use App\Exceptions\Dominio\NoAutorizado;
use App\Http\Requests\Catalogos\StoreBodegaRequest;
use App\Http\Requests\Catalogos\StoreProductoRequest;
use App\Models\Bodega;
use App\Models\Producto;
use App\Models\User;
use App\Services\Catalogos\TraduceDuplicados;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\MatrizPermisos;

const BASE_CLAVE = 'Clave-Sintetica-2026!';

function usuarioConClave(string $rol, string $correo): User
{
    return usuarioConRol($rol, [
        'email' => $correo,
        'password' => Hash::make(BASE_CLAVE),
    ]);
}

dataset('base_roles', array_combine(MatrizPermisos::ROLES, array_map(fn ($r) => [$r], MatrizPermisos::ROLES)));

describe('BASE-AC1 login con credenciales válidas', function () {
    it('BASE-AC1: devuelve 200 con token Bearer y rol, y el token autentica al usuario', function (string $rol) {
        $usuario = usuarioConClave($rol, "usuario.{$rol}@farma.test");

        $respuesta = $this->postJson('/api/v1/auth/login', [
            'email' => "usuario.{$rol}@farma.test",
            'password' => BASE_CLAVE,
        ]);

        $respuesta->assertOk();
        $datos = cuerpoDatos($respuesta);

        expect($datos)->toHaveKeys(['token', 'rol'])
            ->and($datos['token'])->toBeString()->not->toBe('')
            ->and($datos['rol'])->toBe($rol)
            ->and($respuesta->getContent())->not->toContain($usuario->getAuthPassword())
            ->and($respuesta->getContent())->not->toContain('"password"');

        // Se emitió exactamente un token Sanctum para ese usuario.
        expect(PersonalAccessToken::query()
            ->where('tokenable_type', $usuario->getMorphClass())
            ->where('tokenable_id', $usuario->id)
            ->count())->toBe(1);

        // El token es un Bearer real: identifica al mismo usuario en /auth/me.
        olvidarAutenticacion();
        $me = $this->withToken($datos['token'])->getJson('/api/v1/auth/me');

        $me->assertOk();
        $datosMe = cuerpoDatos($me);
        expect($datosMe['id'] ?? null)->toBe($usuario->id)
            ->and($datosMe['rol'] ?? null)->toBe($rol)
            ->and($datosMe)->not->toHaveKey('password');
    })->with('base_roles');
});

describe('BASE-AC2 credenciales inválidas', function () {
    it('BASE-AC2: responde 401 CREDENCIALES_INVALIDAS sin emitir token', function (string $caso) {
        usuarioConClave('regente_farmacia', 'regente@farma.test');
        usuarioConClave('auxiliar_farmacia', 'auxiliar@farma.test');

        $cuerpo = match ($caso) {
            'contraseña errónea' => ['email' => 'regente@farma.test', 'password' => 'otra-clave-123'],
            'correo inexistente' => ['email' => 'nadie@farma.test', 'password' => BASE_CLAVE],
            'contraseña con mayúsculas cambiadas' => ['email' => 'regente@farma.test', 'password' => strtolower(BASE_CLAVE)],
            'contraseña con espacio final' => ['email' => 'regente@farma.test', 'password' => BASE_CLAVE.' '],
            'correo inexistente y contraseña errónea' => ['email' => 'nadie@farma.test', 'password' => 'otra-clave-123'],
        };

        $respuesta = $this->postJson('/api/v1/auth/login', $cuerpo);

        esperarError($respuesta, 401, 'CREDENCIALES_INVALIDAS');
        expect($respuesta->getContent())->not->toContain('"token"')
            ->and(PersonalAccessToken::query()->count())->toBe(0);
    })->with([
        'contraseña errónea',
        'correo inexistente',
        'contraseña con mayúsculas cambiadas',
        'contraseña con espacio final',
        'correo inexistente y contraseña errónea',
    ]);

    it('BASE-AC2: correo existente con contraseña errónea y correo inexistente dan exactamente el mismo error', function () {
        usuarioConClave('medico', 'medico@farma.test');

        $correoExistente = $this->postJson('/api/v1/auth/login', [
            'email' => 'medico@farma.test',
            'password' => 'clave-equivocada-1',
        ]);
        $correoInexistente = $this->postJson('/api/v1/auth/login', [
            'email' => 'no.existe@farma.test',
            'password' => 'clave-equivocada-1',
        ]);

        esperarError($correoExistente, 401, 'CREDENCIALES_INVALIDAS');
        esperarError($correoInexistente, 401, 'CREDENCIALES_INVALIDAS');

        // Mismo mensaje y mismo cuerpo completo: no se puede enumerar qué correos existen.
        expect($correoExistente->json('error.mensaje'))->toBe($correoInexistente->json('error.mensaje'))
            ->and($correoExistente->json())->toBe($correoInexistente->json())
            ->and(PersonalAccessToken::query()->count())->toBe(0);
    });

    it('BASE-AC2: la contraseña de otro usuario no sirve para entrar', function () {
        usuarioConClave('auditor', 'auditor@farma.test');
        usuarioConRol('admin', ['email' => 'admin@farma.test', 'password' => Hash::make('clave-del-admin-9')]);

        $respuesta = $this->postJson('/api/v1/auth/login', [
            'email' => 'auditor@farma.test',
            'password' => 'clave-del-admin-9',
        ]);

        esperarError($respuesta, 401, 'CREDENCIALES_INVALIDAS');
        expect(PersonalAccessToken::query()->count())->toBe(0);
    });

    it('BASE-AC2: login sin correo o sin contraseña responde 422 VALIDACION con detalles.campos', function (array $cuerpo, string $campoFaltante) {
        usuarioConClave('regente_farmacia', 'regente@farma.test');

        $respuesta = $this->postJson('/api/v1/auth/login', $cuerpo);

        esperarError($respuesta, 422, 'VALIDACION');
        expect($respuesta->json('error.detalles.campos'))->toBeArray()->toHaveKey($campoFaltante)
            ->and(PersonalAccessToken::query()->count())->toBe(0);
    })->with([
        'sin correo' => [['password' => BASE_CLAVE], 'email'],
        'sin contraseña' => [['email' => 'regente@farma.test'], 'password'],
        'cuerpo vacío' => [[], 'email'],
        'correo vacío' => [['email' => '', 'password' => BASE_CLAVE], 'email'],
        'contraseña vacía' => [['email' => 'regente@farma.test', 'password' => ''], 'password'],
    ]);
});

describe('BASE-AC3 rutas protegidas sin token o con token revocado', function () {
    it('BASE-AC3: sin token responde 401 NO_AUTENTICADO y no cambia nada', function (string $clave) {
        $def = MatrizPermisos::endpoint($clave);
        [$uri, $cuerpo, $cabeceras] = MatrizPermisos::preparar($clave);
        $antes = MatrizPermisos::instantanea($clave);

        $respuesta = $this->json($def['metodo'], $uri, $cuerpo, $cabeceras);

        esperarError($respuesta, 401, 'NO_AUTENTICADO');
        expect(MatrizPermisos::instantanea($clave))->toBe($antes);
    })->with(MatrizPermisos::casosEndpoint());

    it('BASE-AC3: con token revocado por POST /api/v1/auth/logout responde 401 NO_AUTENTICADO y no cambia nada', function (string $clave) {
        $def = MatrizPermisos::endpoint($clave);
        // Un rol que SÍ tiene permiso: así el 401 solo puede deberse al token revocado.
        $usuario = usuarioConRol($def['permitidos'][0]);
        $token = $usuario->createToken('test')->plainTextToken;

        $logout = $this->withToken($token)->postJson('/api/v1/auth/logout');
        $logout->assertSuccessful();
        expect($usuario->tokens()->count())->toBe(0);

        olvidarAutenticacion();
        [$uri, $cuerpo, $cabeceras] = MatrizPermisos::preparar($clave);
        $antes = MatrizPermisos::instantanea($clave);

        $respuesta = $this->withToken($token)->json($def['metodo'], $uri, $cuerpo, $cabeceras);

        esperarError($respuesta, 401, 'NO_AUTENTICADO');
        expect(MatrizPermisos::instantanea($clave))->toBe($antes);
    })->with(MatrizPermisos::casosEndpoint());

    it('BASE-AC3: logout solo revoca el token usado; otro token del mismo usuario sigue válido', function () {
        $usuario = usuarioConRol('regente_farmacia');
        $tokenA = $usuario->createToken('dispositivo-a')->plainTextToken;
        $tokenB = $usuario->createToken('dispositivo-b')->plainTextToken;

        $this->withToken($tokenA)->postJson('/api/v1/auth/logout')->assertSuccessful();

        olvidarAutenticacion();
        esperarError($this->withToken($tokenA)->getJson('/api/v1/auth/me'), 401, 'NO_AUTENTICADO');

        olvidarAutenticacion();
        $this->withToken($tokenB)->getJson('/api/v1/auth/me')->assertOk();
        expect($usuario->tokens()->count())->toBe(1);
    });

    it('BASE-AC3: token inválido o malformado responde 401 NO_AUTENTICADO', function (string $caso) {
        $usuario = usuarioConRol('auxiliar_farmacia');
        $real = $usuario->createToken('test');

        $token = match ($caso) {
            'texto aleatorio' => 'esto-no-es-un-token',
            'id inexistente' => '999999|'.str_repeat('a', 40),
            'id real con secreto equivocado' => $real->accessToken->id.'|'.str_repeat('b', 40),
            'token borrado directamente de la BD' => (function () use ($real) {
                DB::table('personal_access_tokens')->where('id', $real->accessToken->id)->delete();

                return $real->plainTextToken;
            })(),
        };

        $respuesta = $this->withToken($token)->getJson('/api/v1/auth/me');

        esperarError($respuesta, 401, 'NO_AUTENTICADO');
    })->with([
        'texto aleatorio',
        'id inexistente',
        'id real con secreto equivocado',
        'token borrado directamente de la BD',
    ]);

    it('BASE-AC3: sin token y sin cabecera Accept JSON también responde 401 NO_AUTENTICADO en JSON (sin redirigir a login)', function () {
        $respuesta = $this->get('/api/v1/auth/me');

        esperarError($respuesta, 401, 'NO_AUTENTICADO');
    });

    it('BASE-AC3: un token revocado no puede volver a hacer logout', function () {
        $usuario = usuarioConRol('medico');
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertSuccessful();

        olvidarAutenticacion();
        esperarError($this->withToken($token)->postJson('/api/v1/auth/logout'), 401, 'NO_AUTENTICADO');
    });
});

describe('BASE-AC4 matriz rol × endpoint', function () {
    it('BASE-AC4: cada rol obtiene 2xx si la matriz lo permite y 403 NO_AUTORIZADO si no, sin efectos al denegar', function (string $rol, string $clave) {
        $usuario = actuarComo($rol);
        $def = MatrizPermisos::endpoint($clave);
        [$uri, $cuerpo, $cabeceras] = MatrizPermisos::preparar($clave);
        $antes = MatrizPermisos::instantanea($clave);

        $respuesta = $this->json($def['metodo'], $uri, $cuerpo, $cabeceras);

        if (MatrizPermisos::permitido($rol, $clave)) {
            $respuesta->assertSuccessful();
            if (isset($def['verificar'])) {
                ($def['verificar'])($respuesta, $antes, $usuario);
            }

            return;
        }

        esperarError($respuesta, 403, 'NO_AUTORIZADO');
        expect(MatrizPermisos::instantanea($clave))->toBe($antes);
    })->with(MatrizPermisos::casosRolEndpoint());

    it('BASE-AC4: admin con cuerpo vacío en un alta de catálogo recibe 422 VALIDACION con detalles.campos y no se crea nada', function (string $uri, string $modelo) {
        actuarComo('admin');
        $antes = $modelo::query()->count();

        $respuesta = $this->postJson($uri, []);

        esperarError($respuesta, 422, 'VALIDACION');
        expect($respuesta->json('error.detalles.campos'))->toBeArray()->not->toBeEmpty()
            ->and($modelo::query()->count())->toBe($antes);
    })->with([
        'bodegas' => ['/api/v1/bodegas', Bodega::class],
        'productos' => ['/api/v1/productos', Producto::class],
    ]);

    it('BASE-AC4: una ruta o un registro inexistente bajo /api/v1 responde 404 NO_ENCONTRADO con el formato de error', function (string $uri) {
        actuarComo('admin');

        $respuesta = $this->getJson($uri);

        esperarError($respuesta, 404, 'NO_ENCONTRADO');
        expect($respuesta->getContent())->not->toContain('App\\\\Models');
    })->with([
        'ruta inexistente' => ['/api/v1/ruta-que-no-existe'],
        'bodega inexistente' => ['/api/v1/bodegas/999999'],
        'producto inexistente' => ['/api/v1/productos/999999'],
    ]);

    it('BASE-AC4: límite general de peticiones → 429 DEMASIADAS_SOLICITUDES por usuario, sin efectos', function () {
        // La suite usa un límite alto (phpunit.xml); aquí se baja para alcanzarlo.
        config(['farma.api_peticiones_por_minuto' => 3]);

        actuarComo('admin');
        foreach (range(1, 3) as $intento) {
            $this->getJson('/api/v1/bodegas')->assertOk();
        }

        $antes = Bodega::query()->count();
        $respuesta = $this->postJson('/api/v1/bodegas', Bodega::factory()->raw());

        esperarError($respuesta, 429, 'DEMASIADAS_SOLICITUDES');
        expect($respuesta->headers->get('Retry-After'))->not->toBeNull()
            ->and(Bodega::query()->count())->toBe($antes);

        // El contador es por usuario: otro usuario no queda bloqueado.
        actuarComo('auditor');
        $this->getJson('/api/v1/bodegas')->assertOk();
    });

    it('BASE-AC4: toda ruta autenticada de /api/v1 pasa por el límite general throttle:api', function () {
        $autenticadas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($ruta): bool => str_starts_with($ruta->uri(), 'api/v1/')
                && in_array('auth:sanctum', $ruta->gatherMiddleware(), true));

        expect($autenticadas)->not->toBeEmpty();
        foreach ($autenticadas as $ruta) {
            expect(in_array('throttle:api', $ruta->gatherMiddleware(), true))
                ->toBeTrue("sin límite general: {$ruta->uri()}");
        }
    });

    it('BASE-AC4: toda ruta autenticada de /api/v1 está declarada en la matriz de permisos (y la matriz no tiene entradas huérfanas)', function () {
        // "MÉTODO /uri" con los parámetros normalizados a {} ({bodega} en la ruta, {id} en la matriz).
        $normalizar = fn (string $metodo, string $uri): string => strtoupper($metodo).' /'
            .preg_replace('/\{[^}]+\}/', '{}', ltrim($uri, '/'));

        $rutas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($ruta): bool => str_starts_with($ruta->uri(), 'api/v1/')
                && in_array('auth:sanctum', $ruta->gatherMiddleware(), true))
            ->flatMap(fn ($ruta): array => array_map(
                fn (string $metodo): string => $normalizar($metodo, $ruta->uri()),
                array_values(array_diff($ruta->methods(), ['HEAD'])),
            ))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $matriz = collect(MatrizPermisos::endpoints())
            ->map(function (array $def, string $clave) use ($normalizar): string {
                [$metodo, $uri] = explode(' ', $clave, 2);
                expect($def['metodo'])->toBe($metodo, "la clave {$clave} no coincide con su 'metodo'");

                return $normalizar($metodo, $uri);
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        expect($rutas)->not->toBeEmpty()
            ->and(array_values(array_diff($rutas, $matriz)))->toBe([], 'rutas sin permisos declarados en tests/Concerns/MatrizPermisos.php')
            ->and(array_values(array_diff($matriz, $rutas)))->toBe([], 'entradas de la matriz sin ruta autenticada');
    });
});

describe('BASE-AC4 S-88 paginación de bodegas y productos', function () {
    dataset('base_catalogos_listados', [
        'bodegas' => ['/api/v1/bodegas', Bodega::class],
        'productos' => ['/api/v1/productos', Producto::class],
    ]);

    it('BASE-AC4 S-88: sin por_pagina devuelve 25 por página y el total real', function (string $uri, string $modelo) {
        actuarComo('admin');
        $modelo::factory()->count(30)->create();

        $r = $this->getJson($uri);

        $r->assertOk();
        expect($r->json('data'))->toHaveCount(25)
            ->and($r->json('meta.per_page'))->toBe(25)
            ->and($r->json('meta.total'))->toBe(30)
            ->and($r->json('meta.last_page'))->toBe(2);
    })->with('base_catalogos_listados');

    it('BASE-AC4 S-88: por_pagina admite los extremos 1 y 100', function (string $uri, string $modelo, int $porPagina, int $esperadas) {
        actuarComo('admin');
        $modelo::factory()->count(101)->create();

        $r = $this->getJson($uri.'?por_pagina='.$porPagina);

        $r->assertOk();
        expect($r->json('data'))->toHaveCount($esperadas)
            ->and($r->json('meta.per_page'))->toBe($porPagina)
            ->and($r->json('meta.total'))->toBe(101);
    })->with('base_catalogos_listados')->with([
        'mínimo (1)' => [1, 1],
        'máximo (100)' => [100, 100],
    ]);

    it('BASE-AC4 S-88: por_pagina o page fuera de rango o mal formados → 422 VALIDACION con detalles.campos (nunca 500) y sin cambios', function (string $uri, string $modelo, string $campo, string $valor) {
        actuarComo('admin');
        $modelo::factory()->count(2)->create();
        $antes = $modelo::query()->count();

        $r = $this->getJson($uri.'?'.http_build_query([$campo => $valor]));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and($modelo::query()->count())->toBe($antes);
    })->with('base_catalogos_listados')->with([
        'por_pagina 0' => ['por_pagina', '0'],
        'por_pagina 101' => ['por_pagina', '101'],
        'por_pagina no numérico' => ['por_pagina', 'abc'],
        'page 0' => ['page', '0'],
        'page 1.000.001' => ['page', '1000001'],
        'page decimal' => ['page', '1.5'],
    ]);

    it('BASE-AC4 S-88: page 1.000.000 es válida y, vacía, responde 200 con data vacío', function (string $uri, string $modelo) {
        actuarComo('admin');
        $modelo::factory()->count(2)->create();

        $r = $this->getJson($uri.'?page=1000000');

        $r->assertOk();
        expect($r->json('data'))->toBe([])
            ->and($r->json('meta.total'))->toBe(2);
    })->with('base_catalogos_listados');

    it('BASE-AC4 S-88: con nombres repetidos la paginación es estable (orden por nombre e id, sin repetir ni perder registros)', function () {
        actuarComo('admin');
        $ids = Producto::factory()->count(3)->create(['nombre' => 'Producto repetido'])->pluck('id')->sort()->values()->all();

        $vistos = [];
        foreach ([1, 2, 3] as $pagina) {
            $r = $this->getJson('/api/v1/productos?por_pagina=1&page='.$pagina);
            $r->assertOk();
            $vistos[] = $r->json('data.0.id');
        }

        expect($vistos)->toBe($ids);
    });
});

describe('BASE-AC2 login: correo normalizado, límites de intentos y contrato del token', function () {
    it('BASE-AC2: un usuario registrado con mayúsculas y espacios se guarda en minúsculas y entra con cualquier variante', function (string $correoLogin) {
        $usuario = usuarioConClave('regente_farmacia', '  Regente.Mayus@Farma.TEST ');

        expect($usuario->email)->toBe('regente.mayus@farma.test')
            ->and(DB::table('users')->where('id', $usuario->id)->value('email'))->toBe('regente.mayus@farma.test');

        $respuesta = $this->postJson('/api/v1/auth/login', ['email' => $correoLogin, 'password' => BASE_CLAVE]);

        $respuesta->assertOk();
        expect(cuerpoDatos($respuesta)['usuario']['id'] ?? null)->toBe($usuario->id)
            ->and($usuario->tokens()->count())->toBe(1);
    })->with([
        'minúsculas' => ['regente.mayus@farma.test'],
        'como se registró (sin espacios)' => ['Regente.Mayus@Farma.TEST'],
        'todo mayúsculas' => ['REGENTE.MAYUS@FARMA.TEST'],
    ]);

    it('BASE-AC2: un usuario insertado por SQL directo con mayúsculas entra con cualquier variante del correo', function (string $correoLogin) {
        $id = DB::table('users')->insertGetId([
            'name' => 'Ana Prueba',
            'email' => 'Ana.Prueba@Farma.Test',
            'password' => Hash::make(BASE_CLAVE),
            'rol' => 'auditor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $respuesta = $this->postJson('/api/v1/auth/login', ['email' => $correoLogin, 'password' => BASE_CLAVE]);

        $respuesta->assertOk();
        expect(cuerpoDatos($respuesta)['usuario']['id'] ?? null)->toBe($id)
            ->and(PersonalAccessToken::query()->where('tokenable_id', $id)->count())->toBe(1)
            ->and(DB::table('users')->count())->toBe(1);
    })->with([
        'minúsculas' => ['ana.prueba@farma.test'],
        'variante original' => ['Ana.Prueba@Farma.Test'],
    ]);

    it('BASE-AC2: la búsqueda del login usa el índice único users_email_lower_unique', function () {
        usuarioConClave('auditor', 'indice@farma.test');

        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas): void {
            if (preg_match('/\bfrom "users"/i', $consulta->sql) === 1) {
                $consultas[] = $consulta;
            }
        });

        $this->postJson('/api/v1/auth/login', ['email' => 'Indice@Farma.Test', 'password' => BASE_CLAVE])->assertOk();

        expect($consultas)->not->toBeEmpty();
        $busqueda = $consultas[0];

        // Con tablas de pocas filas el planificador prefiere Seq Scan aunque el índice sirva;
        // desactivarlo (solo en esta transacción) deja ver si la consulta PUEDE usar el índice:
        // con where('email', …) no puede y el plan sigue siendo Seq Scan.
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select('EXPLAIN '.$busqueda->sql, $busqueda->bindings))
            ->map(fn (object $fila): string => (string) $fila->{'QUERY PLAN'})
            ->implode("\n");

        expect($plan)->toContain('users_email_lower_unique')
            ->and($plan)->not->toContain('Seq Scan');
    });

    it('BASE-AC2: expira_en_minutos es entero (aunque la configuración llegue como texto del .env) o null', function (mixed $configurado, ?int $esperado) {
        config(['sanctum.expiration' => $configurado]);
        usuarioConClave('auditor', 'auditor@farma.test');

        $respuesta = $this->postJson('/api/v1/auth/login', ['email' => 'auditor@farma.test', 'password' => BASE_CLAVE]);

        $respuesta->assertOk();
        expect($respuesta->json())->toHaveKey('expira_en_minutos')
            ->and($respuesta->json('expira_en_minutos'))->toBe($esperado)
            ->and($respuesta->getContent())->toContain('"expira_en_minutos":'.($esperado ?? 'null'));
    })->with([
        'texto del .env' => ['720', 720],
        'entero' => [60, 60],
        'sin expiración' => [null, null],
    ]);
});

describe('BASE-AC4 límites de login por correo y por IP', function () {
    it('BASE-AC4: el mismo correo desde IPs distintas se bloquea al superar el límite por correo → 429 sin emitir token', function () {
        config(['farma.login_intentos_por_correo_por_minuto' => 3, 'farma.login_intentos_por_ip_por_minuto' => 100]);
        usuarioConClave('regente_farmacia', 'regente@farma.test');

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            esperarError(
                $this->withServerVariables(['REMOTE_ADDR' => $ip])
                    ->postJson('/api/v1/auth/login', ['email' => 'regente@farma.test', 'password' => 'mala-clave-1']),
                401,
                'CREDENCIALES_INVALIDAS',
            );
        }

        // Cuarta IP distinta y contraseña correcta: el límite por correo ya se alcanzó.
        $respuesta = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->postJson('/api/v1/auth/login', ['email' => 'REGENTE@farma.test', 'password' => BASE_CLAVE]);

        esperarError($respuesta, 429, 'DEMASIADAS_SOLICITUDES');
        expect($respuesta->headers->get('Retry-After'))->not->toBeNull()
            ->and(PersonalAccessToken::query()->count())->toBe(0);

        // Otro correo no queda bloqueado por el límite por correo.
        usuarioConClave('medico', 'medico@farma.test');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->postJson('/api/v1/auth/login', ['email' => 'medico@farma.test', 'password' => BASE_CLAVE])
            ->assertOk();
    });

    it('BASE-AC4: correos distintos desde la misma IP se bloquean al superar el límite por IP → 429 sin emitir token', function () {
        config(['farma.login_intentos_por_correo_por_minuto' => 100, 'farma.login_intentos_por_ip_por_minuto' => 3]);
        usuarioConClave('auxiliar_farmacia', 'auxiliar@farma.test');

        foreach (['uno', 'dos', 'tres'] as $n) {
            esperarError(
                $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
                    ->postJson('/api/v1/auth/login', ['email' => "{$n}@farma.test", 'password' => 'mala-clave-1']),
                401,
                'CREDENCIALES_INVALIDAS',
            );
        }

        $respuesta = $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->postJson('/api/v1/auth/login', ['email' => 'auxiliar@farma.test', 'password' => BASE_CLAVE]);

        esperarError($respuesta, 429, 'DEMASIADAS_SOLICITUDES');
        expect(PersonalAccessToken::query()->count())->toBe(0);

        // Otra IP no queda bloqueada.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])
            ->postJson('/api/v1/auth/login', ['email' => 'auxiliar@farma.test', 'password' => BASE_CLAVE])
            ->assertOk();
    });
});

describe('BASE-AC4 identificadores de ruta inválidos', function () {
    it('BASE-AC4: un id no numérico o fuera del rango bigint responde 404 NO_ENCONTRADO (nunca 500)', function (string $uri) {
        actuarComo('admin');
        Bodega::factory()->create();
        Producto::factory()->create();

        $respuesta = $this->getJson($uri);

        esperarError($respuesta, 404, 'NO_ENCONTRADO');
        expect($respuesta->getContent())->not->toContain('SQLSTATE')
            ->and($respuesta->getContent())->not->toContain('select');
    })->with([
        'bodega texto' => ['/api/v1/bodegas/abc'],
        'bodega negativa' => ['/api/v1/bodegas/-1'],
        'bodega decimal' => ['/api/v1/bodegas/1.5'],
        'bodega fuera de bigint' => ['/api/v1/bodegas/99999999999999999999'],
        'bodega justo sobre bigint' => ['/api/v1/bodegas/9223372036854775808'],
        'producto texto' => ['/api/v1/productos/abc'],
        'producto fuera de bigint' => ['/api/v1/productos/99999999999999999999'],
        'producto con espacio' => ['/api/v1/productos/1%20'],
    ]);

    it('BASE-AC4: todo parámetro de ruta de /api/v1 restringe su formato (las RN nuevas heredan el patrón)', function () {
        $rutas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($ruta): bool => str_starts_with($ruta->uri(), 'api/v1/') && $ruta->parameterNames() !== []);

        expect($rutas)->not->toBeEmpty();
        foreach ($rutas as $ruta) {
            foreach ($ruta->parameterNames() as $parametro) {
                expect(array_key_exists($parametro, $ruta->wheres))
                    ->toBeTrue("parámetro {{$parametro}} sin patrón en {$ruta->uri()}");
            }
        }
    });

    it('BASE-AC4: red de seguridad: un valor que PostgreSQL no puede convertir (22P02/22003) responde 422 DATO_INVALIDO, nunca 500', function (string $valor) {
        // Ruta de prueba SIN patrón: simula una RN futura que olvide registrarlo.
        Route::middleware(['api', 'auth:sanctum'])
            ->get('api/v1/prueba-sin-patron/{bodegaLibre}', fn (Bodega $bodegaLibre) => ['id' => $bodegaLibre->id]);
        actuarComo('admin');

        $respuesta = $this->getJson('/api/v1/prueba-sin-patron/'.$valor);

        esperarError($respuesta, 422, 'DATO_INVALIDO');
        expect($respuesta->getContent())->not->toContain('SQLSTATE');
    })->with([
        'texto (22P02)' => ['abc'],
        'fuera de bigint (22003)' => ['99999999999999999999'],
    ]);
});

/**
 * Envía el log de la aplicación a un archivo temporal propio del test y devuelve su ruta.
 */
function logDeTest(): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'farma-log-');
    config([
        'logging.channels.prueba_sin_bindings' => ['driver' => 'single', 'path' => $ruta, 'level' => 'debug'],
        'logging.default' => 'prueba_sin_bindings',
    ]);

    return $ruta;
}

describe('BASE-AC4 errores de BD: sin SQL ni valores en el log (RN-10)', function () {
    it('BASE-AC4: un 22P02 por un valor del cliente responde 422 DATO_INVALIDO y el log no contiene el valor ni el SQL', function () {
        $log = logDeTest();
        Route::middleware(['api', 'auth:sanctum'])
            ->get('api/v1/prueba-sin-patron/{bodegaLibre}', fn (Bodega $bodegaLibre) => ['id' => $bodegaLibre->id]);
        actuarComo('admin');

        $respuesta = $this->getJson('/api/v1/prueba-sin-patron/ValorSecreto9988');

        esperarError($respuesta, 422, 'DATO_INVALIDO');
        $contenido = (string) file_get_contents($log);
        expect($contenido)->not->toContain('ValorSecreto9988')
            ->and(strtolower($contenido))->not->toContain('select')
            ->and($contenido)->toContain('22P02');
    });

    it('BASE-AC4: un 22003 que no proviene de un valor del cliente responde 500 ERROR_INTERNO sin SQL en el log', function () {
        $log = logDeTest();
        Route::middleware(['api', 'auth:sanctum'])
            ->get('api/v1/prueba-desbordamiento', fn () => DB::select('SELECT 9223372036854775807::bigint + ?::bigint AS total', [4321]));
        actuarComo('admin');

        $respuesta = $this->getJson('/api/v1/prueba-desbordamiento');

        esperarError($respuesta, 500, 'ERROR_INTERNO');
        $contenido = (string) file_get_contents($log);
        expect($contenido)->not->toContain('4321')
            ->and($contenido)->not->toContain('9223372036854775807')
            ->and(strtolower($contenido))->not->toContain('select')
            ->and($contenido)->toContain('22003');
    });

    it('BASE-AC4: cualquier otro error de BD (p. ej. NOT NULL) responde 500 y se registra sin SQL ni valores', function () {
        $log = logDeTest();
        Route::middleware(['api', 'auth:sanctum'])
            // En un savepoint: el error no debe abortar la transacción de RefreshDatabase.
            ->post('api/v1/prueba-error-bd', fn () => DB::transaction(
                fn () => DB::table('pacientes')->insert(['numero_documento' => 'DocSecreto7777']),
            ));
        actuarComo('admin');

        $respuesta = $this->postJson('/api/v1/prueba-error-bd');

        esperarError($respuesta, 500, 'ERROR_INTERNO');
        expect(DB::table('pacientes')->count())->toBe(0);
        $contenido = (string) file_get_contents($log);
        expect($contenido)->not->toContain('DocSecreto7777')
            ->and(strtolower($contenido))->not->toContain('insert into')
            ->and($contenido)->toContain('23502')
            ->and($contenido)->toContain('api/v1/prueba-error-bd');
    });
});

describe('BASE-AC4 código de catálogo duplicado en carrera', function () {
    it('BASE-AC4: si otro proceso inserta el mismo código tras pasar la validación, responde 409 CODIGO_DUPLICADO sin crear nada más', function (string $uri, string $modelo, string $request, array $cuerpo, string $campo) {
        actuarComo('admin');

        // Simula la carrera: justo después de que el Form Request valide (su afterResolving se
        // registró antes que este) y antes del servicio, "otra petición" inserta una fila que
        // solo coincide en el campo único probado.
        app()->afterResolving($request, function () use ($modelo, $cuerpo, $campo): void {
            DB::table((new $modelo)->getTable())->insert(
                $modelo::factory()->raw([$campo => $cuerpo[$campo]]) + ['created_at' => now(), 'updated_at' => now()],
            );
        });

        $respuesta = $this->postJson($uri, $cuerpo);

        esperarError($respuesta, 409, 'CODIGO_DUPLICADO');
        expect($respuesta->json('error.detalles'))->toBe(['campo' => $campo])
            ->and($respuesta->getContent())->not->toContain('SQLSTATE')
            ->and($respuesta->getContent())->not->toContain($cuerpo[$campo])
            // Solo queda la fila de la "otra petición"; la conexión sigue utilizable.
            ->and($modelo::query()->where($campo, $cuerpo[$campo])->count())->toBe(1)
            ->and($modelo::query()->where('codigo', $cuerpo['codigo'])->count())->toBe($campo === 'codigo' ? 1 : 0);
    })->with([
        'bodega' => ['/api/v1/bodegas', Bodega::class, StoreBodegaRequest::class, ['codigo' => 'CARRERA1', 'nombre' => 'Bodega carrera'], 'codigo'],
        'bodega (nombre)' => ['/api/v1/bodegas', Bodega::class, StoreBodegaRequest::class, ['codigo' => 'CARRERA3', 'nombre' => 'Bodega carrera nombre'], 'nombre'],
        'producto' => ['/api/v1/productos', Producto::class, StoreProductoRequest::class, ['codigo' => 'CARRERA2', 'nombre' => 'Producto carrera', 'unidad_medida' => 'tableta', 'control_especial' => false], 'codigo'],
    ]);

    it('BASE-AC4: una violación de unicidad de una restricción no declarada no se disfraza de CODIGO_DUPLICADO: se relanza y no crea nada', function () {
        $existente = Producto::factory()->create();
        $servicio = new class
        {
            use TraduceDuplicados;

            /** @param  array<string, string>  $restricciones */
            public function insertar(Closure $insertar, array $restricciones): mixed
            {
                return $this->insertarSinDuplicados($insertar, $restricciones);
            }
        };

        // Solo se declara la restricción de bodegas: la de productos no debe traducirse.
        expect(fn () => $servicio->insertar(
            fn () => Producto::query()->create(Producto::factory()->raw(['codigo' => $existente->codigo])),
            ['bodegas_codigo_unique' => 'codigo'],
        ))->toThrow(UniqueConstraintViolationException::class);

        // La transacción exterior sigue utilizable y no hay fila nueva.
        expect(Producto::query()->where('codigo', $existente->codigo)->count())->toBe(1);
    });
});

describe('BASE-AC4 denegación decidida en un servicio', function () {
    it('BASE-AC4: la excepción de dominio NoAutorizado responde 403 NO_AUTORIZADO con el mismo formato y mensaje que la policy', function () {
        actuarComo('auditor');
        Route::middleware('api')->get('/api/v1/prueba-no-autorizado', fn () => throw new NoAutorizado);

        $respuesta = $this->getJson('/api/v1/prueba-no-autorizado');

        esperarError($respuesta, 403, 'NO_AUTORIZADO');
        expect($respuesta->json('error.mensaje'))->toBe('No tiene permiso para realizar esta acción.')
            ->and($respuesta->json('error.detalles'))->toBe([]);
    });
});
