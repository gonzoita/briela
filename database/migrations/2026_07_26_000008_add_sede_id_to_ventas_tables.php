<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ventas por sede: el cliente, la cotización y el lead pertenecen a la
    // sede de VENTA que los originó. Todo lo existente queda en Bogotá.
    //
    // Nota: a partir de aquí cada sede ve solo sus propios clientes. Los
    // usuarios de Cali y Cúcuta arrancan con la lista vacía hasta que se les
    // reasignen clientes desde Bogotá.
    public function up(): void
    {
        $sedePrincipal = DB::table('sedes')->where('es_principal', true)->value('id');

        foreach (['clientes', 'cotizaciones', 'crm_leads'] as $tabla) {
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
        foreach (['clientes', 'cotizaciones', 'crm_leads'] as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'sede_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('sede_id');
                });
            }
        }
    }
};
