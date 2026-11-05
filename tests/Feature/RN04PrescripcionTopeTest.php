<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Paciente;
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
 * RN-04 — Paciente, prescripción vigente y tope acumulado (AC1..AC7, AC9..AC11). AC8 (tope bajo
 * concurrencia real) va en tests/Feature/SinTransaccion/RN04TopeConcurrenteTest.php y el cálculo
 * del pendiente (CalculadorPendiente::pendiente) en tests/Feature/RN04CalculadorPendienteTest.php.
 *
 * Supuestos: S-05 (vigente si ACTIVA y hoy <= fecha_vencimiento; "hoy" en America/Bogota),
 * S-06 (PENDIENTE_AUTORIZACION no mueve stock pero sí cuenta para el tope), S-20 (un producto una
 * vez por prescripción), S-27 (forma antes que reglas de negocio), S-41 (ítems: solo
 * prescripcion_item_id y cantidad, de 1 a 1.000.000), S-42 (409 STOCK_INSUFICIENTE), S-43 (la
 * previsualización responde con los mismos códigos), S-45 (PRESCRIPCION_NO_CORRESPONDE), S-50
 * (401 → 403 → 400 → 422), S-52 (el reintento con la misma clave reproduce sin revalidar).
 *
 * Precedencia acordada para RN-04: vigencia (422 PRESCRIPCION_NO_VIGENTE) → tope (422
 * CANTIDAD_EXCEDE_PRESCRITO) → stock (409 STOCK_INSUFICIENTE).
 *
 * Contrato del error de tope: 422 CANTIDAD_EXCEDE_PRESCRITO con detalles.prescripcion_item_id,
 * detalles.pendiente (prescrito − CONFIRMADA − PENDIENTE_AUTORIZACION) y detalles.solicitado.
 *
 * El stock se crea por el camino real del kardex (RegistradorKardex, ENTRADA con la existencia
 * bloqueada) para que la cadena de saldos cuadre (RN-06-AC2).
 *
 * RN-04-AC9 se prueba por el flujo real de RN-05 (crear la pendiente por POST /dispensaciones y
 * rechazarla o autorizarla por sus endpoints; también en RN05ControlEspecialTest). Además, unas
 * pruebas del cálculo preparan los estados PENDIENTE_AUTORIZACION / RECHAZADA por SQL (respetando
 * los CHECK) para combinarlos libremente sobre un producto sin control especial.
 */

const RN04_URI = '/api/v1/dispensaciones';
const RN04_URI_PREVIA = '/api/v1/dispensaciones/previsualizar';
const RN04_HOY = '2026-11-04';
const RN04_VENCE_LOTE = '2027-06-30';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn04)
|--------------------------------------------------------------------------
*/

/** Congela el reloj en una hora local de Bogotá (por defecto 2026-11-04 10:00). */
function rn04Congelar(string $fechaHoraBogota = RN04_HOY.' 10:00:00'): void
{
    test()->travelTo(Carbon::parse($fechaHoraBogota, 'America/Bogota'));
}

/** Fecha Y-m-d a N días de RN04_HOY. */
function rn04Dia(int $dias): string
{
    return Carbon::parse(RN04_HOY, 'America/Bogota')->addDays($dias)->toDateString();
}

/** Regente "de servicio" que registra las entradas y dispensa/rechaza en los estados creados por SQL. */
function rn04Regente(string $sufijo = 'servicio'): User
{
    $email = "rn04-regente-{$sufijo}@farma.test";

    return User::query()->where('email', $email)->first()
        ?? usuarioConRol('regente_farmacia', ['email' => $email]);
}

/** Lote nuevo del producto con `cantidad` en la bodega (existencia que nace en 0 + ENTRADA real). */
function rn04Stock(Bodega $bodega, Producto $producto, int $cantidad, string $vence = RN04_VENCE_LOTE): Existencia
{
    $lote = Lote::factory()->create(['producto_id' => $producto->id, 'fecha_vencimiento' => $vence]);
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);

    if ($cantidad > 0) {
        $regente = rn04Regente();
        DB::transaction(function () use ($existencia, $cantidad, $regente): void {
            $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
            app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $regente->id);
        });
    }

    return $existencia->refresh();
}

function rn04StockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/**
 * Escenario: bodega con `stock` de un producto; prescripción (vigente por defecto, con los
 * atributos dados) con un ítem de ese producto prescrito por `prescrito`.
 *
 * @param  array<string, mixed>  $prescripcion
 * @return array{bodega: Bodega, producto: Producto, existencia: Existencia, prescripcion: Prescripcion, item: PrescripcionItem}
 */
function rn04Escenario(int $prescrito = 10, int $stock = 20, array $prescripcion = []): array
{
    $bodega = Bodega::factory()->create();
    $producto = Producto::factory()->create();
    $existencia = rn04Stock($bodega, $producto, $stock);
    $prescripcion = Prescripcion::factory()->create($prescripcion);
    $item = PrescripcionItem::factory()->create([
        'prescripcion_id' => $prescripcion->id,
        'producto_id' => $producto->id,
        'cantidad' => $prescrito,
    ]);

    return compact('bodega', 'producto', 'existencia', 'prescripcion', 'item');
}

/**
 * Como rn04Escenario() pero con un producto de control especial (RN-05): toda dispensación del
 * ítem nace PENDIENTE_AUTORIZACION y necesita a un segundo regente.
 *
 * @return array{bodega: Bodega, producto: Producto, existencia: Existencia, prescripcion: Prescripcion, item: PrescripcionItem}
 */
function rn04EscenarioControl(int $prescrito = 10, int $stock = 20): array
{
    $bodega = Bodega::factory()->create();
    $producto = Producto::factory()->controlEspecial()->create();
    $existencia = rn04Stock($bodega, $producto, $stock);
    $prescripcion = Prescripcion::factory()->create();
    $item = PrescripcionItem::factory()->create([
        'prescripcion_id' => $prescripcion->id,
        'producto_id' => $producto->id,
        'cantidad' => $prescrito,
    ]);

    return compact('bodega', 'producto', 'existencia', 'prescripcion', 'item');
}

/**
 * Cuerpo con un solo ítem (el del escenario, salvo que se indique otro).
 *
 * @param  array<string, mixed>  $s
 * @return array<string, mixed>
 */
function rn04Cuerpo(array $s, mixed $cantidad, ?PrescripcionItem $item = null, ?Bodega $bodega = null): array
{
    return [
        'paciente_id' => $s['prescripcion']->paciente_id,
        'prescripcion_id' => $s['prescripcion']->id,
        'bodega_id' => ($bodega ?? $s['bodega'])->id,
        'items' => [['prescripcion_item_id' => ($item ?? $s['item'])->id, 'cantidad' => $cantidad]],
    ];
}

/**
 * POST con Idempotency-Key (nueva por llamada salvo que se pase `clave`). La previsualización la
 * ignora. JSON_PRESERVE_ZERO_FRACTION para que 2.0 viaje como 2.0.
 *
 * @param  array<string, mixed>  $cuerpo
 */
function rn04Post(string $uri, array $cuerpo, ?string $clave = null): TestResponse
{
    $cabeceras = $clave === null ? cabecerasDispensacion() : ['Idempotency-Key' => $clave];

    return test()->postJson($uri, $cuerpo, $cabeceras, JSON_PRESERVE_ZERO_FRACTION);
}

/** @param array<string, mixed> $s */
function rn04Dispensar(array $s, mixed $cantidad, ?Bodega $bodega = null): TestResponse
{
    return rn04Post(RN04_URI, rn04Cuerpo($s, $cantidad, null, $bodega));
}

