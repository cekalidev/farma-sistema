<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->string('codigo', 50);
            // RN-01-AC2: todo lote tiene fecha de vencimiento.
            $table->date('fecha_vencimiento');
            $table->timestamps();

            // RN-01-AC2: código único por producto.
            $table->unique(['producto_id', 'codigo'], 'lotes_producto_codigo_unique');
            // Destino de la FK compuesta existencias(lote_id, producto_id) (RN-01-AC3).
            $table->unique(['id', 'producto_id'], 'lotes_id_producto_unique');
            // FEFO (RN-02): orden por fecha_vencimiento, id.
            $table->index(['producto_id', 'fecha_vencimiento', 'id'], 'lotes_fefo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
