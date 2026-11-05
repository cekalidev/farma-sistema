<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Dispensacion;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * RN-09 — Dispensación idempotente (AC1..AC5, AC7, AC8). AC6 (simultaneidad real) va en
 * tests/Feature/SinTransaccion/RN09IdempotenciaConcurrenteTest.php y hashCanonico en
 * tests/Unit/RN09HashCanonicoTest.php.
 *
 * Supuestos: S-10 (misma clave + cuerpo distinto → 422 IDEMPOTENCY_KEY_REUTILIZADA), S-11 (solo se
 * guarda la clave de una dispensación creada; los errores no se reproducen; alcance por usuario;
 * no caduca), S-43 (la previsualización no escribe), S-47 (el orden de los ítems importa), S-50
 * (orden 401 → 403 → 400 → 422), S-51 (claves desconocidas en la raíz → 422) y S-52 (el reintento
 * reproduce sin revalidar, p. ej. con la bodega desactivada después).
 *
 * Contrato HTTP:
 *  - POST /api/v1/dispensaciones con cabecera `Idempotency-Key` OBLIGATORIA (1..255 caracteres,
 *    no vacía ni solo espacios); sin ella → 400 IDEMPOTENCY_KEY_REQUERIDA.
 *  - Reintento (mismo usuario, misma clave, mismo cuerpo canónico) → mismo status y mismo cuerpo
 *    JSON que la respuesta original, con `Idempotent-Replayed: true`. La respuesta original NO
 *    lleva esa cabecera.
 *  - Decisión documentada (AC4): el hash es sobre JSON canónico → el orden de las claves de los
 *    objetos no importa, pero el orden de los elementos de una lista sí (una lista JSON es
 *    ordenada y S-47 hace que el orden de los ítems cambie la respuesta): reordenar `items` con
 *    la misma clave → 422 IDEMPOTENCY_KEY_REUTILIZADA.
 *  - Columnas: dispensaciones.idempotency_key (la clave tal cual) y request_hash (64 hex).
 *
 * El stock se crea por el camino real del kardex (RegistradorKardex, ENTRADA con la existencia
 * bloqueada) para que la cadena de saldos cuadre (RN-06-AC2).
 */

const RN09_URI = '/api/v1/dispensaciones';
const RN09_URI_PREVIA = '/api/v1/dispensaciones/previsualizar';
const RN09_HOY = '2026-11-04 10:00:00';
const RN09_VENCE = '2027-06-30';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn09)
|--------------------------------------------------------------------------
*/

function rn09Congelar(string $fechaHoraBogota = RN09_HOY): void
{
    test()->travelTo(Carbon::parse($fechaHoraBogota, 'America/Bogota'));
}

/** Suma `cantidad` a la existencia con una ENTRADA real del kardex. */
function rn09Entrada(Existencia $existencia, int $cantidad): void
{
    $regente = User::query()->where('email', 'rn09-regente-servicio@farma.test')->first()
        ?? usuarioConRol('regente_farmacia', ['email' => 'rn09-regente-servicio@farma.test']);

    DB::transaction(function () use ($existencia, $cantidad, $regente): void {
        $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
        app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $regente->id);
    });
}

/** Lote nuevo del producto con `cantidad` en la bodega (existencia que nace en 0 + ENTRADA). */
function rn09Stock(Bodega $bodega, Producto $producto, int $cantidad, string $vence = RN09_VENCE): Existencia
{
    $lote = Lote::factory()->create(['producto_id' => $producto->id, 'fecha_vencimiento' => $vence]);
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);

    if ($cantidad > 0) {
        rn09Entrada($existencia, $cantidad);
    }

    return $existencia->refresh();
}

function rn09StockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/**
 * Escenario: dos bodegas (B1, B2) con stock de P1 y P2; prescripción vigente con un ítem holgado
 * por producto (1000 u.); y otra prescripción de OTRO paciente con un ítem de P1.
 *
 * @return array{b1: Bodega, b2: Bodega, p1: Producto, p2: Producto, e1: Existencia, e2: Existencia, e1b2: Existencia, e2b2: Existencia, prescripcion: Prescripcion, item1: PrescripcionItem, item2: PrescripcionItem, ajena: Prescripcion, itemAjeno: PrescripcionItem}
 */
function rn09Escenario(int $stock = 20): array
{
    $b1 = Bodega::factory()->create();
    $b2 = Bodega::factory()->create();
    $p1 = Producto::factory()->create();
    $p2 = Producto::factory()->create();

    $e1 = rn09Stock($b1, $p1, $stock);
    $e2 = rn09Stock($b1, $p2, $stock);
    $e1b2 = rn09Stock($b2, $p1, $stock);
    $e2b2 = rn09Stock($b2, $p2, $stock);

    $prescripcion = Prescripcion::factory()->create();
    $item1 = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $p1->id, 'cantidad' => 1000]);
    $item2 = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $p2->id, 'cantidad' => 1000]);

    $ajena = Prescripcion::factory()->create();
    $itemAjeno = PrescripcionItem::factory()->create(['prescripcion_id' => $ajena->id, 'producto_id' => $p1->id, 'cantidad' => 1000]);

    return compact('b1', 'b2', 'p1', 'p2', 'e1', 'e2', 'e1b2', 'e2b2', 'prescripcion', 'item1', 'item2', 'ajena', 'itemAjeno');
}

/**
 * Cuerpo de dispensación del escenario.
 *
 * @param  array<string, mixed>  $s
 * @param  list<array{0: PrescripcionItem, 1: mixed}>  $items
 * @return array<string, mixed>
 */