/** @param array<string, mixed> $s */
function rn04Previsualizar(array $s, mixed $cantidad): TestResponse
{
    return rn04Post(RN04_URI_PREVIA, rn04Cuerpo($s, $cantidad));
}

/**
 * Estado completo de las tablas que una dispensación toca: en un rechazo o en una
 * previsualización debe quedar idéntico.
 *
 * @return array<string, mixed>
 */
function rn04Instantanea(): array
{
    $filas = fn (string $tabla): array => DB::table($tabla)->orderBy('id')->get()
        ->map(fn (object $f): array => (array) $f)->all();

    return [
        'existencias' => $filas('existencias'),
        'kardex' => $filas('movimientos_kardex'),
        'dispensaciones' => $filas('dispensaciones'),
        'dispensacion_items' => $filas('dispensacion_items'),
        'dispensacion_detalles' => $filas('dispensacion_detalles'),
        'prescripciones' => $filas('prescripciones'),
        'prescripcion_items' => $filas('prescripcion_items'),
    ];
}

/** Cadena de saldos de la existencia desde 0 hasta su cantidad actual (RN-06-AC2). */
function rn04AfirmarCadena(Existencia $existencia): void
{
    $saldo = 0;
    foreach (DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->orderBy('id')->get() as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "existencia {$existencia->id}, movimiento #{$i}: saldo_anterior no encadena")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }
    expect($saldo)->toBe(rn04StockDe($existencia), "existencia {$existencia->id}: el kardex no cuadra con el stock");
}

/**
 * Efecto completo de una dispensación creada (201) de un solo ítem:
 *  - dispensación CONFIRMADA del paciente, la prescripción y la bodega;
 *  - un dispensacion_item con el ítem prescrito, su producto y la cantidad;
 *  - detalles y SALIDA_DISPENSACION solo del producto prescrito, que suman la cantidad;
 *  - el stock de la existencia baja exactamente la cantidad y la cadena del kardex cuadra.
 */
function rn04AfirmarCreada(TestResponse $r, array $s, int $cantidad, int $stockAntes, ?Bodega $bodega = null, ?Existencia $existencia = null): int
{
    $r->assertCreated();
    $id = cuerpoDatos($r)['id'] ?? null;
    expect($id)->toBeInt();

    $bodega ??= $s['bodega'];
    $existencia ??= $s['existencia'];
    $fila = DB::table('dispensaciones')->where('id', $id)->first();
    expect($fila)->not->toBeNull()
        ->and($fila->estado)->toBe('CONFIRMADA')
        ->and((int) $fila->paciente_id)->toBe($s['prescripcion']->paciente_id)
        ->and((int) $fila->prescripcion_id)->toBe($s['prescripcion']->id)
        ->and((int) $fila->bodega_id)->toBe($bodega->id);

    $items = DB::table('dispensacion_items')->where('dispensacion_id', $id)->get();
    expect($items)->toHaveCount(1)
        ->and((int) $items[0]->prescripcion_item_id)->toBe($s['item']->id)
        ->and((int) $items[0]->producto_id)->toBe($s['item']->producto_id)
        ->and((int) $items[0]->cantidad)->toBe($cantidad);

    $detalles = DB::table('dispensacion_detalles')->where('dispensacion_id', $id)->get();
    expect((int) $detalles->sum('cantidad'))->toBe($cantidad)
        ->and($detalles->pluck('producto_id')->map(fn ($p): int => (int) $p)->unique()->values()->all())->toBe([$s['item']->producto_id]);

    $salidas = DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->where('documento_id', $id)->get();
    expect($salidas)->not->toBeEmpty()
        ->and((int) $salidas->sum('cantidad'))->toBe(-$cantidad)
        ->and($salidas->pluck('producto_id')->map(fn ($p): int => (int) $p)->unique()->values()->all())->toBe([$s['item']->producto_id])
        ->and($salidas->pluck('documento_tipo')->unique()->values()->all())->toBe(['dispensacion'])
        ->and(rn04StockDe($existencia))->toBe($stockAntes - $cantidad);

    rn04AfirmarCadena($existencia);

    return $id;
}

/** 422 CANTIDAD_EXCEDE_PRESCRITO con ítem, pendiente y solicitado. */
function rn04AfirmarExcede(TestResponse $r, int $itemId, int $pendiente, int $solicitado): void
{
    esperarError($r, 422, 'CANTIDAD_EXCEDE_PRESCRITO');
    expect($r->json('error.detalles.prescripcion_item_id'))->toBe($itemId)
        ->and($r->json('error.detalles.pendiente'))->toBe($pendiente)
        ->and($r->json('error.detalles.solicitado'))->toBe($solicitado);
}

/** Suma de lo dispensado en estado CONFIRMADA para un ítem prescrito. */
function rn04Confirmado(PrescripcionItem $item): int
{
    return (int) DB::table('dispensacion_items')
        ->join('dispensaciones', 'dispensaciones.id', '=', 'dispensacion_items.dispensacion_id')
        ->where('dispensacion_items.prescripcion_item_id', $item->id)
        ->where('dispensaciones.estado', 'CONFIRMADA')
        ->sum('dispensacion_items.cantidad');
}

/**
 * Estado preparado por SQL para las pruebas del cálculo (RN-04-AC9 con estados preparados),
 * respetando los CHECK: PENDIENTE_AUTORIZACION sin autorizador ni rechazo; RECHAZADA con
 * rechazador distinto del dispensador, fecha y motivo. Nunca mueve stock (S-06). El flujo real
 * (POST /dispensaciones + /rechazar|/autorizar) lo prueban los tests "RN-04-AC9: flujo real".
 *
 * @param  array<string, mixed>  $s
 */
function rn04DispensacionEnEstado(array $s, PrescripcionItem $item, int $cantidad, string $estado): int
{
    $dispensador = rn04Regente('dispensador');
    $ahora = now();

    $fila = [
        'paciente_id' => $s['prescripcion']->paciente_id,
        'prescripcion_id' => $item->prescripcion_id,
        'bodega_id' => $s['bodega']->id,
        'dispensado_por_id' => $dispensador->id,
        'estado' => $estado,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ];
    if ($estado === 'RECHAZADA') {
        $fila += [
            'rechazado_por_id' => rn04Regente('rechazador')->id,
            'rechazado_en' => $ahora,
            'motivo_rechazo' => 'Rechazo sintético de prueba RN-04',
        ];
    }

    $id = DB::table('dispensaciones')->insertGetId($fila);
    DB::table('dispensacion_items')->insert([
        'dispensacion_id' => $id,
        'prescripcion_id' => $item->prescripcion_id,
        'prescripcion_item_id' => $item->id,
        'producto_id' => $item->producto_id,
        'cantidad' => $cantidad,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ]);

    return $id;
}

/**
 * Pasa por SQL una PENDIENTE_AUTORIZACION preparada a RECHAZADA, sin pasar por
 * POST /dispensaciones/{id}/rechazar (solo para las pruebas del cálculo con estados preparados).
 */
function rn04Rechazar(int $dispensacionId): void
{
    $actualizadas = DB::table('dispensaciones')->where('id', $dispensacionId)->where('estado', 'PENDIENTE_AUTORIZACION')->update([
        'estado' => 'RECHAZADA',
        'rechazado_por_id' => rn04Regente('rechazador')->id,
        'rechazado_en' => now(),
        'motivo_rechazo' => 'Rechazo sintético de prueba RN-04',
    ]);
    expect($actualizadas)->toBe(1);
}

