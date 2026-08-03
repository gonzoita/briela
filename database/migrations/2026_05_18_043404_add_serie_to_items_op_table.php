<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items_op', function (Blueprint $table) {
            $table->string('numero_serie')->unique()->nullable()->after('tipo');
            $table->enum('estado_serie', [
                'en_produccion',
                'calidad_aprobada',
                'empacado',
                'despachado',
                'instalado',
            ])->default('en_produccion')->after('numero_serie');
        });
    }

    public function down(): void
    {
        Schema::table('items_op', function (Blueprint $table) {
            $table->dropUnique(['numero_serie']);
            $table->dropColumn(['numero_serie', 'estado_serie']);
        });
    }
};
