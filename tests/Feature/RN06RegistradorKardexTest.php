<?php

declare(strict_types=1);

use App\Enums\DocumentoOrigen;
use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\ExcepcionDominio;
use App\Models\Existencia;
use App\Models\MovimientoKardex;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * RN-06 — función crítica RegistradorKardex::registrar (saldos, signo por tipo, motivo
 * obligatorio en ajuste, documento origen). Toca la BD: va en Feature con RefreshDatabase.
 * "Exige transacción" se prueba sin transacción envolvente en
 * tests/Feature/SinTransaccion/RN06KardexSemillaTest.php.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-04 10:00:00', 'America/Bogota'));
    $this->usuario = usuarioConRol('regente_farmacia');
});

/** Llama a registrar() dentro de una transacción, con la existencia releída y bloqueada. */
function rn06RegRegistrar(int $existenciaId, TipoMovimiento $tipo, int $cantidad, User $usuario, ?string $motivo = null, ?DocumentoOrigen $doc = null, ?int $docId = null): MovimientoKardex
{
    return DB::transaction(function () use ($existenciaId, $tipo, $cantidad, $usuario, $motivo, $doc, $docId): MovimientoKardex {
        $existencia = Existencia::query()->lockForUpdate()->findOrFail($existenciaId);

        return app(RegistradorKardex::class)->registrar($tipo, $existencia, $cantidad, $usuario->id, $motivo, $doc, $docId);
    });
}

/** @return array{stock: int, kardex: list<array<string, mixed>>} */
function rn06RegEstado(int $existenciaId): array
{
    return [
        'stock' => (int) DB::table('existencias')->where('id', $existenciaId)->value('cantidad'),
        'kardex' => DB::table('movimientos_kardex')->orderBy('id')->get()->map(fn (object $f): array => (array) $f)->all(),
    ];
}

