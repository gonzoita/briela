<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los pasos de producción de un ensamble **directo**.
 *
 * Hasta ahora el flujo de trabajo colgaba de la plantilla del cotizador: `templates_trabajo`
 * apunta a `plantillas_ensamble`, y `TrabajoAutoGeneratorService` se devolvía sin hacer nada
 * cuando el ensamble no tenía plantilla. Con los ensambles directos eso deja de ser un caso
 * imposible: sin esta columna, una OP con un ensamble directo nace con cero trabajos, el
 * avance nunca sube y la orden se queda quieta en `confirmada` sin que nada explique por qué.
 *
 * `ensamble_id` deja que el flujo cuelgue del ensamble mismo. Las dos columnas conviven y
 * ambas admiten nulo: un template pertenece a una plantilla, o a un ensamble directo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('templates_trabajo', 'ensamble_id')) {
            return;
        }

        Schema::table('templates_trabajo', function (Blueprint $table) {
            $table->unsignedBigInteger('ensamble_id')->nullable()->after('plantilla_ensamble_id');

            // Si se borra el ensamble, el template queda huérfano en vez de desaparecer: los
            // trabajos ya hechos lo referencian y son historia de producción.
            $table->foreign('ensamble_id')->references('id')->on('ensambles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('templates_trabajo', 'ensamble_id')) {
            return;
        }

        Schema::table('templates_trabajo', function (Blueprint $table) {
            $table->dropForeign(['ensamble_id']);
            $table->dropColumn('ensamble_id');
        });
    }
};