function rn04Anular(Prescripcion $prescripcion): void
{
    DB::table('prescripciones')->where('id', $prescripcion->id)->update(['estado' => 'ANULADA']);
}

/*
|--------------------------------------------------------------------------
| RN-04-AC1: campos obligatorios
|--------------------------------------------------------------------------
*/

describe('RN-04-AC1 campos obligatorios', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC1: sin paciente_id, prescripcion_id, bodega_id o items (o con items vacío/null) → 422 VALIDACION con el campo en detalles.campos y sin cambios, al dispensar y al previsualizar', function (string $uri, string $campo, string $modo) {
        $s = rn04Escenario();
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn04Cuerpo($s, 2);
        match ($modo) {
            'omitir' => $cuerpo = array_diff_key($cuerpo, [$campo => true]),
            'null' => $cuerpo[$campo] = null,
            'vacio' => $cuerpo[$campo] = [],
        };
        $antes = rn04Instantanea();

        $r = rn04Post($uri, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(rn04Instantanea())->toBe($antes);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ])->with([
        'sin paciente_id' => ['paciente_id', 'omitir'],
        'sin prescripcion_id' => ['prescripcion_id', 'omitir'],
        'sin bodega_id' => ['bodega_id', 'omitir'],
        'sin items' => ['items', 'omitir'],
        'paciente_id null' => ['paciente_id', 'null'],
        'prescripcion_id null' => ['prescripcion_id', 'null'],
        'bodega_id null' => ['bodega_id', 'null'],
        'items null' => ['items', 'null'],
        'items vacío' => ['items', 'vacio'],
    ]);

    it('RN-04-AC1: cuerpo vacío → 422 VALIDACION que nombra los cuatro campos obligatorios', function () {
        rn04Escenario();
        actuarComo('regente_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Post(RN04_URI, []);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKeys(['paciente_id', 'prescripcion_id', 'bodega_id', 'items'])
            ->and(rn04Instantanea())->toBe($antes);
    });

    it('RN-04-AC1: ítem sin prescripcion_item_id → 422 VALIDACION sin cambios', function () {
        $s = rn04Escenario();
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn04Cuerpo($s, 2);
        unset($cuerpo['items'][0]['prescripcion_item_id']);
        $antes = rn04Instantanea();

        $r = rn04Post(RN04_URI, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('items.0.prescripcion_item_id')
            ->and(rn04Instantanea())->toBe($antes);
    });
});

/*
|--------------------------------------------------------------------------
| RN-04-AC2 / AC3: prescripción vigente (S-05)
|--------------------------------------------------------------------------
*/

describe('RN-04-AC2 RN-04-AC3 vigencia de la prescripción', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC2: vence ayer → 422 PRESCRIPCION_NO_VIGENTE; vence hoy o mañana → 201 (vigencia inclusiva, S-05)', function (int $dias, bool $vigente) {
        $s = rn04Escenario(10, 20, ['fecha_emision' => rn04Dia(-60), 'fecha_vencimiento' => rn04Dia($dias)]);
        $auxiliar = actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, 3);

        if ($vigente) {
            $id = rn04AfirmarCreada($r, $s, 3, 20);
            expect((int) DB::table('dispensaciones')->where('id', $id)->value('dispensado_por_id'))->toBe($auxiliar->id);
        } else {
            esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
            expect(rn04Instantanea())->toBe($antes)
                ->and(rn04StockDe($s['existencia']))->toBe(20);
        }
    })->with([
        'venció hace 30 días' => [-30, false],
        'venció ayer' => [-1, false],
        'vence hoy (vigente, S-05)' => [0, true],
        'vence mañana' => [1, true],
    ]);

    it('RN-04-AC2 S-43: la previsualización aplica la misma vigencia: ayer → 422 PRESCRIPCION_NO_VIGENTE; hoy/mañana → 200; nunca escribe', function (int $dias, bool $vigente) {
        $s = rn04Escenario(10, 20, ['fecha_emision' => rn04Dia(-60), 'fecha_vencimiento' => rn04Dia($dias)]);
        actuarComo('regente_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Previsualizar($s, 3);

        if ($vigente) {
            $r->assertOk();
        } else {
            esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
        }
        expect(rn04Instantanea())->toBe($antes);
    })->with([
        'venció ayer' => [-1, false],
        'vence hoy' => [0, true],
        'vence mañana' => [1, true],
    ]);

    it('RN-04-AC2 S-05: frontera del día en Bogotá: prescripción que vence el 2026-11-04 es vigente a las 23:59:59 de Bogotá y no vigente a las 00:00:00 del 5', function (string $instanteBogota, bool $vigente) {
        rn04Congelar($instanteBogota);
        $s = rn04Escenario(10, 20, ['fecha_emision' => '2026-10-01', 'fecha_vencimiento' => '2026-11-04']);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, 2);

        if ($vigente) {
            rn04AfirmarCreada($r, $s, 2, 20);
        } else {
            esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
            expect(rn04Instantanea())->toBe($antes);
        }
    })->with([
        '2026-11-04 00:00:00 Bogotá' => ['2026-11-04 00:00:00', true],
        '2026-11-04 19:30:00 Bogotá (ya 5 de noviembre en UTC)' => ['2026-11-04 19:30:00', true],
        '2026-11-04 23:59:59 Bogotá' => ['2026-11-04 23:59:59', true],
        '2026-11-05 00:00:00 Bogotá' => ['2026-11-05 00:00:00', false],
    ]);

    describe('con la zona de la aplicación en UTC', function () {
        beforeEach(function () {
            $this->zonaPhpOriginal = date_default_timezone_get();
            $this->zonaAppOriginal = config('app.timezone');
            config(['app.timezone' => 'UTC']);
            date_default_timezone_set('UTC');
        });

        afterEach(function () {
            date_default_timezone_set($this->zonaPhpOriginal);
            config(['app.timezone' => $this->zonaAppOriginal]);
        });

        it('RN-04-AC2 S-05: "hoy" se calcula en America/Bogota aunque la aplicación corra en UTC', function (string $instanteUtc, bool $vigente) {
            test()->travelTo(Carbon::parse($instanteUtc, 'UTC'));
            $s = rn04Escenario(10, 20, ['fecha_emision' => '2026-10-01', 'fecha_vencimiento' => '2026-11-04']);
            actuarComo('auxiliar_farmacia');
            $antes = rn04Instantanea();

            $r = rn04Dispensar($s, 2);

            if ($vigente) {
                rn04AfirmarCreada($r, $s, 2, 20);
            } else {
                esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
                expect(rn04Instantanea())->toBe($antes);
            }
        })->with([
            '2026-11-05 02:00 UTC = 2026-11-04 21:00 Bogotá → vigente' => ['2026-11-05 02:00:00', true],
            '2026-11-05 04:59:59 UTC = 2026-11-04 23:59:59 Bogotá → vigente' => ['2026-11-05 04:59:59', true],
            '2026-11-05 05:00 UTC = 2026-11-05 00:00 Bogotá → vencida' => ['2026-11-05 05:00:00', false],
        ]);
    });

    it('RN-04-AC3: prescripción ANULADA (aunque su fecha esté vigente) → 422 PRESCRIPCION_NO_VIGENTE sin cambios, al dispensar y al previsualizar', function (string $uri, int $dias) {
        $s = rn04Escenario(10, 20, ['estado' => 'ANULADA', 'fecha_vencimiento' => rn04Dia($dias)]);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Post($uri, rn04Cuerpo($s, 2));

        esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(20);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ])->with([
        'vence hoy' => [0],
        'vence en 30 días' => [30],
    ]);

    it('RN-04-AC3 RN-04-AC2: prescripción ANULADA y además vencida → 422 PRESCRIPCION_NO_VIGENTE sin cambios', function () {
        $s = rn04Escenario(10, 20, ['estado' => 'ANULADA', 'fecha_emision' => rn04Dia(-60), 'fecha_vencimiento' => rn04Dia(-1)]);
        actuarComo('regente_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, 2);

        esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
        expect(rn04Instantanea())->toBe($antes);
    });

    it('RN-04-AC3: tras una parcial de 4 (201) se anula la prescripción; una nueva dispensación de 1 → 422 PRESCRIPCION_NO_VIGENTE y el stock queda en 16', function () {
        $s = rn04Escenario(10, 20);
        actuarComo('auxiliar_farmacia');
        rn04AfirmarCreada(rn04Dispensar($s, 4), $s, 4, 20);

        rn04Anular($s['prescripcion']);
        $antes = rn04Instantanea();
        $r = rn04Dispensar($s, 1);

        esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(16)
            ->and(rn04Confirmado($s['item']))->toBe(4);
    });

    it('RN-04-AC2: tras una parcial de 4 el día que vence (201), al día siguiente una de 1 → 422 PRESCRIPCION_NO_VIGENTE', function () {
        $s = rn04Escenario(10, 20, ['fecha_emision' => rn04Dia(-10), 'fecha_vencimiento' => RN04_HOY]);
        actuarComo('auxiliar_farmacia');
        rn04AfirmarCreada(rn04Dispensar($s, 4), $s, 4, 20);

        rn04Congelar(rn04Dia(1).' 08:00:00');
        $antes = rn04Instantanea();
        $r = rn04Dispensar($s, 1);

        esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(16);
    });
});