function rn09Cuerpo(array $s, array $items, ?Bodega $bodega = null, ?Prescripcion $prescripcion = null): array
{
    $prescripcion ??= $s['prescripcion'];

    return [
        'paciente_id' => $prescripcion->paciente_id,
        'prescripcion_id' => $prescripcion->id,
        'bodega_id' => ($bodega ?? $s['b1'])->id,
        'items' => array_map(fn (array $i): array => [
            'prescripcion_item_id' => $i[0]->id,
            'cantidad' => $i[1],
        ], $items),
    ];
}

/**
 * POST con la cabecera Idempotency-Key (null = sin cabecera).
 *
 * @param  array<string, mixed>  $cuerpo
 */
function rn09Post(array $cuerpo, ?string $clave, string $uri = RN09_URI): TestResponse
{
    $cabeceras = $clave === null ? [] : ['Idempotency-Key' => $clave];

    return test()->postJson($uri, $cuerpo, $cabeceras);
}

/** Autentica como otro usuario dentro del mismo test. */
function rn09Como(User $usuario): void
{
    olvidarAutenticacion();
    Sanctum::actingAs($usuario);
}

/**
 * Estado completo de las tablas que una dispensación toca.
 *
 * @return array<string, mixed>
 */
function rn09Instantanea(): array
{
    $filas = fn (string $tabla): array => DB::table($tabla)->orderBy('id')->get()
        ->map(fn (object $f): array => (array) $f)->all();

    return [
        'existencias' => $filas('existencias'),
        'kardex' => $filas('movimientos_kardex'),
        'dispensaciones' => $filas('dispensaciones'),
        'dispensacion_items' => $filas('dispensacion_items'),
        'dispensacion_detalles' => $filas('dispensacion_detalles'),
    ];
}

/** Cadena de saldos de la existencia desde 0 hasta su cantidad actual (RN-06-AC2). */
function rn09AfirmarCadena(Existencia $existencia): void
{
    $saldo = 0;
    foreach (DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->orderBy('id')->get() as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "existencia {$existencia->id}, movimiento #{$i}: saldo_anterior no encadena")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad);
        $saldo = (int) $m->saldo_posterior;
    }
    expect($saldo)->toBe(rn09StockDe($existencia), "existencia {$existencia->id}: el kardex no cuadra con el stock");
}

/** Respuesta original (no reproducida): 201, id entero y SIN cabecera Idempotent-Replayed. */
function rn09AfirmarOriginal(TestResponse $r): int
{
    $r->assertCreated();
    expect($r->headers->get('Idempotent-Replayed'))->toBeNull('la respuesta original no debe marcarse como reproducida');
    $id = cuerpoDatos($r)['id'] ?? null;
    expect($id)->toBeInt();

    return $id;
}

