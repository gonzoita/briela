<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué bodega entra lo que fabrica esta OP.
 *
 * Antes la bodega se pedía en el último paso de cada unidad, en la pantalla del operario, y se
 * podía predefinir en la plantilla del ensamble. Los dos sitios estaban mal: es una decisión de
 * quien planea la producción, no de quien la arma, y el ensamble es un catálogo —lo mismo se
 * fabrica hoy para una bodega y mañana para otra—.
 *
 * Nullable a propósito: las OPs que ya existen no tienen bodega declarada, y ponerles una a la
 * fuerza sería inventar dónde quedó algo que ya se fabricó. Para ellas sigue valiendo el
 * respaldo de siempre. Se vuelve obligatoria al **confirmar** una OP nueva, que es cuando la
 * decisión ya se puede tomar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->unsignedBigInteger('bodega_entrega_id')->nullable()->after('responsable_id');
            $table->foreign('bodega_entrega_id')->references('id')->on('bodegas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->dropForeign(['bodega_entrega_id']);
            $table->dropColumn('bodega_entrega_id');
        });
    }
};
