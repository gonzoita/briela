<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $table) {
            if (! Schema::hasColumn('cotizacion_items', 'variables_instancia')) {
                $table->json('variables_instancia')->nullable()->after('componentes_snapshot');
            }
            if (! Schema::hasColumn('cotizacion_items', 'descripcion_larga')) {
                $table->text('descripcion_larga')->nullable()->after('descripcion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->dropColumn(['variables_instancia', 'descripcion_larga']);
        });
    }
};
