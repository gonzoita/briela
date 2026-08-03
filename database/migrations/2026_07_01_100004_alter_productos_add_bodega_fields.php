<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            if (!Schema::hasColumn('productos', 'es_vendible')) {
                $table->boolean('es_vendible')->default(true)->after('tipo');
            }
            if (!Schema::hasColumn('productos', 'es_insumo')) {
                $table->boolean('es_insumo')->default(false)->after('es_vendible');
            }
            if (!Schema::hasColumn('productos', 'proveedor_id')) {
                $table->foreignId('proveedor_id')->nullable()->after('categoria_id')
                    ->constrained('proveedores')->nullOnDelete();
            }
            if (!Schema::hasColumn('productos', 'precio_promedio_compra')) {
                $table->decimal('precio_promedio_compra', 12, 2)->nullable()->after('precio_costo');
            }
            if (!Schema::hasColumn('productos', 'precio_ultimo_compra')) {
                $table->decimal('precio_ultimo_compra', 12, 2)->nullable()->after('precio_promedio_compra');
            }
        });

        Schema::table('productos', function (Blueprint $table) {
            if (Schema::hasColumn('productos', 'stock_almacen1')) {
                $table->dropColumn('stock_almacen1');
            }
            if (Schema::hasColumn('productos', 'stock_almacen2')) {
                $table->dropColumn('stock_almacen2');
            }
            if (Schema::hasColumn('productos', 'stock_almacen3')) {
                $table->dropColumn('stock_almacen3');
            }
            if (Schema::hasColumn('productos', 'stock_almacen4')) {
                $table->dropColumn('stock_almacen4');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            if (Schema::hasColumn('productos', 'es_vendible'))           $table->dropColumn('es_vendible');
            if (Schema::hasColumn('productos', 'es_insumo'))             $table->dropColumn('es_insumo');
            if (Schema::hasColumn('productos', 'precio_promedio_compra')) $table->dropColumn('precio_promedio_compra');
            if (Schema::hasColumn('productos', 'precio_ultimo_compra'))   $table->dropColumn('precio_ultimo_compra');
        });

        Schema::table('productos', function (Blueprint $table) {
            if (Schema::hasColumn('productos', 'proveedor_id')) {
                $table->dropForeign(['proveedor_id']);
                $table->dropColumn('proveedor_id');
            }
        });

        Schema::table('productos', function (Blueprint $table) {
            if (!Schema::hasColumn('productos', 'stock_almacen1')) $table->integer('stock_almacen1')->default(0);
            if (!Schema::hasColumn('productos', 'stock_almacen2')) $table->integer('stock_almacen2')->default(0);
            if (!Schema::hasColumn('productos', 'stock_almacen3')) $table->integer('stock_almacen3')->default(0);
            if (!Schema::hasColumn('productos', 'stock_almacen4')) $table->integer('stock_almacen4')->default(0);
        });
    }
};