/** Reproducción: mismo status y mismo cuerpo JSON que el original, con Idempotent-Replayed: true. */
function rn09AfirmarReproducida(TestResponse $reintento, TestResponse $original): void
{
    $reintento->assertStatus($original->getStatusCode());
    expect($reintento->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($reintento->headers->get('Content-Type'))->toContain('application/json')
        ->and($reintento->json())->toBe($original->json());
}

/** La dispensación guardó la clave tal cual y un hash de 64 hex. */
function rn09AfirmarClaveGuardada(int $id, string $clave, int $usuarioId): void
{
    $fila = DB::table('dispensaciones')->where('id', $id)->first();
    expect($fila)->not->toBeNull()
        ->and($fila->idempotency_key)->toBe($clave)
        ->and((int) $fila->dispensado_por_id)->toBe($usuarioId)
        ->and($fila->request_hash)->toMatch('/^[0-9a-f]{64}$/');
}

/*
|--------------------------------------------------------------------------
| RN-09-AC1 reintento con misma clave y mismo cuerpo
|--------------------------------------------------------------------------
*/

describe('RN-09-AC1 reintento idéntico', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC1: reintentar con la misma clave y el mismo cuerpo reproduce status y cuerpo (mismo id) con Idempotent-Replayed: true; stock descontado una vez, una dispensación y un solo juego de movimientos', function (int $reintentos) {
        $s = rn09Escenario(20);
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 3], [$s['item2'], 2]]);
        $clave = 'rn09-ac1-'.$reintentos;

        $original = rn09Post($cuerpo, $clave);
        $id = rn09AfirmarOriginal($original);
        $tras = rn09Instantanea();

        expect(rn09StockDe($s['e1']))->toBe(17)
            ->and(rn09StockDe($s['e2']))->toBe(18)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->where('documento_id', $id)->count())->toBe(2);
        rn09AfirmarClaveGuardada($id, $clave, $usuario->id);

        for ($i = 1; $i <= $reintentos; $i++) {
            $reintento = rn09Post($cuerpo, $clave);
            rn09AfirmarReproducida($reintento, $original);
            expect(cuerpoDatos($reintento)['id'])->toBe($id, "reintento {$i}")
                ->and(rn09Instantanea())->toBe($tras, "reintento {$i}: el reintento cambió la BD");
        }

        expect(DB::table('dispensaciones')->count())->toBe(1)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(2)
            ->and(rn09StockDe($s['e1']))->toBe(17)
            ->and(rn09StockDe($s['e2']))->toBe(18);
        rn09AfirmarCadena($s['e1']);
        rn09AfirmarCadena($s['e2']);
    })->with([
        '1 reintento' => [1],
        '5 reintentos' => [5],
    ]);

    it('RN-09-AC1: el reintento se reproduce aunque el stock ya no alcance (agotado por otra dispensación): 201 con el cuerpo original, no 409, y nada se descuenta', function () {
        $s = rn09Escenario(5);
        actuarComo('regente_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 4]]);

        $original = rn09Post($cuerpo, 'rn09-ac1-agotado');
        $id = rn09AfirmarOriginal($original);

        // Otra dispensación (otra clave) se lleva la unidad restante: un reproceso daría 409.
        rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 1]]), 'rn09-ac1-otra'));
        expect(rn09StockDe($s['e1']))->toBe(0);
        $antes = rn09Instantanea();

        $reintento = rn09Post($cuerpo, 'rn09-ac1-agotado');
        rn09AfirmarReproducida($reintento, $original);
        expect(cuerpoDatos($reintento)['id'])->toBe($id)
            ->and(rn09Instantanea())->toBe($antes);
        rn09AfirmarCadena($s['e1']);
    });

    it('RN-09-AC1 S-11: la clave no caduca: reintentar pasado un tiempo (incluso con el lote y la prescripción ya vencidos) sigue reproduciendo la respuesta original', function (string $despues) {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);

        $original = rn09Post($cuerpo, 'rn09-ac1-caduca');
        rn09AfirmarOriginal($original);
        $tras = rn09Instantanea();

        rn09Congelar($despues);
        $reintento = rn09Post($cuerpo, 'rn09-ac1-caduca');

        rn09AfirmarReproducida($reintento, $original);
        expect(rn09Instantanea())->toBe($tras);
    })->with([
        '1 minuto después' => ['2026-11-04 10:01:00'],
        'al día siguiente' => ['2026-11-05 10:00:00'],
        'más de un año después (todo vencido)' => ['2028-01-15 10:00:00'],
    ]);

    it('RN-09-AC1: claves distintas (también si solo cambian mayúsculas/minúsculas) son solicitudes distintas: cada una crea su dispensación sin cabecera de reproducción', function (string $k1, string $k2) {
        $s = rn09Escenario(10);
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);

        $id1 = rn09AfirmarOriginal(rn09Post($cuerpo, $k1));
        $id2 = rn09AfirmarOriginal(rn09Post($cuerpo, $k2));

        expect($id2)->not->toBe($id1)
            ->and(DB::table('dispensaciones')->count())->toBe(2)
            ->and(rn09StockDe($s['e1']))->toBe(6)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(2);
        rn09AfirmarClaveGuardada($id1, $k1, $usuario->id);
        rn09AfirmarClaveGuardada($id2, $k2, $usuario->id);
        rn09AfirmarCadena($s['e1']);
    })->with([
        'claves distintas' => ['clave-a', 'clave-b'],
        'solo cambia la caja' => ['Clave-Caja', 'clave-caja'],
        'una es prefijo de la otra' => ['clave', 'clave-1'],
    ]);

    it('RN-09-AC1 RN-09-AC3: la previsualización no exige ni consume la clave: previsualizar con K y luego dispensar con K crea la dispensación (sin Idempotent-Replayed)', function () {
        $s = rn09Escenario(10);
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);
        $antes = rn09Instantanea();

        rn09Post($cuerpo, 'rn09-previa', RN09_URI_PREVIA)->assertOk();
        expect(rn09Instantanea())->toBe($antes);

        $id = rn09AfirmarOriginal(rn09Post($cuerpo, 'rn09-previa'));
        rn09AfirmarClaveGuardada($id, 'rn09-previa', $usuario->id);
        expect(rn09StockDe($s['e1']))->toBe(8);
    });
});

/*
|--------------------------------------------------------------------------
| RN-09-AC2 misma clave con cuerpo distinto
|--------------------------------------------------------------------------
*/

describe('RN-09-AC2 clave reutilizada', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC2 S-10: misma clave con cuerpo distinto → 422 IDEMPOTENCY_KEY_REUTILIZADA sin efectos; la original sigue reproduciéndose y el nuevo cuerpo con otra clave sí se crea', function (Closure $distinto) {
        $s = rn09Escenario(20);
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);

        $original = rn09Post($cuerpo, 'rn09-ac2');
        $id = rn09AfirmarOriginal($original);
        $tras = rn09Instantanea();

        $otro = $distinto($s);
        expect($otro)->not->toBe($cuerpo);

        $r = rn09Post($otro, 'rn09-ac2');
        esperarError($r, 422, 'IDEMPOTENCY_KEY_REUTILIZADA');
        expect($r->headers->get('Idempotent-Replayed'))->toBeNull()
            ->and(rn09Instantanea())->toBe($tras);

        // La clave sigue ligada al cuerpo original (el 422 no la "reasigna").
        rn09AfirmarReproducida(rn09Post($cuerpo, 'rn09-ac2'), $original);
        expect(rn09Instantanea())->toBe($tras);

        // El cuerpo nuevo es válido por sí mismo: con otra clave se procesa.
        $nuevo = rn09AfirmarOriginal(rn09Post($otro, 'rn09-ac2-otra'));
        rn09AfirmarClaveGuardada($nuevo, 'rn09-ac2-otra', $usuario->id);
        rn09AfirmarClaveGuardada($id, 'rn09-ac2', $usuario->id);
        expect(DB::table('dispensaciones')->where('idempotency_key', 'rn09-ac2')->count())->toBe(1);
    })->with([
        'otra cantidad' => [fn (array $s): array => rn09Cuerpo($s, [[$s['item1'], 3]])],
        'otro ítem prescrito' => [fn (array $s): array => rn09Cuerpo($s, [[$s['item2'], 2]])],
        'otra bodega' => [fn (array $s): array => rn09Cuerpo($s, [[$s['item1'], 2]], $s['b2'])],
        'ítem extra' => [fn (array $s): array => rn09Cuerpo($s, [[$s['item1'], 2], [$s['item2'], 1]])],
        'otro paciente y prescripción' => [fn (array $s): array => rn09Cuerpo($s, [[$s['itemAjeno'], 2]], null, $s['ajena'])],
    ]);

    it('RN-09-AC2: con cuerpo distinto que además no tendría stock, la respuesta es 422 IDEMPOTENCY_KEY_REUTILIZADA (no 409) y nada cambia', function () {
        $s = rn09Escenario(5);
        actuarComo('regente_farmacia');

        rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), 'rn09-ac2-stock'));
        $tras = rn09Instantanea();

        $r = rn09Post(rn09Cuerpo($s, [[$s['item1'], 999]]), 'rn09-ac2-stock');
        esperarError($r, 422, 'IDEMPOTENCY_KEY_REUTILIZADA');
        expect(rn09Instantanea())->toBe($tras)
            ->and(rn09StockDe($s['e1']))->toBe(3);
    });
});

