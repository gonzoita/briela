<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->text('observaciones_calidad')->nullable()->after('notas_internas');
            $table->text('motivo_rechazo')->nullable()->after('observaciones_calidad');
        });

        // Agrega 'reproceso' al enum de estado — necesario para poder devolver
        // una OP a producción cuando el control de calidad la rechaza.
        DB::statement("ALTER TABLE ops MODIFY estado ENUM('borrador','confirmada','en_produccion','calidad','reproceso','despachada') NOT NULL DEFAULT 'borrador'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE ops MODIFY estado ENUM('borrador','confirmada','en_produccion','calidad','despachada') NOT NULL DEFAULT 'borrador'");

        Schema::table('ops', function (Blueprint $table) {
            $table->dropColumn(['observaciones_calidad', 'motivo_rechazo']);
        });
    }
};
