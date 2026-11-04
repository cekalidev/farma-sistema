<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RN-01-AC9: GET /api/v1/inventario?producto_id= sin bodega. bodega_id ya está cubierto por el
 * prefijo del índice único (bodega_id, producto_id, lote_id) y lote_id por su propio índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('existencias', function (Blueprint $table) {
            $table->index(['producto_id', 'lote_id'], 'existencias_producto_lote_index');
        });
    }

    public function down(): void
    {
        Schema::table('existencias', function (Blueprint $table) {
            $table->dropIndex('existencias_producto_lote_index');
        });
    }
};