/*
|--------------------------------------------------------------------------
| RN-09-AC3 cabecera obligatoria
|--------------------------------------------------------------------------
*/

describe('RN-09-AC3 cabecera Idempotency-Key', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC3: sin cabecera, vacía, solo espacios o con más de 255 caracteres → 400 IDEMPOTENCY_KEY_REQUERIDA sin efectos', function (?string $clave) {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        $antes = rn09Instantanea();

        $r = rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), $clave);

        esperarError($r, 400, 'IDEMPOTENCY_KEY_REQUERIDA');
        expect($r->headers->get('Idempotent-Replayed'))->toBeNull()
            ->and(rn09Instantanea())->toBe($antes)
            ->and(rn09StockDe($s['e1']))->toBe(10);
    })->with([
        'sin cabecera' => [null],
        'vacía' => [''],
        'un espacio' => [' '],
        'solo espacios' => ['     '],
        '256 caracteres' => [str_repeat('k', 256)],
        '1000 caracteres' => [str_repeat('x', 1000)],
    ]);

    it('RN-09-AC3: claves de 1 y de exactamente 255 caracteres se aceptan, se guardan tal cual y se reproducen', function (string $clave) {
        $s = rn09Escenario(10);
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);

        $original = rn09Post($cuerpo, $clave);
        $id = rn09AfirmarOriginal($original);
        rn09AfirmarClaveGuardada($id, $clave, $usuario->id);

        rn09AfirmarReproducida(rn09Post($cuerpo, $clave), $original);
        expect(DB::table('dispensaciones')->count())->toBe(1)
            ->and(rn09StockDe($s['e1']))->toBe(8);
    })->with([
        '1 carácter' => ['k'],
        '255 caracteres' => [str_repeat('k', 255)],
        'UUID v4' => ['3f2b8c1e-9d4a-4e6f-8b7c-0a1d2e3f4a5b'],
    ]);

    it('RN-09-AC3: una clave de 256 caracteres que comparte los primeros 255 con una ya usada no se trunca ni se reproduce: 400 sin efectos', function () {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);
        rn09AfirmarOriginal(rn09Post($cuerpo, str_repeat('k', 255)));
        $tras = rn09Instantanea();

        $r = rn09Post($cuerpo, str_repeat('k', 256));
        esperarError($r, 400, 'IDEMPOTENCY_KEY_REQUERIDA');
        expect($r->headers->get('Idempotent-Replayed'))->toBeNull()
            ->and(rn09Instantanea())->toBe($tras);
    });

    it('RN-09-AC3: la previsualización no exige la clave: sin cabecera responde 200 sin escribir nada', function () {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        $antes = rn09Instantanea();

        $r = rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), null, RN09_URI_PREVIA);

        $r->assertOk();
        expect(cuerpoDatos($r)['detalles'] ?? null)->toBeArray()->toHaveCount(1)
            ->and(rn09Instantanea())->toBe($antes);
    });

    it('RN-09-AC3: sin token y sin clave → 401 NO_AUTENTICADO (la autenticación va primero) sin efectos', function () {
        $s = rn09Escenario(10);
        $antes = rn09Instantanea();

        $r = rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), null);

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn09Instantanea())->toBe($antes);
    });
});

/*
|--------------------------------------------------------------------------
| RN-09-AC4 JSON canónico
|--------------------------------------------------------------------------
*/