/*
|--------------------------------------------------------------------------
| RN-04-AC4: correspondencia paciente ↔ prescripción ↔ ítem (S-45)
|--------------------------------------------------------------------------
*/

describe('RN-04-AC4 correspondencia', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC4: prescripción de otro paciente, o ítem que no es de la prescripción → 422 PRESCRIPCION_NO_CORRESPONDE sin cambios, al dispensar y al previsualizar', function (string $uri, string $caso) {
        $s = rn04Escenario(10, 20);
        // Otra prescripción del MISMO paciente con el mismo producto (S-20 lo permite entre prescripciones).
        $otraMismoPaciente = Prescripcion::factory()->create(['paciente_id' => $s['prescripcion']->paciente_id]);
        $itemOtraMismoPaciente = PrescripcionItem::factory()->create(['prescripcion_id' => $otraMismoPaciente->id, 'producto_id' => $s['producto']->id, 'cantidad' => 10]);
        // Prescripción de OTRO paciente.
        $ajena = Prescripcion::factory()->create();
        $itemAjeno = PrescripcionItem::factory()->create(['prescripcion_id' => $ajena->id, 'producto_id' => $s['producto']->id, 'cantidad' => 10]);
        // Ítem propio de otro producto (con stock) para la mezcla.
        $producto2 = Producto::factory()->create();
        rn04Stock($s['bodega'], $producto2, 20);
        $itemPropio2 = PrescripcionItem::factory()->create(['prescripcion_id' => $s['prescripcion']->id, 'producto_id' => $producto2->id, 'cantidad' => 10]);

        $cuerpo = rn04Cuerpo($s, 2);
        match ($caso) {
            'paciente distinto' => $cuerpo['paciente_id'] = Paciente::factory()->create()->id,
            'prescripción de otro paciente con su ítem' => $cuerpo = [
                'paciente_id' => $s['prescripcion']->paciente_id,
                'prescripcion_id' => $ajena->id,
                'bodega_id' => $s['bodega']->id,
                'items' => [['prescripcion_item_id' => $itemAjeno->id, 'cantidad' => 2]],
            ],
            'ítem de otra prescripción del mismo paciente' => $cuerpo['items'][0]['prescripcion_item_id'] = $itemOtraMismoPaciente->id,
            'ítem de una prescripción de otro paciente' => $cuerpo['items'][0]['prescripcion_item_id'] = $itemAjeno->id,
            'ítem propio + ítem ajeno en la misma petición' => $cuerpo['items'] = [
                ['prescripcion_item_id' => $itemPropio2->id, 'cantidad' => 2],
                ['prescripcion_item_id' => $itemOtraMismoPaciente->id, 'cantidad' => 2],
            ],
        };
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Post($uri, $cuerpo);

        esperarError($r, 422, 'PRESCRIPCION_NO_CORRESPONDE');
        expect(rn04Instantanea())->toBe($antes);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ])->with([
        'paciente distinto',
        'prescripción de otro paciente con su ítem',
        'ítem de otra prescripción del mismo paciente',
        'ítem de una prescripción de otro paciente',
        'ítem propio + ítem ajeno en la misma petición',
    ]);

    it('RN-04-AC4 RN-04-AC5: el tope es por ítem prescrito: lo dispensado de otra prescripción del mismo paciente y producto no consume el pendiente', function () {
        $s = rn04Escenario(10, 30);
        $otra = Prescripcion::factory()->create(['paciente_id' => $s['prescripcion']->paciente_id]);
        $itemOtra = PrescripcionItem::factory()->create(['prescripcion_id' => $otra->id, 'producto_id' => $s['producto']->id, 'cantidad' => 10]);
        actuarComo('auxiliar_farmacia');

        $r1 = rn04Post(RN04_URI, [
            'paciente_id' => $otra->paciente_id,
            'prescripcion_id' => $otra->id,
            'bodega_id' => $s['bodega']->id,
            'items' => [['prescripcion_item_id' => $itemOtra->id, 'cantidad' => 10]],
        ]);
        $r1->assertCreated();

        $r2 = rn04Dispensar($s, 10);

        rn04AfirmarCreada($r2, $s, 10, 20);
        expect(rn04Confirmado($s['item']))->toBe(10)
            ->and(rn04Confirmado($itemOtra))->toBe(10)
            ->and(rn04StockDe($s['existencia']))->toBe(10);
    });
});

/*
|--------------------------------------------------------------------------
| RN-04-AC5 / AC6: tope acumulado por ítem
|--------------------------------------------------------------------------
*/

