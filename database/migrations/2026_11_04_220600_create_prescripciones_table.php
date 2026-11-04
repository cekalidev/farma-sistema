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
        Schema::create('prescripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('medico_id')->constrained('users')->restrictOnDelete();
            $table->date('fecha_emision');
            // S-05: vigente si ACTIVA y hoy <= fecha_vencimiento.
            $table->date('fecha_vencimiento');
            $table->string('estado', 20)->default('ACTIVA');
            $table->text('observaciones')->nullable();
            $table->timestamps();

            // Destino de la FK compuesta dispensaciones(prescripcion_id, paciente_id) (RN-04).
            $table->unique(['id', 'paciente_id'], 'prescripciones_id_paciente_unique');
        });

        DB::statement("ALTER TABLE prescripciones ADD CONSTRAINT prescripciones_estado_check CHECK (estado IN ('ACTIVA', 'ANULADA'))");
        DB::statement('ALTER TABLE prescripciones ADD CONSTRAINT prescripciones_fechas_check CHECK (fecha_vencimiento >= fecha_emision)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescripciones');
    }
};
