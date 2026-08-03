<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cotizacion_items')) return;
        Schema::create('cotizacion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->enum('tipo', ['producto', 'configuracion_puerta', 'texto_libre']);
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->foreignId('configuracion_puerta_id')->nullable()->constrained('configuraciones_puerta')->nullOnDelete();
            $table->integer('orden')->default(0);
            $table->text('descripcion');
            $table->decimal('cantidad', 10, 3);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('descuento_pct', 5, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('impuesto_pct', 5, 2)->default(0);
            $table->decimal('impuesto_valor', 12, 2)->default(0);
            $table->decimal('total_linea', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_items');
    }
};