describe('RN-04-AC5 RN-04-AC6 tope acumulado', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC5: ítem de 10: 4 → 201, 6 → 201 (exacto al tope), 1 → 422 CANTIDAD_EXCEDE_PRESCRITO con pendiente 0 y sin cambios', function () {
        $s = rn04Escenario(10, 20);
        actuarComo('auxiliar_farmacia');

        rn04AfirmarCreada(rn04Dispensar($s, 4), $s, 4, 20);
        rn04AfirmarCreada(rn04Dispensar($s, 6), $s, 6, 16);
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, 1);

        rn04AfirmarExcede($r, $s['item']->id, 0, 1);
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(10)
            ->and(rn04Confirmado($s['item']))->toBe(10)
            ->and(DB::table('dispensaciones')->count())->toBe(2)
            ->and((int) DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->where('existencia_id', $s['existencia']->id)->sum('cantidad'))->toBe(-10);
    });

    it('RN-04-AC5: parciales acumuladas en orden; la última se acepta solo si no supera lo prescrito', function (int $prescrito, array $parciales, int $ultima, ?int $pendienteSiFalla) {
        $s = rn04Escenario($prescrito, 100);
        actuarComo('regente_farmacia');
        $stock = 100;
        foreach ($parciales as $p) {
            rn04AfirmarCreada(rn04Dispensar($s, $p), $s, $p, $stock);
            $stock -= $p;
        }
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, $ultima);

        if ($pendienteSiFalla === null) {
            rn04AfirmarCreada($r, $s, $ultima, $stock);
            expect(rn04Confirmado($s['item']))->toBe(array_sum($parciales) + $ultima)
                ->and(rn04Confirmado($s['item']))->toBeLessThanOrEqual($prescrito);
        } else {
            rn04AfirmarExcede($r, $s['item']->id, $pendienteSiFalla, $ultima);
            expect(rn04Instantanea())->toBe($antes)
                ->and(rn04Confirmado($s['item']))->toBe(array_sum($parciales));
        }
    })->with([
        'prescrito 10 de una vez' => [10, [], 10, null],
        'prescrito 10: 9 y luego 1 (exacto)' => [10, [9], 1, null],
        'prescrito 10: 9 y luego 2 (+1)' => [10, [9], 2, 1],
        'prescrito 10: 3+3+3 y luego 1 (exacto)' => [10, [3, 3, 3], 1, null],
        'prescrito 10: 3+3+3 y luego 2 (+1)' => [10, [3, 3, 3], 2, 1],
        'prescrito 10: 4+6 y luego 1 (pendiente 0)' => [10, [4, 6], 1, 0],
        'prescrito 10: 4 y luego 7 (pendiente 6)' => [10, [4], 7, 6],
        'prescrito 1: 1 y luego 1 (pendiente 0)' => [1, [1], 1, 0],
        'prescrito 1: 1 de una vez (exacto)' => [1, [], 1, null],
        'prescrito 10: 1×10 parciales y luego 1' => [10, [1, 1, 1, 1, 1, 1, 1, 1, 1, 1], 1, 0],
    ]);

    it('RN-04-AC5: el tope es del ítem, no del usuario ni de la bodega: 4 (auxiliar, bodega 1) + 6 (regente, bodega 2) agotan el ítem; 1 más desde cualquiera → 422 pendiente 0', function () {
        $s = rn04Escenario(10, 20);
        $bodega2 = Bodega::factory()->create();
        $existencia2 = rn04Stock($bodega2, $s['producto'], 20);

        actuarComo('auxiliar_farmacia');
        rn04AfirmarCreada(rn04Dispensar($s, 4), $s, 4, 20);

        olvidarAutenticacion();
        actuarComo('regente_farmacia');
        rn04AfirmarCreada(rn04Dispensar($s, 6, $bodega2), $s, 6, 20, $bodega2, $existencia2);

        $antes = rn04Instantanea();
        foreach ([$s['bodega'], $bodega2] as $bodega) {
            rn04AfirmarExcede(rn04Dispensar($s, 1, $bodega), $s['item']->id, 0, 1);
        }
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(16)
            ->and(rn04StockDe($existencia2))->toBe(14);
    });

    it('RN-04-AC6: ítem de 10, pedir más de 10 de una vez → 422 CANTIDAD_EXCEDE_PRESCRITO con pendiente 10 y sin cambios (aunque haya stock de sobra)', function (int $solicitado) {
        $s = rn04Escenario(10, 50);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, $solicitado);

        rn04AfirmarExcede($r, $s['item']->id, 10, $solicitado);
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(50);
    })->with([
        'prescrito + 1 (11)' => [11],
        'prescrito + 2 (12)' => [12],
        'stock exacto (50)' => [50],
        'máximo admitido por S-41 (1.000.000)' => [1_000_000],
    ]);

    it('RN-04-AC6 S-43: la previsualización responde igual: 11 sobre 10 → 422 pendiente 10; 10 → 200; tras dispensar 4, 7 → 422 pendiente 6; nunca escribe', function () {
        $s = rn04Escenario(10, 50);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        rn04AfirmarExcede(rn04Previsualizar($s, 11), $s['item']->id, 10, 11);
        rn04Previsualizar($s, 10)->assertOk();
        expect(rn04Instantanea())->toBe($antes);

        rn04AfirmarCreada(rn04Dispensar($s, 4), $s, 4, 50);
        $tras = rn04Instantanea();

        rn04AfirmarExcede(rn04Previsualizar($s, 7), $s['item']->id, 6, 7);
        rn04Previsualizar($s, 6)->assertOk();
        expect(rn04Instantanea())->toBe($tras);
    });

    it('RN-04-AC6 S-04: con dos ítems, si el segundo excede su tope la petición entera se rechaza (422 con el ítem que excede) y el primero no se dispensa', function (string $uri) {
        $s = rn04Escenario(10, 20);
        $producto2 = Producto::factory()->create();
        $existencia2 = rn04Stock($s['bodega'], $producto2, 20);
        $item2 = PrescripcionItem::factory()->create(['prescripcion_id' => $s['prescripcion']->id, 'producto_id' => $producto2->id, 'cantidad' => 5]);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $cuerpo = rn04Cuerpo($s, 10);
        $cuerpo['items'][] = ['prescripcion_item_id' => $item2->id, 'cantidad' => 6];
        $r = rn04Post($uri, $cuerpo);

        rn04AfirmarExcede($r, $item2->id, 5, 6);
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(20)
            ->and(rn04StockDe($existencia2))->toBe(20);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ]);

    it('RN-04-AC5: varios ítems de la misma prescripción tienen topes independientes', function () {
        $s = rn04Escenario(10, 30);
        $producto2 = Producto::factory()->create();
        $existencia2 = rn04Stock($s['bodega'], $producto2, 30);
        $item2 = PrescripcionItem::factory()->create(['prescripcion_id' => $s['prescripcion']->id, 'producto_id' => $producto2->id, 'cantidad' => 5]);
        actuarComo('auxiliar_farmacia');

        // Agota el ítem 1; el ítem 2 sigue con 5.
        rn04AfirmarCreada(rn04Dispensar($s, 10), $s, 10, 30);
        $r = rn04Post(RN04_URI, rn04Cuerpo($s, 5, $item2));
        $r->assertCreated();
        expect(rn04StockDe($existencia2))->toBe(25)
            ->and(rn04Confirmado($item2))->toBe(5);

        $antes = rn04Instantanea();
        rn04AfirmarExcede(rn04Dispensar($s, 1), $s['item']->id, 0, 1);
        rn04AfirmarExcede(rn04Post(RN04_URI, rn04Cuerpo($s, 1, $item2)), $item2->id, 0, 1);
        expect(rn04Instantanea())->toBe($antes);
    });

    it('RN-04-AC5: dos ítems en una sola petición, cada uno exacto a su tope → 201 con ambos; repetir cualquiera → 422', function () {
        $s = rn04Escenario(10, 30);
        $producto2 = Producto::factory()->create();
        $existencia2 = rn04Stock($s['bodega'], $producto2, 30);
        $item2 = PrescripcionItem::factory()->create(['prescripcion_id' => $s['prescripcion']->id, 'producto_id' => $producto2->id, 'cantidad' => 5]);
        actuarComo('regente_farmacia');

        $cuerpo = rn04Cuerpo($s, 10);
        $cuerpo['items'][] = ['prescripcion_item_id' => $item2->id, 'cantidad' => 5];
        $r = rn04Post(RN04_URI, $cuerpo);

        $r->assertCreated();
        expect(rn04StockDe($s['existencia']))->toBe(20)
            ->and(rn04StockDe($existencia2))->toBe(25)
            ->and(rn04Confirmado($s['item']))->toBe(10)
            ->and(rn04Confirmado($item2))->toBe(5);
        rn04AfirmarCadena($s['existencia']);
        rn04AfirmarCadena($existencia2);

        $antes = rn04Instantanea();
        rn04AfirmarExcede(rn04Post(RN04_URI, rn04Cuerpo($s, 1, $item2)), $item2->id, 0, 1);
        expect(rn04Instantanea())->toBe($antes);
    });
});

