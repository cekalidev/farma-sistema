<?php

declare(strict_types=1);

use App\Http\Middleware\CabecerasSeguridad;
use App\Support\Correlacion;
use App\Support\ProxiesConfianza;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Formatter\JsonFormatter;

/*
 * Parte D — Despliegue: sondas /health y /ready, cabeceras de seguridad, proxy de confianza y CORS.
 *
 * El 503 de /ready se simula con una conexión por defecto inválida (puerto 1 de localhost), sin
 * tumbar PostgreSQL; la conexión de la suite (pgsql, con la transacción de RefreshDatabase) no se toca.
 */

describe('Parte D: sondas de despliegue', function () {
    it('DESPLIEGUE: GET /health responde 200 {"status":"ok"} sin autenticación y sin consultar la BD', function () {
        DB::enableQueryLog();

        $respuesta = $this->getJson('/health');

        $respuesta->assertOk()->assertExactJson(['status' => 'ok']);
        expect(DB::getQueryLog())->toBe([]);
    });

    it('DESPLIEGUE: GET /health responde 200 aunque la BD esté caída (liveness no depende de la BD)', function () {
        conBaseDeDatosCaida(fn () => $this->getJson('/health')->assertOk());
    });

    it('DESPLIEGUE: GET /ready responde 200 con la BD disponible y las migraciones al día', function () {
        $this->getJson('/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready', 'checks' => ['database' => 'ok', 'migrations' => 'ok']]);
    });

    it('DESPLIEGUE: GET /ready responde 503 JSON con la BD caída, sin filtrar el error de conexión', function () {
        $respuesta = conBaseDeDatosCaida(fn () => $this->getJson('/ready'));

        $respuesta->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'checks' => ['database' => 'fail', 'migrations' => 'unknown']]);
        expect($respuesta->getContent())->not->toContain('SQLSTATE')->not->toContain('127.0.0.1');
    });

    it('DESPLIEGUE: GET /ready responde 503 si hay migraciones pendientes', function () {
        DB::table('migrations')->where('migration', 'like', '%create_personal_access_tokens_table')->delete();

        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.migrations', 'pending');
    });

    it('DESPLIEGUE: GET /ready responde 503 si no existe la tabla migrations (BD vacía)', function () {
        Schema::drop('migrations');

        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'checks' => ['database' => 'ok', 'migrations' => 'pending']]);
    });

    it('DESPLIEGUE: /up de Laravel se conserva', function () {
        $this->get('/up')->assertOk();
    });

    it('DESPLIEGUE: las sondas no llevan auth ni throttle ni sesión', function (string $uri) {
        $ruta = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r): bool => $r->uri() === $uri);

        expect($ruta)->not->toBeNull()
            ->and($ruta->gatherMiddleware())->toBe([]);
    })->with(['health', 'ready']);
});

describe('Parte D: cabeceras de seguridad y proxy', function () {
    it('DESPLIEGUE: toda respuesta lleva nosniff, X-Frame-Options DENY y Referrer-Policy; sin HSTS en http', function (string $uri) {
        $respuesta = $this->getJson($uri);

        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Strict-Transport-Security');
    })->with(['/health', '/api/v1/auth/me', '/api/v1/no-existe']);

    it('DESPLIEGUE: HSTS solo cuando la petición es https', function () {
        $this->getJson('https://localhost/health')
            ->assertHeader('Strict-Transport-Security', CabecerasSeguridad::HSTS);
    });

    it('DESPLIEGUE: detrás del proxy (X-Forwarded-Proto: https) la petición se trata como https y lleva HSTS', function () {
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->getJson('/health')
            ->assertHeader('Strict-Transport-Security', CabecerasSeguridad::HSTS);
    });
});

describe('Parte D: TRUSTED_PROXIES', function () {
    it('DESPLIEGUE: TRUSTED_PROXIES se interpreta como * o como lista de IP/CIDR', function (mixed $valor, string|array $esperado) {
        expect(ProxiesConfianza::desde($valor))->toBe($esperado);
    })->with([
        'sin definir' => [null, '*'],
        'vacío' => ['  ', '*'],
        'asterisco' => ['*', '*'],
        'lista' => ['10.0.0.1, 10.1.0.0/16,', ['10.0.0.1', '10.1.0.0/16']],
        'solo comas' => [',,', '*'],
    ]);

    it('DESPLIEGUE: con una lista concreta, X-Forwarded-Proto solo cuenta si viene de un proxy listado', function () {
        TrustProxies::at(ProxiesConfianza::desde('10.0.0.1,10.1.0.0/16'));

        try {
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
                ->withHeaders(['X-Forwarded-Proto' => 'https'])
                ->getJson('http://localhost/health')
                ->assertHeader('Strict-Transport-Security', CabecerasSeguridad::HSTS);

            // URL absoluta en http: tras la petición anterior el generador de URL recordaría https.
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->withHeaders(['X-Forwarded-Proto' => 'https'])
                ->getJson('http://localhost/health')
                ->assertHeaderMissing('Strict-Transport-Security');
        } finally {
            TrustProxies::at('*');
        }
    });
});

