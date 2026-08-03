<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('producto_movimientos')) return;

        Schema::create('producto_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas');
            $table->string('tipo'); // entrada | salida | transferencia | ajuste | consumo_ensamble | venta
            $table->decimal('cantidad', 12, 3);
            $table->decimal('stock_anterior', 12, 3);
            $table->decimal('stock_nuevo', 12, 3);
            $table->foreignId('bodega_destino_id')->nullable()->constrained('bodegas');
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->string('origen_tipo')->nullable(); // op | ensamble | ajuste_manual | compra | venta
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users');
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_movimientos');
    }
};