/*
|--------------------------------------------------------------------------
| RN-04-AC7: cantidad entera positiva (S-07, S-41)
|--------------------------------------------------------------------------
*/

describe('RN-04-AC7 cantidad inválida', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC7: cantidad 0, negativa, no entera, booleana, nula, texto o fuera de rango → 422 VALIDACION en items.0.cantidad y sin cambios, al dispensar y al previsualizar', function (string $uri, mixed $cantidad) {
        $s = rn04Escenario(10, 20);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Post($uri, rn04Cuerpo($s, $cantidad));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('items.0.cantidad')
            ->and(rn04Instantanea())->toBe($antes);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ])->with([
        'cero' => [0],
        'negativa -1' => [-1],
        'negativa -10' => [-10],
        'decimal 1.5' => [1.5],
        'decimal 0.5' => [0.5],
        'entero escrito como decimal 2.0' => [2.0],
        'booleano true' => [true],
        'booleano false' => [false],
        'null' => [null],
        'texto' => ['abc'],
        'entero como texto "5"' => ['5'],
        'arreglo' => [[3]],
        'por encima del máximo de S-41 (1.000.001)' => [1_000_001],
    ]);

    it('RN-04-AC7: ítem sin cantidad → 422 VALIDACION sin cambios', function () {
        $s = rn04Escenario(10, 20);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn04Cuerpo($s, 2);
        unset($cuerpo['items'][0]['cantidad']);
        $antes = rn04Instantanea();

        $r = rn04Post(RN04_URI, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('items.0.cantidad')
            ->and(rn04Instantanea())->toBe($antes);
    });

    it('RN-04-AC7 S-27: la forma se valida antes que la vigencia y el tope: prescripción vencida con cantidad 0 → 422 VALIDACION (no PRESCRIPCION_NO_VIGENTE)', function () {
        $s = rn04Escenario(10, 20, ['fecha_emision' => rn04Dia(-60), 'fecha_vencimiento' => rn04Dia(-1)]);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, 0);

        esperarError($r, 422, 'VALIDACION');
        expect(rn04Instantanea())->toBe($antes);
    });
});

/*
|--------------------------------------------------------------------------
| RN-04-AC9: dispensaciones PENDIENTE_AUTORIZACION cuentan, RECHAZADA no (S-06)
|--------------------------------------------------------------------------
*/

describe('RN-04-AC9 pendientes de autorización y rechazadas (flujo real RN-05)', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC9: flujo real: un auxiliar crea por POST una de 3 sobre un ítem de control especial de 10 (PENDIENTE); pedir 8 → 422 pendiente 7 sin cambios (también al previsualizar); un regente distinto la rechaza por /rechazar; pedir 10 → 201', function () {
        $s = rn04EscenarioControl();
        $auxiliar = usuarioConRol('auxiliar_farmacia');
        Sanctum::actingAs($auxiliar);

        $r = rn04Dispensar($s, 3);
        $r->assertCreated();
        $pendienteId = cuerpoDatos($r)['id'];
        expect(cuerpoDatos($r)['estado'])->toBe('PENDIENTE_AUTORIZACION')
            ->and(rn04StockDe($s['existencia']))->toBe(20);

        $antes = rn04Instantanea();
        rn04AfirmarExcede(rn04Dispensar($s, 8), $s['item']->id, 7, 8);
        rn04AfirmarExcede(rn04Previsualizar($s, 8), $s['item']->id, 7, 8);
        expect(rn04Instantanea())->toBe($antes);

        Sanctum::actingAs(usuarioConRol('regente_farmacia'));
        $rechazo = test()->postJson(RN04_URI."/{$pendienteId}/rechazar", ['motivo' => 'Soporte de control especial incompleto']);
        $rechazo->assertOk();
        expect(cuerpoDatos($rechazo)['estado'])->toBe('RECHAZADA')
            ->and(DB::table('movimientos_kardex')->where('documento_tipo', 'dispensacion')->where('documento_id', $pendienteId)->count())->toBe(0);

        // Pendiente de vuelta en 10: lo dice la previsualización (200) y la creación de 10 (201,
        // nueva PENDIENTE_AUTORIZACION por ser de control especial; el stock sigue sin moverse).
        Sanctum::actingAs($auxiliar);
        rn04Previsualizar($s, 10)->assertOk();
        rn04AfirmarExcede(rn04Previsualizar($s, 11), $s['item']->id, 10, 11);
        $nueva = rn04Dispensar($s, 10);
        $nueva->assertCreated();
        expect(cuerpoDatos($nueva)['estado'])->toBe('PENDIENTE_AUTORIZACION')
            ->and(cuerpoDatos($nueva)['id'])->not->toBe($pendienteId)
            ->and(DB::table('dispensaciones')->where('id', $pendienteId)->value('estado'))->toBe('RECHAZADA')
            ->and(rn04StockDe($s['existencia']))->toBe(20);
    });

    it('RN-04-AC9: flujo real, variante: si un regente distinto AUTORIZA la de 3 en vez de rechazarla, sigue restando 3: pedir 8 → 422 pendiente 7 sin cambios; pedir 7 → 201', function () {
        $s = rn04EscenarioControl();
        $auxiliar = usuarioConRol('auxiliar_farmacia');
        Sanctum::actingAs($auxiliar);

        $r = rn04Dispensar($s, 3);
        $r->assertCreated();
        $pendienteId = cuerpoDatos($r)['id'];

        Sanctum::actingAs(usuarioConRol('regente_farmacia'));
        $autorizada = test()->postJson(RN04_URI."/{$pendienteId}/autorizar");
        $autorizada->assertOk();
        expect(cuerpoDatos($autorizada)['estado'])->toBe('CONFIRMADA')
            ->and(rn04StockDe($s['existencia']))->toBe(17)
            ->and(rn04Confirmado($s['item']))->toBe(3);

        Sanctum::actingAs($auxiliar);
        $antes = rn04Instantanea();
        rn04AfirmarExcede(rn04Dispensar($s, 8), $s['item']->id, 7, 8);
        rn04AfirmarExcede(rn04Previsualizar($s, 8), $s['item']->id, 7, 8);
        expect(rn04Instantanea())->toBe($antes);

        $siete = rn04Dispensar($s, 7);
        $siete->assertCreated();
        expect(cuerpoDatos($siete)['estado'])->toBe('PENDIENTE_AUTORIZACION');
        rn04AfirmarExcede(rn04Dispensar($s, 1), $s['item']->id, 0, 1);
    });
});

