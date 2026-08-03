<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $table) {
            // Snapshot del precio mayorista del producto/ensamble al momento de
            // agregar el ítem — es la base de utilidad garantizada de la
            // empresa. La comisión del vendedor se calcula sobre el excedente
            // (precio_unitario - precio_mayorista_base), no sobre el precio
            // completo. Se guarda como snapshot (no se recalcula desde el
            // producto) para que cotizaciones viejas no cambien de valor si el
            // precio mayorista del producto se actualiza después.
            $table->decimal('precio_mayorista_base', 14, 2)->default(0)->after('precio_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->dropColumn('precio_mayorista_base');
        });
    }
};
