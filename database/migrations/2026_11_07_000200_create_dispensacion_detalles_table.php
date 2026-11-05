<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RN-02: tramo FEFO de un ítem (un lote por fila). Solo existe cuando el stock se descontó
 * (estado CONFIRMADA); cada detalle referencia su movimiento SALIDA_DISPENSACION del kardex.
 *
 * Las FK compuestas garantizan que la existencia es de la bodega de la dispensación y del producto
 * del ítem, y que lote/producto/bodega copiados coinciden con la existencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensacion_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dispensacion_id');
            $table->unsignedBigInteger('dispensacion_item_id');
            $table->unsignedBigInteger('existencia_id');
            $table->unsignedBigInteger('bodega_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('lote_id');
            $table->foreignId('movimiento_kardex_id')->unique()->constrained('movimientos_kardex')->restrictOnDelete();
            $table->integer('cantidad');
            $table->timestamps();

            $table->foreign(['dispensacion_item_id', 'dispensacion_id', 'producto_id'], 'dispensacion_detalles_item_foreign')
                ->references(['id', 'dispensacion_id', 'producto_id'])->on('dispensacion_items')
                ->restrictOnDelete();
            $table->foreign(['dispensacion_id', 'bodega_id'], 'dispensacion_detalles_dispensacion_bodega_foreign')
                ->references(['id', 'bodega_id'])->on('dispensaciones')
                ->restrictOnDelete();
            $table->foreign(['existencia_id', 'bodega_id', 'producto_id', 'lote_id'], 'dispensacion_detalles_existencia_foreign')
                ->references(['id', 'bodega_id', 'producto_id', 'lote_id'])->on('existencias')
                ->restrictOnDelete();

            // Un tramo por lote e ítem.
            $table->unique(['dispensacion_item_id', 'lote_id'], 'dispensacion_detalles_item_lote_unique');
            $table->index('dispensacion_id');
            $table->index('lote_id');
            $table->index('existencia_id');
        });

        // RN-03: CHECK (cantidad > 0) en detalles.
        DB::statement('ALTER TABLE dispensacion_detalles ADD CONSTRAINT dispensacion_detalles_cantidad_check CHECK (cantidad > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensacion_detalles');
    }
};
