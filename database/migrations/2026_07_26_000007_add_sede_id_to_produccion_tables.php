<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // La OP pertenece a la sede que la FABRICA (una venta de Cúcuta se puede
    // producir en Bogotá). Las estaciones de trabajo pertenecen a la sede
    // donde están físicamente.
    public function up(): void
    {
        $sedePrincipal = DB::table('sedes')->where('es_principal', true)->value('id');

        foreach (['ops', 'estaciones_trabajo'] as $tabla) {
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
        foreach (['ops', 'estaciones_trabajo'] as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'sede_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('sede_id');
                });
            }
        }
    }
};
