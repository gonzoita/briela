<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En qué remisión salió cada unidad. Hasta ahora solo se sabía que había salido
 * (`remisionado`), y anular una remisión desmarcaba «las últimas N» unidades del ítem: si la
 * orden tenía dos remisiones, podía soltar las de la otra. Con esto se suelta exactamente lo
 * suyo, y el inventario sabe qué unidades sacar. Solo agrega una columna nula.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('op_item_trabajos') && ! Schema::hasColumn('op_item_trabajos', 'remision_id')) {
            Schema::table('op_item_trabajos', function (Blueprint $table) {
                $table->unsignedBigInteger('remision_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante: no se borran columnas de una instalación.
    }
};
