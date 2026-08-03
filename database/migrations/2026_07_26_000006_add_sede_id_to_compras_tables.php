<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Las compras pasan a pertenecer a una sede. Todo lo que ya existe queda
    // en la sede principal (Bogotá).
    public function up(): void
    {
        $sedePrincipal = DB::table('sedes')->where('es_principal', true)->value('id');

        foreach (['solicitudes_compra', 'ordenes_compra'] as $tabla) {
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
        foreach (['solicitudes_compra', 'ordenes_compra'] as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'sede_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('sede_id');
                });
            }
        }
    }
};
