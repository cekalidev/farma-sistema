<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BodegaController;
use App\Http\Controllers\Api\V1\DiscrepanciaController;
use App\Http\Controllers\Api\V1\DispensacionController;
use App\Http\Controllers\Api\V1\InventarioController;
use App\Http\Controllers\Api\V1\KardexController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\TrasladoController;
use App\Models\Bodega;
use App\Models\Dispensacion;
use App\Models\Existencia;
use App\Models\MovimientoKardex;
use App\Models\Producto;
use App\Models\Traslado;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    // Autenticadas: límite general por usuario (throttle:api, config farma.api_peticiones_por_minuto).
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        // Catálogos: lectura con bodega.consultar / producto.consultar (S-18); alta solo admin (catalogo.gestionar).
        Route::get('bodegas', [BodegaController::class, 'index'])->can('viewAny', Bodega::class)->name('bodegas.index');
        Route::post('bodegas', [BodegaController::class, 'store'])->can('create', Bodega::class)->name('bodegas.store');
        Route::get('bodegas/{bodega}', [BodegaController::class, 'show'])->can('view', 'bodega')->name('bodegas.show');

        Route::get('productos', [ProductoController::class, 'index'])->can('viewAny', Producto::class)->name('productos.index');
        Route::post('productos', [ProductoController::class, 'store'])->can('create', Producto::class)->name('productos.store');
        Route::get('productos/{producto}', [ProductoController::class, 'show'])->can('view', 'producto')->name('productos.show');

        // RN-01 inventario: consulta con inventario.consultar; entradas con inventario.registrar (S-17).
        Route::get('inventario', [InventarioController::class, 'index'])->can('viewAny', Existencia::class)->name('inventario.index');
        Route::post('inventario/entradas', [InventarioController::class, 'storeEntrada'])->can('registrarEntrada', Existencia::class)->name('inventario.entradas.store');

        // RN-06 kardex: ajustes con inventario.registrar (regente); consulta con inventario.consultar.
        // Sin rutas PUT/PATCH/DELETE: el router responde 405 METODO_NO_PERMITIDO (RN-06-AC5).
        Route::post('inventario/ajustes', [InventarioController::class, 'storeAjuste'])->can('registrarAjuste', Existencia::class)->name('inventario.ajustes.store');
        Route::get('kardex', [KardexController::class, 'index'])->can('viewAny', MovimientoKardex::class)->name('kardex.index');
        Route::get('kardex/{movimiento}', [KardexController::class, 'show'])->can('view', 'movimiento')->name('kardex.show');

        // RN-02 dispensación FEFO: dispensacion.crear (auxiliar, regente). Previsualizar no cambia stock ni kardex.
        Route::post('dispensaciones/previsualizar', [DispensacionController::class, 'previsualizar'])->can('create', Dispensacion::class)->name('dispensaciones.previsualizar');
        Route::post('dispensaciones', [DispensacionController::class, 'store'])->can('create', Dispensacion::class)->name('dispensaciones.store');

        // RN-05 control especial: dispensacion.autorizar (regente) y distinto de quien la creó (Policy + servicio).
        Route::post('dispensaciones/{dispensacion}/autorizar', [DispensacionController::class, 'autorizar'])->can('autorizar', 'dispensacion')->name('dispensaciones.autorizar');
        Route::post('dispensaciones/{dispensacion}/rechazar', [DispensacionController::class, 'rechazar'])->can('rechazar', 'dispensacion')->name('dispensaciones.rechazar');

        // RN-07 traslados: consultar (auxiliar, regente, auditor); crear/despachar/recibir con
        // traslado.operar; editar ítems y solicitar solo el creador (S-08); aprobar con
        // traslado.aprobar y no ser el solicitante (RN-08); anular el creador o un regente.
        Route::get('traslados', [TrasladoController::class, 'index'])->can('viewAny', Traslado::class)->name('traslados.index');
        Route::post('traslados', [TrasladoController::class, 'store'])->can('create', Traslado::class)->name('traslados.store');
        Route::get('traslados/{traslado}', [TrasladoController::class, 'show'])->can('view', 'traslado')->name('traslados.show');
        Route::put('traslados/{traslado}/items', [TrasladoController::class, 'updateItems'])->can('editarItems', 'traslado')->name('traslados.items.update');
        Route::post('traslados/{traslado}/solicitar', [TrasladoController::class, 'solicitar'])->can('solicitar', 'traslado')->name('traslados.solicitar');
        Route::post('traslados/{traslado}/aprobar', [TrasladoController::class, 'aprobar'])->can('aprobar', 'traslado')->name('traslados.aprobar');
        Route::post('traslados/{traslado}/despachar', [TrasladoController::class, 'despachar'])->can('despachar', 'traslado')->name('traslados.despachar');
        Route::post('traslados/{traslado}/recibir', [TrasladoController::class, 'recibir'])->can('recibir', 'traslado')->name('traslados.recibir');
        Route::post('traslados/{traslado}/anular', [TrasladoController::class, 'anular'])->can('anular', 'traslado')->name('traslados.anular');

        // RN-07-AC12: resolver discrepancias con traslado.aprobar (regente) que no solicitó el traslado (S-71).
        Route::post('discrepancias/{discrepancia}/resolver', [DiscrepanciaController::class, 'resolver'])->can('resolver', 'discrepancia')->name('discrepancias.resolver');
    });
});
