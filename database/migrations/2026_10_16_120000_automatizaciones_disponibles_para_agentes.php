<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una automatización puede ofrecerse a los agentes del Equipo de IA.
 *
 * Un agente que solo tiene lectura y propuestas gasta tokens en cada paso mecánico. Una
 * automatización es el camino contrario: pasos fijos, sin modelo, sin costo de IA. Con esta marca
 * el agente decide CUÁNDO y SOBRE QUÉ documento, y la automatización hace el trabajo. La marca la
 * pone una persona con permiso para gestionar el Equipo de IA; nace apagada.
 *
 * Aditiva y nulable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automatizaciones') && ! Schema::hasColumn('automatizaciones', 'disponible_agentes')) {
            Schema::table('automatizaciones', function (Blueprint $table) {
                $table->boolean('disponible_agentes')->default(false)->after('activa');
            });
        }

        // La corrida del agente que la lanzó: para el tope por corrida y para auditar quién la pidió.
        if (Schema::hasTable('automatizacion_ejecuciones') && ! Schema::hasColumn('automatizacion_ejecuciones', 'agente_ejecucion_id')) {
            Schema::table('automatizacion_ejecuciones', function (Blueprint $table) {
                $table->unsignedBigInteger('agente_ejecucion_id')->nullable()->after('disparado_por')->index();
            });
        }
    }

    public function down(): void
    {
        // A propósito vacío: son datos de un cliente al que no tenemos acceso.
    }
};
