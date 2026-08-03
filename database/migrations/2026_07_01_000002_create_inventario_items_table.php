<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_items', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->enum('tipo', ['materia_prima', 'insumo', 'consumible', 'herramienta'])->default('insumo');
            $table->string('unidad');
            $table->decimal('stock_actual', 12, 3)->default(0);
            $table->decimal('stock_minimo', 12, 3)->default(0);
            $table->decimal('stock_maximo', 12, 3)->nullable();
            $table->decimal('precio_promedio', 12, 2)->default(0);
            $table->decimal('precio_ultimo', 12, 2)->default(0);
            $table->unsignedBigInteger('proveedor_id')->nullable();
            $table->string('ubicacion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->foreign('proveedor_id')->references('id')->on('proveedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_items');
    }
};
