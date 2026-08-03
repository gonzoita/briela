<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantilla_componentes', function (Blueprint $table) {
            if (!Schema::hasColumn('plantilla_componentes', 'incluir_en_precio')) {
                $table->boolean('incluir_en_precio')->default(true)->after('unidad');
            }
            if (!Schema::hasColumn('plantilla_componentes', 'visible_cliente')) {
                $table->boolean('visible_cliente')->default(false)->after('incluir_en_precio');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_componentes', function (Blueprint $table) {
            $table->dropColumn(['incluir_en_precio', 'visible_cliente']);
        });
    }
};
