<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('op_item_trabajo_pasos')) {
            Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
                if (!Schema::hasColumn('op_item_trabajo_pasos', 'estacion_trabajo_id')) {
                    $table->unsignedBigInteger('estacion_trabajo_id')->nullable()->after('orden');
                    $table->foreign('estacion_trabajo_id')->references('id')->on('estaciones_trabajo')->onDelete('set null');
                }
                if (!Schema::hasColumn('op_item_trabajo_pasos', 'fecha_programada')) {
                    $table->date('fecha_programada')->nullable()->after('estacion_trabajo_id');
                }
                if (!Schema::hasColumn('op_item_trabajo_pasos', 'colaborador_programado_id')) {
                    $table->unsignedBigInteger('colaborador_programado_id')->nullable()->after('fecha_programada');
                    $table->foreign('colaborador_programado_id')->references('id')->on('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('op_item_trabajo_pasos', 'tiempo_estimado_minutos')) {
                    $table->integer('tiempo_estimado_minutos')->nullable()->after('colaborador_programado_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('op_item_trabajo_pasos')) {
            Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
                $table->dropForeign(['estacion_trabajo_id']);
                $table->dropForeign(['colaborador_programado_id']);
                $table->dropColumn(['estacion_trabajo_id', 'fecha_programada', 'colaborador_programado_id', 'tiempo_estimado_minutos']);
            });
        }
    }
};
