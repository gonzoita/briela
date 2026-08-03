<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Última tanda de sede_id: la remisión pertenece a la sede que despacha,
    // el colaborador a la sede donde trabaja y el equipo a la sede donde está
    // físicamente. Todo lo existente queda en Bogotá.
    public function up(): void
    {
        $sedePrincipal = DB::table('sedes')->where('es_principal', true)->value('id');

        foreach (['remisiones', 'operarios', 'equipos_mantenimiento'] as $tabla) {
            if (! Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'sede_id')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('sede_id')->nullable()->after('id')->constrained('sedes')->nullOnDelete();
            });

            if ($sedePrincipal) {
                DB::table($tabla)->whereNull('sede_id')->update(['sede_id' => $sedePrincipal]);
            }
        }
    }

    public function down(): void
    {
        foreach (['remisiones', 'operarios', 'equipos_mantenimiento'] as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'sede_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('sede_id');
                });
            }
        }
    }
};
