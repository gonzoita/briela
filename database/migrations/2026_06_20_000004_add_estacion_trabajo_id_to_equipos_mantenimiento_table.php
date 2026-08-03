<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('equipos_mantenimiento') && !Schema::hasColumn('equipos_mantenimiento', 'estacion_trabajo_id')) {
            Schema::table('equipos_mantenimiento', function (Blueprint $table) {
                $table->unsignedBigInteger('estacion_trabajo_id')->nullable()->after('ubicacion');
                $table->foreign('estacion_trabajo_id')
                    ->references('id')
                    ->on('estaciones_trabajo')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('equipos_mantenimiento', 'estacion_trabajo_id')) {
            Schema::table('equipos_mantenimiento', function (Blueprint $table) {
                $table->dropForeign(['estacion_trabajo_id']);
                $table->dropColumn('estacion_trabajo_id');
            });
        }
    }
};