it('RN-06-AC1 RN-06-AC2: registrar() calcula saldo_anterior/posterior desde el saldo bloqueado, actualiza la existencia y guarda todos los campos', function (TipoMovimiento $tipo, int $cantidad, ?string $motivo, ?DocumentoOrigen $doc) {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);

    $m = rn06RegRegistrar($existencia->id, $tipo, $cantidad, $this->usuario, $motivo, $doc, $doc !== null ? 77 : null);

    $fila = DB::table('movimientos_kardex')->where('id', $m->id)->sole();
    expect($fila->tipo)->toBe($tipo->value)
        ->and((int) $fila->cantidad)->toBe($cantidad)
        ->and((int) $fila->saldo_anterior)->toBe(10)
        ->and((int) $fila->saldo_posterior)->toBe(10 + $cantidad)
        ->and((int) $fila->usuario_id)->toBe($this->usuario->id)
        ->and((int) $fila->existencia_id)->toBe($existencia->id)
        ->and((int) $fila->bodega_id)->toBe($existencia->bodega_id)
        ->and((int) $fila->producto_id)->toBe($existencia->producto_id)
        ->and((int) $fila->lote_id)->toBe($existencia->lote_id)
        ->and($fila->motivo)->toBe($motivo)
        ->and($fila->documento_tipo)->toBe($doc?->value)
        ->and($fila->documento_id === null ? null : (int) $fila->documento_id)->toBe($doc !== null ? 77 : null)
        ->and(Carbon::parse($fila->created_at, config('app.timezone'))->equalTo(now()))->toBeTrue()
        ->and((int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad'))->toBe(10 + $cantidad);
})->with([
    'ENTRADA +7' => [TipoMovimiento::Entrada, 7, null, null],
    'ENTRADA_TRASLADO +4 (doc traslado)' => [TipoMovimiento::EntradaTraslado, 4, null, DocumentoOrigen::Traslado],
    'SALIDA_DISPENSACION −4 (doc dispensación)' => [TipoMovimiento::SalidaDispensacion, -4, null, DocumentoOrigen::Dispensacion],
    'SALIDA_TRASLADO −10 hasta 0 (doc traslado)' => [TipoMovimiento::SalidaTraslado, -10, null, DocumentoOrigen::Traslado],
    'AJUSTE +3' => [TipoMovimiento::Ajuste, 3, 'Sobrante en conteo físico', null],
    'AJUSTE −10 hasta 0' => [TipoMovimiento::Ajuste, -10, 'Baja total por rotura', null],
]);

it('RN-06-AC2: una serie de registrar() encadena saldos y SUM(cantidad) = existencias.cantidad', function () {
    $existencia = Existencia::factory()->create(['cantidad' => 0]);
    $pasos = [
        [TipoMovimiento::Entrada, 20, null, null],
        [TipoMovimiento::SalidaDispensacion, -5, null, DocumentoOrigen::Dispensacion],
        [TipoMovimiento::Ajuste, -3, 'Vencimiento anticipado por calor', null],
        [TipoMovimiento::SalidaTraslado, -12, null, DocumentoOrigen::Traslado],
        [TipoMovimiento::EntradaTraslado, 6, null, DocumentoOrigen::Traslado],
        [TipoMovimiento::Ajuste, 1, 'Unidad encontrada en devolución', null],
    ];

    foreach ($pasos as [$tipo, $cantidad, $motivo, $doc]) {
        rn06RegRegistrar($existencia->id, $tipo, $cantidad, $this->usuario, $motivo, $doc, $doc !== null ? 1 : null);
    }

    $movimientos = DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->orderBy('id')->get();
    $saldo = 0;
    foreach ($movimientos as $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo)
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad);
        $saldo = (int) $m->saldo_posterior;
    }

    expect($movimientos)->toHaveCount(6)
        ->and($saldo)->toBe(7)
        ->and((int) $movimientos->sum('cantidad'))->toBe(7)
        ->and((int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad'))->toBe(7);
});

it('RN-06-AC1: el signo de la cantidad debe ser coherente con el tipo (+ entra, − sale; AJUSTE ≠ 0); si no, se rechaza sin cambios', function (TipoMovimiento $tipo, int $cantidad, ?DocumentoOrigen $doc) {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);
    $antes = rn06RegEstado($existencia->id);

    expect(fn () => rn06RegRegistrar($existencia->id, $tipo, $cantidad, $this->usuario, $tipo === TipoMovimiento::Ajuste ? 'Motivo suficientemente largo' : null, $doc, $doc !== null ? 1 : null))
        ->toThrow(InvalidArgumentException::class, 'no válida para un movimiento '.$tipo->value);

    expect(rn06RegEstado($existencia->id))->toBe($antes);
})->with([
    'ENTRADA negativa' => [TipoMovimiento::Entrada, -1, null],
    'ENTRADA 0' => [TipoMovimiento::Entrada, 0, null],
    'ENTRADA_TRASLADO negativa' => [TipoMovimiento::EntradaTraslado, -1, DocumentoOrigen::Traslado],
    'SALIDA_DISPENSACION positiva' => [TipoMovimiento::SalidaDispensacion, 1, DocumentoOrigen::Dispensacion],
    'SALIDA_DISPENSACION 0' => [TipoMovimiento::SalidaDispensacion, 0, DocumentoOrigen::Dispensacion],
    'SALIDA_TRASLADO positiva' => [TipoMovimiento::SalidaTraslado, 1, DocumentoOrigen::Traslado],
    'AJUSTE 0' => [TipoMovimiento::Ajuste, 0, null],
]);

it('RN-06-AC6 S-33: un AJUSTE exige motivo de al menos 10 caracteres (sin contar espacios de los extremos); si no, se rechaza sin cambios', function (?string $motivo) {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);
    $antes = rn06RegEstado($existencia->id);

    expect(fn () => rn06RegRegistrar($existencia->id, TipoMovimiento::Ajuste, -1, $this->usuario, $motivo))
        ->toThrow(InvalidArgumentException::class, 'Un movimiento AJUSTE exige motivo de al menos 10 caracteres.');

    expect(rn06RegEstado($existencia->id))->toBe($antes);
})->with([
    'null' => [null],
    'vacío' => [''],
    'solo espacios' => [str_repeat(' ', 12)],
    '9 caracteres' => ['abcdefghi'],
    '9 caracteres con espacios alrededor' => ['    abcdefghi    '],
    '9 multibyte' => [str_repeat('é', 9)],
]);