describe('RN-04-AC9 pendientes de autorización y rechazadas (cálculo con estados preparados por SQL)', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC9 (cálculo con estados preparados): con una PENDIENTE_AUTORIZACION de 3 sobre un ítem de 10, pedir 8 → 422 con pendiente 7; tras RECHAZARLA, 8 → 201', function () {
        $s = rn04Escenario(10, 20);
        $pendienteId = rn04DispensacionEnEstado($s, $s['item'], 3, 'PENDIENTE_AUTORIZACION');
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        rn04AfirmarExcede(rn04Dispensar($s, 8), $s['item']->id, 7, 8);
        rn04AfirmarExcede(rn04Previsualizar($s, 8), $s['item']->id, 7, 8);
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(20);

        rn04Rechazar($pendienteId);

        rn04AfirmarCreada(rn04Dispensar($s, 8), $s, 8, 20);
        expect(DB::table('dispensaciones')->where('id', $pendienteId)->value('estado'))->toBe('RECHAZADA');
    });

    it('RN-04-AC9 S-06 (cálculo con estados preparados): la PENDIENTE_AUTORIZACION de 3 no reserva stock pero sí tope: con stock 20 se pueden dispensar 7 (exacto) y el stock queda en 13', function () {
        $s = rn04Escenario(10, 20);
        rn04DispensacionEnEstado($s, $s['item'], 3, 'PENDIENTE_AUTORIZACION');
        actuarComo('regente_farmacia');

        rn04AfirmarCreada(rn04Dispensar($s, 7), $s, 7, 20);

        $antes = rn04Instantanea();
        rn04AfirmarExcede(rn04Dispensar($s, 1), $s['item']->id, 0, 1);
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(13);
    });

    it('RN-04-AC9 (cálculo con estados preparados): una RECHAZADA de 10 no consume el tope: se dispensan 10 (201)', function () {
        $s = rn04Escenario(10, 20);
        rn04DispensacionEnEstado($s, $s['item'], 10, 'RECHAZADA');
        actuarComo('auxiliar_farmacia');

        rn04AfirmarCreada(rn04Dispensar($s, 10), $s, 10, 20);
    });

    it('RN-04-AC9 RN-04-AC5 (cálculo con estados preparados): mezcla de estados: CONFIRMADA 2 (real) + PENDIENTE 3 + RECHAZADA 4 sobre 10 → pendiente 5: 6 → 422 pendiente 5; 5 → 201', function () {
        $s = rn04Escenario(10, 20);
        actuarComo('auxiliar_farmacia');
        rn04AfirmarCreada(rn04Dispensar($s, 2), $s, 2, 20);
        rn04DispensacionEnEstado($s, $s['item'], 3, 'PENDIENTE_AUTORIZACION');
        rn04DispensacionEnEstado($s, $s['item'], 4, 'RECHAZADA');
        $antes = rn04Instantanea();

        rn04AfirmarExcede(rn04Dispensar($s, 6), $s['item']->id, 5, 6);
        expect(rn04Instantanea())->toBe($antes);

        rn04AfirmarCreada(rn04Dispensar($s, 5), $s, 5, 18);
    });
});

/*
|--------------------------------------------------------------------------
| RN-04-AC10: roles (S-50)
|--------------------------------------------------------------------------
*/

describe('RN-04-AC10 roles', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC10: médico, auditor y admin → 403 NO_AUTORIZADO sin cambios, al dispensar y al previsualizar', function (string $uri, string $rol) {
        $s = rn04Escenario(10, 20);
        actuarComo($rol);
        $antes = rn04Instantanea();

        $r = rn04Post($uri, rn04Cuerpo($s, 2));

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn04Instantanea())->toBe($antes);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ])->with([
        'medico' => 'medico',
        'auditor' => 'auditor',
        'admin' => 'admin',
    ]);

    it('RN-04-AC10 S-50: el 403 precede a las reglas de negocio: médico/auditor con prescripción vencida o con exceso → 403 NO_AUTORIZADO', function (string $rol, string $caso) {
        $atributos = $caso === 'vencida' ? ['fecha_emision' => rn04Dia(-60), 'fecha_vencimiento' => rn04Dia(-1)] : [];
        $s = rn04Escenario(10, 20, $atributos);
        actuarComo($rol);
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, $caso === 'exceso' ? 11 : 2);

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn04Instantanea())->toBe($antes);
    })->with(['medico', 'auditor'])->with(['vencida', 'exceso']);

    it('RN-04-AC10: sin token → 401 NO_AUTENTICADO sin cambios', function (string $uri) {
        $s = rn04Escenario(10, 20);
        $antes = rn04Instantanea();

        $r = rn04Post($uri, rn04Cuerpo($s, 2));

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn04Instantanea())->toBe($antes);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ]);

    it('RN-04-AC10: auxiliar y regente sí dispensan (201)', function (string $rol) {
        $s = rn04Escenario(10, 20);
        $usuario = actuarComo($rol);

        $id = rn04AfirmarCreada(rn04Dispensar($s, 2), $s, 2, 20);

        expect((int) DB::table('dispensaciones')->where('id', $id)->value('dispensado_por_id'))->toBe($usuario->id);
    })->with(['auxiliar_farmacia', 'regente_farmacia']);
});

/*
|--------------------------------------------------------------------------
| RN-04-AC11: la salida es siempre del producto prescrito
|--------------------------------------------------------------------------
*/

describe('RN-04-AC11 producto prescrito', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC11 S-41 S-51: producto_id o lote_id enviados por el cliente (en el ítem o en la raíz, incluso null) → 422 VALIDACION sin cambios, al dispensar y al previsualizar', function (string $uri, string $donde, string $clave, string $valor) {
        $s = rn04Escenario(10, 20);
        $otroProducto = Producto::factory()->create();
        $otraExistencia = rn04Stock($s['bodega'], $otroProducto, 20, rn04Dia(10));
        $valores = [
            'otro producto' => $otroProducto->id,
            'producto prescrito' => $s['producto']->id,
            'lote del prescrito' => $s['existencia']->lote_id,
            'lote de otro producto' => $otraExistencia->lote_id,
            'null' => null,
        ];
        $cuerpo = rn04Cuerpo($s, 2);
        if ($donde === 'item') {
            $cuerpo['items'][0][$clave] = $valores[$valor];
        } else {
            $cuerpo[$clave] = $valores[$valor];
        }
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Post($uri, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($otraExistencia))->toBe(20);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ])->with([
        'ítem con producto_id de otro producto' => ['item', 'producto_id', 'otro producto'],
        'ítem con producto_id del propio producto' => ['item', 'producto_id', 'producto prescrito'],
        'ítem con producto_id null' => ['item', 'producto_id', 'null'],
        'ítem con lote_id del producto prescrito' => ['item', 'lote_id', 'lote del prescrito'],
        'ítem con lote_id de otro producto' => ['item', 'lote_id', 'lote de otro producto'],
        'raíz con producto_id' => ['raiz', 'producto_id', 'otro producto'],
        'raíz con lote_id' => ['raiz', 'lote_id', 'lote de otro producto'],
    ]);

    it('RN-04-AC11: con otro producto en la bodega que vence antes, la salida es solo del producto prescrito (detalles, ítems y kardex) y el otro queda intacto', function () {
        $s = rn04Escenario(10, 20);
        $otroProducto = Producto::factory()->create();
        $otraExistencia = rn04Stock($s['bodega'], $otroProducto, 20, rn04Dia(5));
        actuarComo('auxiliar_farmacia');

        $r = rn04Dispensar($s, 4);

        $id = rn04AfirmarCreada($r, $s, 4, 20);
        $detalles = cuerpoDatos($r)['detalles'] ?? null;
        expect($detalles)->toBeArray()->not->toBeEmpty();
        foreach ($detalles as $d) {
            expect($d['producto_id'] ?? null)->toBe($s['producto']->id)
                ->and($d['lote_id'] ?? null)->toBe($s['existencia']->lote_id);
        }
        expect(rn04StockDe($otraExistencia))->toBe(20)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $otraExistencia->id)->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(0)
            ->and(DB::table('dispensacion_detalles')->where('dispensacion_id', $id)->where('producto_id', $otroProducto->id)->count())->toBe(0);
    });

    it('RN-04-AC11 S-42: si el producto prescrito no tiene stock, no se sustituye por otro producto con stock: 409 STOCK_INSUFICIENTE sin cambios', function () {
        $s = rn04Escenario(10, 0);
        $otroProducto = Producto::factory()->create();
        $otraExistencia = rn04Stock($s['bodega'], $otroProducto, 20);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Dispensar($s, 2);

        esperarError($r, 409, 'STOCK_INSUFICIENTE');
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($otraExistencia))->toBe(20);
    });
});

