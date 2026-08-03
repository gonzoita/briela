<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_producto')->nullOnDelete();

            $table->enum('tipo', ['producto', 'servicio', 'ensamble']);
            $table->string('nombre', 200);
            $table->string('referencia', 60)->unique();
            $table->string('unidad_medida', 30)->default('unidad');
            $table->string('descripcion_corta', 160)->nullable();
            $table->text('descripcion_larga')->nullable();

            // Inventario (solo tipo=producto)
            $table->boolean('inventariable')->default(false);
            $table->integer('stock_minimo')->default(0);
            $table->integer('stock_maximo')->default(0);
            $table->integer('stock_almacen1')->default(0);
            $table->integer('stock_almacen2')->default(0);
            $table->integer('stock_almacen3')->default(0);
            $table->integer('stock_almacen4')->default(0);

            // Lista de precios
            $table->decimal('precio_costo', 14, 2)->default(0);
            $table->decimal('precio_mayorista', 14, 2)->default(0);
            $table->decimal('precio_distribuidor', 14, 2)->default(0);
            $table->decimal('precio_cliente_final', 14, 2)->default(0);

            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
