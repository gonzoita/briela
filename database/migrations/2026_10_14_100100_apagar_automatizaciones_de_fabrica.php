<?php

use App\Support\Modulos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Automatizaciones nace APAGADO en toda instalación.
 *
 * Una automatización actúa sin que nadie esté mirando: crea tareas, avisa y puede lanzar agentes.
 * Una actualización que la encendiera a todos haría que cada empresa descubriera comportamiento
 * nuevo sin haberlo pedido. Se prende en Configuración → Módulos cuando se quiere.
 *
 * Se agrega a los apagados sin tocar los que ya hubiera; correrla dos veces no cambia nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('configuraciones')) {
            return;
        }

        $apagados = Modulos::estado()['apagados'];

        if (! in_array('automatizaciones', $apagados, true)) {
            Modulos::guardar([...$apagados, 'automatizaciones'], 'instalacion');
        }
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }
};