describe('RN-09-AC4 cuerpo canónico', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC4: el mismo cuerpo con las claves en otro orden (raíz y dentro de cada ítem) es el mismo cuerpo: se reproduce sin efectos', function (Closure $reordenar) {
        $s = rn09Escenario(20);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 3], [$s['item2'], 1]]);

        $original = rn09Post($cuerpo, 'rn09-ac4');
        $id = rn09AfirmarOriginal($original);
        $tras = rn09Instantanea();

        $reordenado = $reordenar($cuerpo);
        expect(json_encode($reordenado))->not->toBe(json_encode($cuerpo), 'el caso no reordena nada');

        $reintento = rn09Post($reordenado, 'rn09-ac4');
        rn09AfirmarReproducida($reintento, $original);
        expect(cuerpoDatos($reintento)['id'])->toBe($id)
            ->and(rn09Instantanea())->toBe($tras);
    })->with([
        'raíz invertida' => [fn (array $c): array => array_reverse($c, true)],
        'claves de cada ítem invertidas' => [fn (array $c): array => ['items' => array_map(fn (array $i): array => array_reverse($i, true), $c['items'])] + $c],
        'ids como cadena numérica (normalización acordada)' => [fn (array $c): array => [
            'paciente_id' => (string) $c['paciente_id'],
            'prescripcion_id' => (string) $c['prescripcion_id'],
            'bodega_id' => (string) $c['bodega_id'],
            'items' => array_map(fn (array $i): array => ['prescripcion_item_id' => (string) $i['prescripcion_item_id'], 'cantidad' => $i['cantidad']], $c['items']),
        ]],
        'raíz e ítems reordenados' => [fn (array $c): array => [
            'items' => array_map(fn (array $i): array => ['cantidad' => $i['cantidad'], 'prescripcion_item_id' => $i['prescripcion_item_id']], $c['items']),
            'bodega_id' => $c['bodega_id'],
            'paciente_id' => $c['paciente_id'],
            'prescripcion_id' => $c['prescripcion_id'],
        ]],
    ]);

    it('RN-09-AC4: el formato del JSON (espacios, saltos de línea, orden de claves) no cambia el cuerpo: un JSON crudo equivalente se reproduce', function () {
        $s = rn09Escenario(20);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 3]]);

        $original = rn09Post($cuerpo, 'rn09-ac4-crudo');
        rn09AfirmarOriginal($original);
        $tras = rn09Instantanea();

        $crudo = sprintf(
            "{\n  \"items\" : [ { \"cantidad\" : 3 ,\n \"prescripcion_item_id\":%d } ],\n\t\"bodega_id\":%d, \"prescripcion_id\" : %d,\"paciente_id\":%d }",
            $cuerpo['items'][0]['prescripcion_item_id'],
            $cuerpo['bodega_id'],
            $cuerpo['prescripcion_id'],
            $cuerpo['paciente_id'],
        );
        expect(json_decode($crudo, true))->toBeArray();

        $reintento = test()->call('POST', RN09_URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'rn09-ac4-crudo',
        ], $crudo);

        rn09AfirmarReproducida($reintento, $original);
        expect(rn09Instantanea())->toBe($tras);
    });

    it('RN-09-AC4 S-47: el orden de los elementos de items SÍ forma parte del cuerpo (lista JSON ordenada): mismos ítems en otro orden con la misma clave → 422 IDEMPOTENCY_KEY_REUTILIZADA sin efectos', function () {
        $s = rn09Escenario(20);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 3], [$s['item2'], 1]]);

        rn09AfirmarOriginal(rn09Post($cuerpo, 'rn09-ac4-orden'));
        $tras = rn09Instantanea();

        $invertido = ['items' => array_reverse($cuerpo['items'])] + $cuerpo;
        $r = rn09Post($invertido, 'rn09-ac4-orden');

        esperarError($r, 422, 'IDEMPOTENCY_KEY_REUTILIZADA');
        expect(rn09Instantanea())->toBe($tras);
    });
});

/*
|--------------------------------------------------------------------------
| RN-09-AC5 alcance por usuario
|--------------------------------------------------------------------------
*/

describe('RN-09-AC5 alcance por usuario', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC5 S-11: un auxiliar y un regente con la misma clave crean dos dispensaciones independientes (ninguna reproducida); cada uno reproduce luego la suya', function (bool $mismoCuerpo) {
        $s = rn09Escenario(20);
        $auxiliar = usuarioConRol('auxiliar_farmacia');
        $regente = usuarioConRol('regente_farmacia');
        $cuerpoA = rn09Cuerpo($s, [[$s['item1'], 2]]);
        $cuerpoB = $mismoCuerpo ? $cuerpoA : rn09Cuerpo($s, [[$s['item1'], 5]]);

        rn09Como($auxiliar);
        $rA = rn09Post($cuerpoA, 'clave-compartida');
        $idA = rn09AfirmarOriginal($rA);

        rn09Como($regente);
        $rB = rn09Post($cuerpoB, 'clave-compartida');
        $idB = rn09AfirmarOriginal($rB);

        $total = 2 + ($mismoCuerpo ? 2 : 5);
        expect($idB)->not->toBe($idA)
            ->and(DB::table('dispensaciones')->count())->toBe(2)
            ->and(DB::table('dispensaciones')->where('id', $idA)->value('dispensado_por_id'))->toBe($auxiliar->id)
            ->and(DB::table('dispensaciones')->where('id', $idB)->value('dispensado_por_id'))->toBe($regente->id)
            ->and(rn09StockDe($s['e1']))->toBe(20 - $total)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->where('documento_id', $idA)->value('usuario_id'))->toBe($auxiliar->id)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->where('documento_id', $idB)->value('usuario_id'))->toBe($regente->id);
        rn09AfirmarClaveGuardada($idA, 'clave-compartida', $auxiliar->id);
        rn09AfirmarClaveGuardada($idB, 'clave-compartida', $regente->id);
        $tras = rn09Instantanea();

        // Cada uno reproduce la suya, no la del otro.
        rn09Como($auxiliar);
        $reA = rn09Post($cuerpoA, 'clave-compartida');
        rn09AfirmarReproducida($reA, $rA);
        expect(cuerpoDatos($reA)['id'])->toBe($idA);

        rn09Como($regente);
        $reB = rn09Post($cuerpoB, 'clave-compartida');
        rn09AfirmarReproducida($reB, $rB);
        expect(cuerpoDatos($reB)['id'])->toBe($idB)
            ->and(rn09Instantanea())->toBe($tras);
        rn09AfirmarCadena($s['e1']);
    })->with([
        'mismo cuerpo' => [true],
        'cuerpo distinto' => [false],
    ]);

    it('RN-09-AC5: dos usuarios del mismo rol con la misma clave también son independientes, y el cuerpo distinto del segundo no da 422', function () {
        $s = rn09Escenario(20);
        $a = usuarioConRol('auxiliar_farmacia');
        $b = usuarioConRol('auxiliar_farmacia');

        rn09Como($a);
        $idA = rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 1]]), 'K'));

        rn09Como($b);
        $idB = rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item2'], 4]]), 'K'));

        expect($idB)->not->toBe($idA)
            ->and(rn09StockDe($s['e1']))->toBe(19)
            ->and(rn09StockDe($s['e2']))->toBe(16)
            ->and(DB::table('dispensaciones')->where('idempotency_key', 'K')->count())->toBe(2);
    });
});

