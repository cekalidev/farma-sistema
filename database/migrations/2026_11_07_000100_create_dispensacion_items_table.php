<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cantidad solicitada por ítem de prescripción. Existe aunque la dispensación no haya movido stock
 * (PENDIENTE_AUTORIZACION, RN-05 / S-06): es la base del pendiente acumulado de RN-04 (AC5, AC9).
 *
 * producto_id se copia del ítem prescrito (RN-04-AC11: el cliente no elige producto); las FK
 * compuestas impiden que diverja del ítem y que el ítem sea de otra prescripción.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE prescripcion_items ADD CONSTRAINT prescripcion_items_id_prescripcion_producto_unique UNIQUE (id, prescripcion_id, producto_id)');

        Schema::create('dispensacion_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dispensacion_id');
            $table->unsignedBigInteger('prescripcion_id');
            $table->unsignedBigInteger('prescripcion_item_id');
            $table->unsignedBigInteger('producto_id');
            // S-07: unidades mínimas de dispensación, enteras y positivas.
            $table->integer('cantidad');
            $table->timestamps();

            $table->foreign(['dispensacion_id', 'prescripcion_id'], 'dispensacion_items_dispensacion_foreign')
                ->references(['id', 'prescripcion_id'])->on('dispensaciones')
                ->restrictOnDelete();
            $table->foreign(['prescripcion_item_id', 'prescripcion_id', 'producto_id'], 'dispensacion_items_prescripcion_item_foreign')
                ->references(['id', 'prescripcion_id', 'producto_id'])->on('prescripcion_items')
                ->restrictOnDelete();

            // Un ítem prescrito una sola vez por dispensación.
            $table->unique(['dispensacion_id', 'prescripcion_item_id'], 'dispensacion_items_dispensacion_item_unique');
            // Destino de la FK compuesta de dispensacion_detalles.
            $table->unique(['id', 'dispensacion_id', 'producto_id'], 'dispensacion_items_id_dispensacion_producto_unique');
            // RN-04: suma de lo dispensado por ítem prescrito.
            $table->index('prescripcion_item_id');
        });

        // RN-03 / S-07.
        DB::statement('ALTER TABLE dispensacion_items ADD CONSTRAINT dispensacion_items_cantidad_check CHECK (cantidad > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensacion_items');
        DB::statement('ALTER TABLE prescripcion_items DROP CONSTRAINT IF EXISTS prescripcion_items_id_prescripcion_producto_unique');
    }
};
