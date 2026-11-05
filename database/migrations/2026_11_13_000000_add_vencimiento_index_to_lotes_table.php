<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RN-11 GET /alertas/vencimientos: rango `lotes.fecha_vencimiento <= hoy + umbral` sin producto,
 * ordenado por (fecha_vencimiento, id). lotes_fefo_index empieza por producto_id y no sirve.
 * No se añade un índice parcial sobre existencias (cantidad > 0): cada UPDATE de la cantidad
 * dejaría de ser HOT y encarecería toda salida de stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->index(['fecha_vencimiento', 'id'], 'lotes_vencimiento_index');
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropIndex('lotes_vencimiento_index');
        });
    }
};
