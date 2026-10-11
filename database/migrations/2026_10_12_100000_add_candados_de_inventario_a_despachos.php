<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos candados contra mover el inventario dos veces. Solo agrega columnas nulas.
 *
 * - `remisiones.inventario_movido_at`: cuándo salieron del inventario los productos de esta
 *   remisión (al salir el camión). Sin él, anular y volver a despachar descontaba otra vez.
 * - `ops.inventario_consumido_at`: cuándo se consumió el material de la orden al despacharse.
 *   Una orden que se despacha, se anula su remisión y se vuelve a despachar consumía dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('remisiones') && ! Schema::hasColumn('remisiones', 'inventario_movido_at')) {
            Schema::table('remisiones', function (Blueprint $table) {
                $table->timestamp('inventario_movido_at')->nullable();
            });
        }

        if (Schema::hasTable('ops') && ! Schema::hasColumn('ops', 'inventario_consumido_at')) {
            Schema::table('ops', function (Blueprint $table) {
                $table->timestamp('inventario_consumido_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante: no se borran columnas de una instalación.
    }
};
