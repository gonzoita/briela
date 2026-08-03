<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Compras (solicitudes y órdenes) apuntaba con item_id a la tabla
// inventario_items — un sistema de stock paralelo que producción nunca
// usó. El inventario real es "productos" (es_insumo=true), con stock por
// bodega. Como el módulo de Compras no se había usado todavía (sin datos),
// se reapunta la FK item_id de inventario_items a productos, sin migrar
// nada. Ver docs/manual/compras-inventario.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_compra_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->foreign('item_id')->references('id')->on('productos')->nullOnDelete();
        });

        Schema::table('ordenes_compra_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->foreign('item_id')->references('id')->on('productos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_compra_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->foreign('item_id')->references('id')->on('inventario_items')->nullOnDelete();
        });

        Schema::table('ordenes_compra_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->foreign('item_id')->references('id')->on('inventario_items')->nullOnDelete();
        });
    }
};
