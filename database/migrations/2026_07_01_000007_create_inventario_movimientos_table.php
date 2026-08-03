<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->enum('tipo', ['entrada', 'salida', 'ajuste', 'devolucion']);
            $table->decimal('cantidad', 12, 3);
            $table->decimal('stock_anterior', 12, 3);
            $table->decimal('stock_nuevo', 12, 3);
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->string('origen_tipo')->nullable();
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('inventario_items');
            $table->foreign('usuario_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_movimientos');
    }
};
