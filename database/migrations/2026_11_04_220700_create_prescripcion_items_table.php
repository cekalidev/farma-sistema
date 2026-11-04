<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescripcion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescripcion_id')->constrained('prescripciones')->restrictOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            // S-07: unidades mínimas de dispensación, enteras.
            $table->integer('cantidad');
            $table->text('indicaciones')->nullable();
            $table->timestamps();

            // Un producto aparece una sola vez por prescripción (el tope de RN-04 es por ítem).
            $table->unique(['prescripcion_id', 'producto_id'], 'prescripcion_items_prescripcion_producto_unique');
        });

        // RN-03 / S-07: cantidad prescrita entera y positiva.
        DB::statement('ALTER TABLE prescripcion_items ADD CONSTRAINT prescripcion_items_cantidad_check CHECK (cantidad > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescripcion_items');
    }
};