/*
|--------------------------------------------------------------------------
| RN-09-AC7 los fallos no se guardan
|--------------------------------------------------------------------------
*/

describe('RN-09-AC7 reintento tras un fallo', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC7 S-11: K que falló con 409 STOCK_INSUFICIENTE no queda guardada; el fallo no se reproduce y, tras una entrada de stock, el reintento con la misma K se procesa (201)', function () {
        $s = rn09Escenario(1);
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 3]]);
        $antes = rn09Instantanea();

        foreach ([1, 2] as $intento) {
            $r = rn09Post($cuerpo, 'rn09-ac7-409');
            esperarError($r, 409, 'STOCK_INSUFICIENTE');
            expect($r->headers->get('Idempotent-Replayed'))->toBeNull("intento {$intento}")
                ->and(rn09Instantanea())->toBe($antes, "intento {$intento}")
                ->and(DB::table('dispensaciones')->where('idempotency_key', 'rn09-ac7-409')->exists())->toBeFalse();
        }

        rn09Entrada($s['e1'], 5); // corrige la causa por el camino del kardex: 1 + 5 = 6

        $r = rn09Post($cuerpo, 'rn09-ac7-409');
        $id = rn09AfirmarOriginal($r);
        rn09AfirmarClaveGuardada($id, 'rn09-ac7-409', $usuario->id);
        expect(rn09StockDe($s['e1']))->toBe(3)
            ->and(DB::table('dispensaciones')->count())->toBe(1)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(1);
        rn09AfirmarCadena($s['e1']);

        // A partir de aquí sí se reproduce.
        rn09AfirmarReproducida(rn09Post($cuerpo, 'rn09-ac7-409'), $r);
        expect(rn09StockDe($s['e1']))->toBe(3);
    });

    it('RN-09-AC7: K que falló con 409 puede reutilizarse con otro cuerpo (una cantidad que sí alcanza) sin 422', function () {
        $s = rn09Escenario(2);
        $usuario = actuarComo('regente_farmacia');

        esperarError(rn09Post(rn09Cuerpo($s, [[$s['item1'], 3]]), 'rn09-ac7-menos'), 409, 'STOCK_INSUFICIENTE');

        $id = rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), 'rn09-ac7-menos'));
        rn09AfirmarClaveGuardada($id, 'rn09-ac7-menos', $usuario->id);
        expect(rn09StockDe($s['e1']))->toBe(0);
    });

    it('RN-09-AC7: K que falló con 422 (bodega inactiva, S-46) se procesa con el mismo cuerpo cuando la bodega vuelve a estar activa', function () {
        $s = rn09Escenario(10);
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);
        DB::table('bodegas')->where('id', $s['b1']->id)->update(['activa' => false]);
        $antes = rn09Instantanea();

        $r = rn09Post($cuerpo, 'rn09-ac7-422');
        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('bodega_id')
            ->and(rn09Instantanea())->toBe($antes);

        DB::table('bodegas')->where('id', $s['b1']->id)->update(['activa' => true]);

        $id = rn09AfirmarOriginal(rn09Post($cuerpo, 'rn09-ac7-422'));
        rn09AfirmarClaveGuardada($id, 'rn09-ac7-422', $usuario->id);
        expect(rn09StockDe($s['e1']))->toBe(8);
    });

    it('RN-09-AC7: K que falló con 422 por el cuerpo se procesa con el cuerpo corregido (no da IDEMPOTENCY_KEY_REUTILIZADA)', function (Closure $erroneo, string $codigo) {
        $s = rn09Escenario(10);
        $usuario = actuarComo('auxiliar_farmacia');
        $antes = rn09Instantanea();

        $r = rn09Post($erroneo($s), 'rn09-ac7-cuerpo');
        esperarError($r, 422, $codigo);
        expect(rn09Instantanea())->toBe($antes);

        $id = rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), 'rn09-ac7-cuerpo'));
        rn09AfirmarClaveGuardada($id, 'rn09-ac7-cuerpo', $usuario->id);
        expect(rn09StockDe($s['e1']))->toBe(8);
    })->with([
        'cantidad 0 (VALIDACION)' => [fn (array $s): array => rn09Cuerpo($s, [[$s['item1'], 0]]), 'VALIDACION'],
        'ítem de otra prescripción (PRESCRIPCION_NO_CORRESPONDE)' => [fn (array $s): array => rn09Cuerpo($s, [[$s['itemAjeno'], 2]]), 'PRESCRIPCION_NO_CORRESPONDE'],
    ]);

    it('RN-09-AC7: un error interno (500) a mitad de la dispensación no guarda la clave; al resolverse, el reintento con la misma K se procesa (201)', function () {
        $s = rn09Escenario(10);
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rn09_falla() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tipo = 'SALIDA_DISPENSACION' THEN
                    RAISE EXCEPTION 'fallo inyectado por el test RN-09-AC7';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER rn09_falla BEFORE INSERT ON movimientos_kardex
                FOR EACH ROW EXECUTE FUNCTION rn09_falla();
            SQL);
        $antes = rn09Instantanea();

        $r = rn09Post($cuerpo, 'rn09-ac7-500');
        esperarError($r, 500, 'ERROR_INTERNO');
        expect(rn09Instantanea())->toBe($antes);

        DB::unprepared('DROP TRIGGER rn09_falla ON movimientos_kardex; DROP FUNCTION rn09_falla()');

        $id = rn09AfirmarOriginal(rn09Post($cuerpo, 'rn09-ac7-500'));
        rn09AfirmarClaveGuardada($id, 'rn09-ac7-500', $usuario->id);
        expect(rn09StockDe($s['e1']))->toBe(8);
        rn09AfirmarCadena($s['e1']);
    });
});

