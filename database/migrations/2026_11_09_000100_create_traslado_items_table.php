<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RN-07 / S-12: ítem de traslado con lote explícito (no FEFO); el destino recibe el mismo lote.
 * `cantidad` es lo despachado (no cambia tras el BORRADOR) y `cantidad_recibida` se fija al recibir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traslado_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traslado_id')->constrained('traslados')->restrictOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->unsignedBigInteger('lote_id');
            $table->integer('cantidad');
            $table->integer('cantidad_recibida')->nullable();
            $table->timestamps();

            // RN-07-AC9: el lote pertenece al producto del ítem.
            $table->foreign(['lote_id', 'producto_id'], 'traslado_items_lote_producto_foreign')
                ->references(['id', 'producto_id'])->on('lotes')
                ->restrictOnDelete();
            // Un lote aparece una sola vez por traslado.
            $table->unique(['traslado_id', 'lote_id'], 'traslado_items_traslado_lote_unique');
            $table->index('lote_id');
            $table->index('producto_id');
        });

        DB::statement('ALTER TABLE traslado_items ADD CONSTRAINT traslado_items_cantidad_check CHECK (cantidad > 0)');
        DB::statement('ALTER TABLE traslado_items ADD CONSTRAINT traslado_items_cantidad_recibida_check'
            .' CHECK (cantidad_recibida IS NULL OR cantidad_recibida BETWEEN 0 AND cantidad)');
    }

    public function down(): void
    {
        Schema::dropIfExists('traslado_items');
    }
};
