<?php

use App\Support\Modulos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * El Equipo de IA nace APAGADO en toda instalación.
 *
 * Los agentes internos corren solos y gastan IA con un cupo propio. Una actualización que los
 * encendiera a todos haría que cada cliente descubriera un gasto nuevo sin haberlo pedido. Se
 * prenden desde Configuración → Módulos (o desde la ficha en el superadmin) cuando la empresa
 * los quiere.
 *
 * Se agrega a la lista de apagados SIN tocar los que ya hubiera. Si ya estaba apagado, no hace
 * nada: correr esta migración dos veces no cambia el resultado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('configuraciones')) {
            return;
        }

        $apagados = Modulos::estado()['apagados'];

        if (in_array('equipo_ia', $apagados, true)) {
            return;
        }

        Modulos::guardar([...$apagados, 'equipo_ia'], 'instalacion');
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }
};