/*
|--------------------------------------------------------------------------
| RN-09-AC8 reproducción literal
|--------------------------------------------------------------------------
|
| Hasta RN-05 no hay dispensaciones PENDIENTE_AUTORIZACION reales (control especial). Aquí se
| prueba la mitad que es de RN-09: lo que se reproduce es la respuesta ORIGINAL guardada, no el
| estado actual de la dispensación, y el reintento no crea otra. El caso completo (producto de
| control especial → pendiente → reintento) se prueba en RN-05.
*/

describe('RN-09-AC8 reproducción de la respuesta original', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC8: aunque la dispensación o sus datos cambien después (estado, lote, producto), el reintento reproduce literalmente la respuesta original y no crea otra', function (Closure $mutar) {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);

        $original = rn09Post($cuerpo, 'rn09-ac8');
        $id = rn09AfirmarOriginal($original);

        $mutar($s, $id);
        $tras = rn09Instantanea();

        $reintento = rn09Post($cuerpo, 'rn09-ac8');
        rn09AfirmarReproducida($reintento, $original);
        expect(cuerpoDatos($reintento)['id'])->toBe($id)
            ->and(rn09Instantanea())->toBe($tras)
            ->and(DB::table('dispensaciones')->count())->toBe(1)
            ->and(rn09StockDe($s['e1']))->toBe(8);
    })->with([
        'estado pasa a PENDIENTE_AUTORIZACION' => [function (array $s, int $id): void {
            DB::table('dispensaciones')->where('id', $id)->update(['estado' => 'PENDIENTE_AUTORIZACION']);
        }],
        'cambia el código del lote' => [function (array $s, int $id): void {
            DB::table('lotes')->where('id', $s['e1']->lote_id)->update(['codigo' => 'RN09-RENOMBRADO']);
        }],
        'cambia el nombre del producto' => [function (array $s, int $id): void {
            DB::table('productos')->where('id', $s['p1']->id)->update(['nombre' => 'Producto renombrado RN-09']);
        }],
    ]);

    it('RN-09-AC8: con la dispensación en PENDIENTE_AUTORIZACION, el reintento devuelve el estado original (no el actual) y no crea otra pendiente', function () {
        $s = rn09Escenario(10);
        actuarComo('regente_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);

        $original = rn09Post($cuerpo, 'rn09-ac8-estado');
        $id = rn09AfirmarOriginal($original);
        $estadoOriginal = cuerpoDatos($original)['estado'] ?? null;
        expect($estadoOriginal)->toBe('CONFIRMADA');

        DB::table('dispensaciones')->where('id', $id)->update(['estado' => 'PENDIENTE_AUTORIZACION']);

        foreach ([1, 2, 3] as $i) {
            $r = rn09Post($cuerpo, 'rn09-ac8-estado');
            rn09AfirmarReproducida($r, $original);
            expect(cuerpoDatos($r)['estado'])->toBe($estadoOriginal, "reintento {$i}");
        }

        expect(DB::table('dispensaciones')->count())->toBe(1)
            ->and(DB::table('dispensaciones')->where('estado', 'PENDIENTE_AUTORIZACION')->count())->toBe(1)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| Supuestos S-50 (orden de comprobación), S-51 (claves desconocidas en la raíz),
| S-52 (el reintento no revalida: bodega desactivada después) y columnas internas ocultas
|--------------------------------------------------------------------------
*/

describe('RN-09 S-50 orden de comprobación: 401 → 403 → 400 → 422', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC3 S-50: un rol sin permiso para dispensar, sin cabecera (con cuerpo válido o inválido) → 403 NO_AUTORIZADO, no 400, sin efectos', function (string $rol, bool $cuerpoValido) {
        $s = rn09Escenario(10);
        actuarComo($rol);
        $cuerpo = $cuerpoValido ? rn09Cuerpo($s, [[$s['item1'], 2]]) : ['nota' => 'x', 'items' => []];
        $antes = rn09Instantanea();

        $r = rn09Post($cuerpo, null);

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn09Instantanea())->toBe($antes);
    })->with(['medico', 'auditor', 'admin'])->with([
        'cuerpo válido' => [true],
        'cuerpo inválido' => [false],
    ]);

    it('RN-09-AC3 S-50: con permiso, cuerpo inválido y sin cabecera → 400 IDEMPOTENCY_KEY_REQUERIDA (no 422); con cabecera válida → 422 VALIDACION; sin efectos', function (string $rol, Closure $invalido) {
        $s = rn09Escenario(10);
        actuarComo($rol);
        $cuerpo = $invalido($s);
        $antes = rn09Instantanea();

        esperarError(rn09Post($cuerpo, null), 400, 'IDEMPOTENCY_KEY_REQUERIDA');
        expect(rn09Instantanea())->toBe($antes);

        esperarError(rn09Post($cuerpo, 'rn09-s50'), 422, 'VALIDACION');
        expect(rn09Instantanea())->toBe($antes)
            ->and(rn09StockDe($s['e1']))->toBe(10);
    })->with(['auxiliar_farmacia', 'regente_farmacia'])->with([
        'cuerpo vacío' => [fn (array $s): array => []],
        'cantidad 0' => [fn (array $s): array => rn09Cuerpo($s, [[$s['item1'], 0]])],
        'clave desconocida en la raíz' => [fn (array $s): array => rn09Cuerpo($s, [[$s['item1'], 2]]) + ['nota' => 'x']],
    ]);
});

