<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_compra_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('orden_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 12, 3);
            $table->decimal('cantidad_recibida', 12, 3)->default(0);
            $table->string('unidad');
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('impuesto_pct', 5, 2)->default(0);
            $table->decimal('total_linea', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('orden_id')->references('id')->on('ordenes_compra')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inventario_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_compra_items');
    }
};
