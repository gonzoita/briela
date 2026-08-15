<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Originalmente esta migración también agregaba `cotizacion_id` a
// `ordenes_produccion`, la tabla del sistema viejo de producción. Esa parte se
// quitó al arrancar Briela, junto con las tablas muertas que heredó del sistema de origen.
// El nombre del archivo se conserva para no alterar el orden del historial de
// migraciones ni el registro de instalaciones existentes.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cotizaciones', 'en_produccion')) {
            Schema::table('cotizaciones', function (Blueprint $table) {
                $table->boolean('en_produccion')->default(false)->after('estado');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cotizaciones', 'en_produccion')) {
            Schema::table('cotizaciones', function (Blueprint $table) {
                $table->dropColumn('en_produccion');
            });
        }
    }
};
