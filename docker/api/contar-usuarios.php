<?php

// Imprime el número de filas de `users` (lo usa el entrypoint para decidir la semilla demo).
// Se ejecuta tras las migraciones, así que la tabla existe.

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo DB::table('users')->count();