it('RN-06-AC6 S-33: un motivo de más de 500 caracteres se rechaza (InvalidArgumentException) sin cambios, en cualquier tipo', function (TipoMovimiento $tipo, int $cantidad, string $motivo) {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);
    $antes = rn06RegEstado($existencia->id);

    expect(fn () => rn06RegRegistrar($existencia->id, $tipo, $cantidad, $this->usuario, $motivo))
        ->toThrow(InvalidArgumentException::class, 'El motivo supera 500 caracteres.');

    expect(rn06RegEstado($existencia->id))->toBe($antes);
})->with([
    'AJUSTE con 501 ASCII' => [TipoMovimiento::Ajuste, -1, str_repeat('a', 501)],
    'AJUSTE con 501 multibyte' => [TipoMovimiento::Ajuste, 1, str_repeat('é', 501)],
    'ENTRADA con 501' => [TipoMovimiento::Entrada, 1, str_repeat('a', 501)],
]);

it('RN-06-AC6 S-33 frontera: un AJUSTE con motivo de exactamente 500 caracteres (también multibyte) se registra', function (string $motivo) {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);

    $m = rn06RegRegistrar($existencia->id, TipoMovimiento::Ajuste, -1, $this->usuario, $motivo);

    expect(DB::table('movimientos_kardex')->where('id', $m->id)->value('motivo'))->toBe($motivo)
        ->and((int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad'))->toBe(9);
})->with(['ASCII' => [str_repeat('a', 500)], 'multibyte' => [str_repeat('é', 500)]]);

it('RN-06-AC2 RN-06-AC7: una existencia sin guardar se rechaza (LogicException) sin crear existencia ni kardex', function () {
    $existencia = Existencia::factory()->make(['cantidad' => 0]);
    $existenciasAntes = DB::table('existencias')->count();

    expect(fn () => DB::transaction(fn () => app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $existencia, 5, $this->usuario->id)))
        ->toThrow(LogicException::class, 'La existencia debe estar guardada antes de registrar movimientos.');

    expect($existencia->exists)->toBeFalse()
        ->and(DB::table('existencias')->count())->toBe($existenciasAntes)
        ->and(DB::table('movimientos_kardex')->count())->toBe(0);
});

it('RN-06-AC6 S-33 frontera: un AJUSTE con motivo de exactamente 10 caracteres se registra', function (string $motivo) {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);

    $m = rn06RegRegistrar($existencia->id, TipoMovimiento::Ajuste, -1, $this->usuario, $motivo);

    expect(DB::table('movimientos_kardex')->where('id', $m->id)->value('motivo'))->toBe($motivo)
        ->and((int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad'))->toBe(9);
})->with(['ASCII' => ['abcdefghij'], 'multibyte' => [str_repeat('é', 10)]]);

it('RN-06-AC1 S-35: una salida o entrada por traslado sin su documento origen se rechaza sin cambios', function (TipoMovimiento $tipo, int $cantidad, ?DocumentoOrigen $doc, ?int $docId) {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);
    $antes = rn06RegEstado($existencia->id);

    expect(fn () => rn06RegRegistrar($existencia->id, $tipo, $cantidad, $this->usuario, null, $doc, $docId))
        ->toThrow(InvalidArgumentException::class, 'Documento origen no válido para un movimiento '.$tipo->value.'.');

    expect(rn06RegEstado($existencia->id))->toBe($antes);
})->with([
    'SALIDA_DISPENSACION sin documento' => [TipoMovimiento::SalidaDispensacion, -1, null, null],
    'SALIDA_DISPENSACION con documento de traslado' => [TipoMovimiento::SalidaDispensacion, -1, DocumentoOrigen::Traslado, 1],
    'SALIDA_TRASLADO sin id de documento' => [TipoMovimiento::SalidaTraslado, -1, DocumentoOrigen::Traslado, null],
    'ENTRADA_TRASLADO sin documento' => [TipoMovimiento::EntradaTraslado, 1, null, null],
]);

