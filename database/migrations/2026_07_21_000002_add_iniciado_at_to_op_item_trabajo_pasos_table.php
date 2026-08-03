<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hasta ahora solo se guardaba completado_at (fin). No había forma de saber
// cuándo arrancó realmente un paso — el "tiempo_minutos" era un número que
// alguien escribía a mano. Con iniciado_at se puede marcar el inicio con un
// botón y las dos fechas quedan solas, sin que nadie tenga que escribirlas.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('op_item_trabajo_pasos', 'iniciado_at')) return;

        Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
            $table->timestamp('iniciado_at')->nullable()->after('completado_at');
        });
    }

    public function down(): void
    {
        Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
            $table->dropColumn('iniciado_at');
        });
    }
};