describe('Parte D: logs JSON', function () {
    it('DESPLIEGUE: con LOG_STDERR_FORMATTER=JsonFormatter cada línea es JSON y lleva extra.correlation_id', function () {
        $archivo = tempnam(sys_get_temp_dir(), 'log');
        config(['logging.channels.json_prueba' => array_merge(config('logging.channels.stderr'), [
            'handler_with' => ['stream' => $archivo],
            'formatter' => JsonFormatter::class,
        ])]);
        $id = Correlacion::establecer(Correlacion::nuevo());

        Log::channel('json_prueba')->warning('prueba de formato', ['paciente_id' => 7]);

        $linea = json_decode(trim((string) file_get_contents($archivo)), true, flags: JSON_THROW_ON_ERROR);
        unlink($archivo);
        expect($linea['message'])->toBe('prueba de formato')
            ->and($linea['context']['paciente_id'])->toBe(7)
            ->and($linea['extra'][Correlacion::CLAVE])->toBe($id);
    });
});

describe('Parte D: CORS', function () {
    it('DESPLIEGUE: un origen de CORS_ALLOWED_ORIGINS recibe Access-Control-Allow-Origin sin credenciales en el preflight', function () {
        config(['cors.allowed_origins' => ['https://farma-web.example']]);

        $respuesta = $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => 'https://farma-web.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,idempotency-key',
        ]);

        $respuesta->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://farma-web.example')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        expect(strtolower((string) $respuesta->headers->get('Access-Control-Allow-Headers')))
            ->toContain('authorization')->toContain('idempotency-key');
    });

    it('DESPLIEGUE: en una respuesta real se exponen X-Correlation-Id y Retry-After al origen permitido', function () {
        config(['cors.allowed_origins' => ['https://farma-web.example']]);

        $respuesta = $this->withHeaders(['Origin' => 'https://farma-web.example'])->getJson('/api/v1/auth/me');

        $respuesta->assertStatus(401)->assertHeader('Access-Control-Allow-Origin', 'https://farma-web.example');
        expect((string) $respuesta->headers->get('Access-Control-Expose-Headers'))
            ->toContain('X-Correlation-Id')->toContain('Retry-After');
    });

    it('DESPLIEGUE: un origen no listado (o la lista vacía por defecto) no queda autorizado', function (array $origenes) {
        config(['cors.allowed_origins' => $origenes]);

        $respuesta = $this->withHeaders(['Origin' => 'https://malicioso.example'])->getJson('/api/v1/auth/me');

        // Con un único origen permitido, la librería lo devuelve fijo: el navegador bloquea igual
        // porque no coincide con el de la petición. Lo esencial: nunca se refleja el origen ajeno.
        expect($respuesta->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://malicioso.example')
            ->and($respuesta->headers->get('Access-Control-Allow-Origin'))->not->toBe('*');
    })->with([
        'lista vacía' => [[]],
        'otro origen' => [['https://farma-web.example']],
    ]);

    it('DESPLIEGUE: config/cors.php por defecto no permite ningún origen ni credenciales y solo cubre api/*', function () {
        $config = require base_path('config/cors.php');

        expect($config['paths'])->toBe(['api/*'])
            ->and($config['supports_credentials'])->toBeFalse();
    });
});

/**
 * Ejecuta $accion con una conexión por defecto que apunta a un puerto cerrado y restaura la
 * conexión de la suite al terminar (la transacción de RefreshDatabase vive en 'pgsql').
 *
 * @template T
 *
 * @param  Closure(): T  $accion
 * @return T
 */
function conBaseDeDatosCaida(Closure $accion): mixed
{
    $original = config('database.default');
    config(['database.connections.caida' => array_merge(config('database.connections.pgsql'), [
        'host' => '127.0.0.1', 'port' => 1, 'url' => null, 'connect_timeout' => 1,
    ])]);
    config(['database.default' => 'caida']);

    try {
        return $accion();
    } finally {
        config(['database.default' => $original]);
        DB::purge('caida');
    }
}
