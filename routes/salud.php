<?php

use App\Http\Controllers\SaludController;
use Illuminate\Support\Facades\Route;

// Sondas de despliegue en la raíz (sin /api/v1, sin auth, sin sesión). Sin throttle: las llama el
// orquestador cada pocos segundos y son baratas; /up (Laravel) se conserva.
Route::get('health', [SaludController::class, 'health'])->name('salud.health');
Route::get('ready', [SaludController::class, 'ready'])->name('salud.ready');
