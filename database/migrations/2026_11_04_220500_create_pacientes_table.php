<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** RN-10: todas las columnas personales son sensibles (no van a logs). */
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_documento', 2);
            $table->string('numero_documento', 20);
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->date('fecha_nacimiento');
            $table->string('telefono', 20)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->string('correo', 150)->nullable();
            $table->timestamps();

            $table->unique(['tipo_documento', 'numero_documento'], 'pacientes_documento_unique');
        });

        DB::statement("ALTER TABLE pacientes ADD CONSTRAINT pacientes_tipo_documento_check CHECK (tipo_documento IN ('CC', 'TI', 'CE', 'PA', 'RC'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};
