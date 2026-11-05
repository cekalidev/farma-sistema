<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RN-08-AC5: segregación de funciones en la BD; quien solicita un traslado no puede aprobarlo.
 * Junto con traslados_solicitante_creador_check (S-08), un UPDATE directo tampoco puede dejar al
 * creador como aprobador. Resolver discrepancias (S-71) no tiene CHECK: compara
 * discrepancias.resuelto_por_id con traslados.solicitado_por_id, que están en otra tabla (lo
 * defienden la Policy y ResolucionDiscrepancia).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE traslados ADD CONSTRAINT traslados_aprobador_solicitante_check'
            .' CHECK (aprobado_por_id IS NULL OR aprobado_por_id <> solicitado_por_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE traslados DROP CONSTRAINT IF EXISTS traslados_aprobador_solicitante_check');
    }
};