/*
|--------------------------------------------------------------------------
| Precedencia: vigencia → tope (422) → stock (409)
|--------------------------------------------------------------------------
*/

describe('RN-04 precedencia de errores', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC2 RN-04-AC3 RN-04-AC6: vigencia → tope → stock, al dispensar y al previsualizar', function (string $uri, string $prescripcion, int $stock, int $cantidad, int $status, string $codigo) {
        $atributos = match ($prescripcion) {
            'vencida' => ['fecha_emision' => rn04Dia(-60), 'fecha_vencimiento' => rn04Dia(-1)],
            'anulada' => ['estado' => 'ANULADA'],
            'vigente' => [],
        };
        $s = rn04Escenario(10, $stock, $atributos);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        $r = rn04Post($uri, rn04Cuerpo($s, $cantidad));

        esperarError($r, $status, $codigo);
        if ($codigo === 'CANTIDAD_EXCEDE_PRESCRITO') {
            rn04AfirmarExcede($r, $s['item']->id, 10, $cantidad);
        }
        expect(rn04Instantanea())->toBe($antes);
    })->with([
        'dispensar' => RN04_URI,
        'previsualizar' => RN04_URI_PREVIA,
    ])->with([
        'vencida + exceso + sin stock → NO_VIGENTE' => ['vencida', 0, 11, 422, 'PRESCRIPCION_NO_VIGENTE'],
        'anulada + exceso + sin stock → NO_VIGENTE' => ['anulada', 0, 11, 422, 'PRESCRIPCION_NO_VIGENTE'],
        'vencida + dentro del tope + sin stock → NO_VIGENTE' => ['vencida', 0, 2, 422, 'PRESCRIPCION_NO_VIGENTE'],
        'vigente + exceso + sin stock → EXCEDE (no 409)' => ['vigente', 0, 11, 422, 'CANTIDAD_EXCEDE_PRESCRITO'],
        'vigente + exceso + stock parcial → EXCEDE (no 409)' => ['vigente', 5, 11, 422, 'CANTIDAD_EXCEDE_PRESCRITO'],
        'vigente + dentro del tope + sin stock → 409' => ['vigente', 0, 10, 409, 'STOCK_INSUFICIENTE'],
        'vigente + dentro del tope + stock 1 menos → 409' => ['vigente', 9, 10, 409, 'STOCK_INSUFICIENTE'],
    ]);

    it('RN-04-AC5 RN-03: tope agotado y sin stock → 422 CANTIDAD_EXCEDE_PRESCRITO pendiente 0, no 409', function () {
        $s = rn04Escenario(10, 10);
        actuarComo('auxiliar_farmacia');
        rn04AfirmarCreada(rn04Dispensar($s, 10), $s, 10, 10);
        $antes = rn04Instantanea();

        rn04AfirmarExcede(rn04Dispensar($s, 1), $s['item']->id, 0, 1);
        expect(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| Idempotencia (S-11, S-52) frente a RN-04
|--------------------------------------------------------------------------
*/

describe('RN-04 con idempotencia', function () {
    beforeEach(fn () => rn04Congelar());

    it('RN-04-AC3 S-52: crear con K, anular la prescripción y reintentar con K → reproduce el 201 original (no 422) sin nueva salida', function () {
        $s = rn04Escenario(10, 20);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn04Cuerpo($s, 4);

        $original = rn04Post(RN04_URI, $cuerpo, 'rn04-s52-anulada');
        rn04AfirmarCreada($original, $s, 4, 20);
        rn04Anular($s['prescripcion']);
        $antes = rn04Instantanea();

        $reintento = rn04Post(RN04_URI, $cuerpo, 'rn04-s52-anulada');

        $reintento->assertStatus(201);
        expect($reintento->headers->get('Idempotent-Replayed'))->toBe('true')
            ->and($reintento->json())->toBe($original->json())
            ->and(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(16);
    });

    it('RN-04-AC2 S-52: crear con K el día que vence, reintentar con K al día siguiente → reproduce el 201 original (no PRESCRIPCION_NO_VIGENTE)', function () {
        $s = rn04Escenario(10, 20, ['fecha_emision' => rn04Dia(-10), 'fecha_vencimiento' => RN04_HOY]);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn04Cuerpo($s, 4);

        $original = rn04Post(RN04_URI, $cuerpo, 'rn04-s52-vencida');
        rn04AfirmarCreada($original, $s, 4, 20);
        rn04Congelar(rn04Dia(1).' 09:00:00');
        $antes = rn04Instantanea();

        $reintento = rn04Post(RN04_URI, $cuerpo, 'rn04-s52-vencida');

        $reintento->assertStatus(201);
        expect($reintento->headers->get('Idempotent-Replayed'))->toBe('true')
            ->and($reintento->json())->toBe($original->json())
            ->and(rn04Instantanea())->toBe($antes);
    });

    it('RN-04-AC5 S-52: dispensar los 10 prescritos con K y reintentar con K → reproduce el 201 (no 422 pendiente 0) y el stock baja una sola vez', function () {
        $s = rn04Escenario(10, 20);
        actuarComo('regente_farmacia');
        $cuerpo = rn04Cuerpo($s, 10);

        $original = rn04Post(RN04_URI, $cuerpo, 'rn04-s52-tope');
        rn04AfirmarCreada($original, $s, 10, 20);
        $antes = rn04Instantanea();

        $reintento = rn04Post(RN04_URI, $cuerpo, 'rn04-s52-tope');

        $reintento->assertStatus(201);
        expect($reintento->headers->get('Idempotent-Replayed'))->toBe('true')
            ->and($reintento->json())->toBe($original->json())
            ->and(rn04Instantanea())->toBe($antes)
            ->and(rn04StockDe($s['existencia']))->toBe(10)
            ->and(rn04Confirmado($s['item']))->toBe(10);
    });

    it('RN-04-AC6 S-11: un 422 CANTIDAD_EXCEDE_PRESCRITO con K no guarda la clave: la misma K con una cantidad válida crea la dispensación (201 original)', function () {
        $s = rn04Escenario(10, 20);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();

        rn04AfirmarExcede(rn04Post(RN04_URI, rn04Cuerpo($s, 11), 'rn04-s11'), $s['item']->id, 10, 11);
        expect(rn04Instantanea())->toBe($antes);

        $r = rn04Post(RN04_URI, rn04Cuerpo($s, 10), 'rn04-s11');

        rn04AfirmarCreada($r, $s, 10, 20);
        expect($r->headers->get('Idempotent-Replayed'))->toBeNull();
    });

    it('RN-04-AC3 S-11: un 422 PRESCRIPCION_NO_VIGENTE con K no guarda la clave: reintentar con K sigue dando 422 y nada cambia', function () {
        $s = rn04Escenario(10, 20, ['estado' => 'ANULADA']);
        actuarComo('auxiliar_farmacia');
        $antes = rn04Instantanea();
        $cuerpo = rn04Cuerpo($s, 2);

        esperarError(rn04Post(RN04_URI, $cuerpo, 'rn04-s11-anulada'), 422, 'PRESCRIPCION_NO_VIGENTE');
        $r = rn04Post(RN04_URI, $cuerpo, 'rn04-s11-anulada');

        esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
        expect($r->headers->get('Idempotent-Replayed'))->toBeNull()
            ->and(rn04Instantanea())->toBe($antes);
    });
});