describe('RN-09 S-51 claves desconocidas en la raíz', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC4 S-51: una clave desconocida en la raíz (también con valor null o vacío) → 422 VALIDACION con esa clave en detalles.campos y sin efectos, al dispensar y al previsualizar', function (string $uri, string $clave, mixed $valor) {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]) + [$clave => $valor];
        $antes = rn09Instantanea();

        $r = rn09Post($cuerpo, 'rn09-s51', $uri);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($clave)
            ->and(rn09Instantanea())->toBe($antes)
            ->and(rn09StockDe($s['e1']))->toBe(10);

        // La clave no quedó consumida: el cuerpo correcto con la misma K se crea.
        if ($uri === RN09_URI) {
            rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), 'rn09-s51'));
            expect(rn09StockDe($s['e1']))->toBe(8);
        }
    })->with([
        'dispensar' => [RN09_URI],
        'previsualizar' => [RN09_URI_PREVIA],
    ])->with([
        'nota' => ['nota', 'entregar en la mañana'],
        'lote_id' => ['lote_id', 1],
        'producto_id' => ['producto_id', 1],
        'nota null' => ['nota', null],
        'nota vacía' => ['nota', ''],
    ]);

    it('RN-09-AC4 S-51: los parámetros de la query string no cuentan como claves del cuerpo', function () {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');

        rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), 'rn09-s51-query', RN09_URI.'?origen=app'));
        expect(rn09StockDe($s['e1']))->toBe(8);
    });
});

describe('RN-09 S-52 el reintento no revalida la bodega', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC8 S-52 S-46: con la bodega desactivada después de crear, el reintento con K reproduce el 201 original; una dispensación nueva (otra K) en esa bodega → 422 VALIDACION con bodega_id, sin efectos', function () {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn09Cuerpo($s, [[$s['item1'], 2]]);

        $original = rn09Post($cuerpo, 'rn09-s52');
        rn09AfirmarOriginal($original);
        DB::table('bodegas')->where('id', $s['b1']->id)->update(['activa' => false]);
        $tras = rn09Instantanea();

        rn09AfirmarReproducida(rn09Post($cuerpo, 'rn09-s52'), $original);
        expect(rn09Instantanea())->toBe($tras);

        $nueva = rn09Post($cuerpo, 'rn09-s52-nueva');
        esperarError($nueva, 422, 'VALIDACION');
        expect($nueva->json('error.detalles.campos'))->toHaveKey('bodega_id')
            ->and(rn09Instantanea())->toBe($tras)
            ->and(rn09StockDe($s['e1']))->toBe(8);

        // La previsualización (sin clave) sigue rechazando la bodega inactiva.
        $previa = rn09Post($cuerpo, null, RN09_URI_PREVIA);
        esperarError($previa, 422, 'VALIDACION');
        expect($previa->json('error.detalles.campos'))->toHaveKey('bodega_id');
    });

    it('RN-09-AC2 S-52: con la bodega desactivada, K con otro cuerpo sigue dando 422 IDEMPOTENCY_KEY_REUTILIZADA (no VALIDACION) y sin detalles', function () {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), 'rn09-s52-otro'));
        DB::table('bodegas')->where('id', $s['b1']->id)->update(['activa' => false]);
        $tras = rn09Instantanea();

        $r = rn09Post(rn09Cuerpo($s, [[$s['item1'], 3]]), 'rn09-s52-otro');

        esperarError($r, 422, 'IDEMPOTENCY_KEY_REUTILIZADA');
        expect($r->json('error.detalles'))->toBeEmpty()
            ->and(rn09Instantanea())->toBe($tras);
    });
});

describe('RN-09 columnas internas de idempotencia', function () {
    beforeEach(fn () => rn09Congelar());

    it('RN-09-AC1: la clave, el hash y la respuesta guardada no salen al serializar el modelo Dispensacion (toArray / toJson)', function () {
        $s = rn09Escenario(10);
        actuarComo('auxiliar_farmacia');
        rn09AfirmarOriginal(rn09Post(rn09Cuerpo($s, [[$s['item1'], 2]]), 'rn09-oculta'));

        $dispensacion = Dispensacion::query()->firstOrFail();
        $internas = ['idempotency_key', 'request_hash', 'respuesta_status', 'respuesta_cuerpo'];

        // Las columnas tienen valor (no se ocultan por estar vacías)…
        foreach ($internas as $columna) {
            expect($dispensacion->getAttribute($columna))->not->toBeNull();
        }

        // …pero no se serializan.
        expect(array_intersect($internas, array_keys($dispensacion->toArray())))->toBe([])
            ->and($dispensacion->toJson())->not->toContain('rn09-oculta')
            ->and(array_keys($dispensacion->toArray()))->toContain('id', 'bodega_id', 'estado');
    });
});