it('RN-06-AC6 RN-03-AC4: un movimiento que dejaría el saldo negativo lanza STOCK_INSUFICIENTE (409) sin cambios', function (TipoMovimiento $tipo, int $cantidad, ?DocumentoOrigen $doc) {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);
    $antes = rn06RegEstado($existencia->id);
    $error = null;

    try {
        rn06RegRegistrar($existencia->id, $tipo, $cantidad, $this->usuario, $tipo === TipoMovimiento::Ajuste ? 'Merma por conteo físico' : null, $doc, $doc !== null ? 1 : null);
    } catch (Throwable $e) {
        $error = $e;
    }

    expect($error)->toBeInstanceOf(ExcepcionDominio::class)
        ->and($error->codigo())->toBe('STOCK_INSUFICIENTE')
        ->and($error->status())->toBe(409)
        ->and(rn06RegEstado($existencia->id))->toBe($antes);
})->with([
    'AJUSTE −11 sobre 10' => [TipoMovimiento::Ajuste, -11, null],
    'SALIDA_DISPENSACION −11 sobre 10' => [TipoMovimiento::SalidaDispensacion, -11, DocumentoOrigen::Dispensacion],
    'SALIDA_TRASLADO −2147483647' => [TipoMovimiento::SalidaTraslado, -2147483647, DocumentoOrigen::Traslado],
]);

it('RN-06-AC2: un movimiento positivo que superaría 2.147.483.647 lanza CANTIDAD_EXCEDE_MAXIMO (no un error de BD) sin cambios', function (TipoMovimiento $tipo, ?string $motivo) {
    $existencia = Existencia::factory()->create(['cantidad' => 2_147_483_640]);
    $antes = rn06RegEstado($existencia->id);
    $error = null;

    try {
        rn06RegRegistrar($existencia->id, $tipo, 8, $this->usuario, $motivo);
    } catch (Throwable $e) {
        $error = $e;
    }

    expect($error)->toBeInstanceOf(ExcepcionDominio::class)
        ->and($error->codigo())->toBe('CANTIDAD_EXCEDE_MAXIMO')
        ->and(rn06RegEstado($existencia->id))->toBe($antes);
})->with([
    'ENTRADA' => [TipoMovimiento::Entrada, null],
    'AJUSTE' => [TipoMovimiento::Ajuste, 'Sobrante en conteo físico'],
]);

it('RN-06-AC2: con una existencia desactualizada en memoria nunca se escribe un saldo_anterior viejo (o se rechaza o usa el saldo real)', function () {
    $existencia = Existencia::factory()->create(['cantidad' => 10]);
    $vieja = Existencia::query()->findOrFail($existencia->id);       // cantidad = 10 en memoria
    DB::table('existencias')->where('id', $existencia->id)->update(['cantidad' => 12]);
    $error = null;

    try {
        DB::transaction(fn () => app(RegistradorKardex::class)->registrar(TipoMovimiento::Ajuste, $vieja, -1, $this->usuario->id, 'Ajuste con modelo desactualizado'));
    } catch (Throwable $e) {
        $error = $e;
    }

    $stock = (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
    $movimientos = DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->get();

    if ($error !== null) {
        expect($movimientos)->toHaveCount(0)->and($stock)->toBe(12);
    } else {
        expect($movimientos)->toHaveCount(1)
            ->and((int) $movimientos[0]->saldo_anterior)->toBe(12)
            ->and((int) $movimientos[0]->saldo_posterior)->toBe(11)
            ->and($stock)->toBe(11);
    }
});
