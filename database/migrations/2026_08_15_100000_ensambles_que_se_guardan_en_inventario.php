<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un ensamble que se guarda en bodega.
 *
 * Hasta ahora un ensamble era una receta: se cotizaba, se fabricaba y salía. No había forma
 * de decir «de este tengo cuatro armados en la bodega principal», y eso es exactamente lo que
 * pasa con lo que se fabrica contra pronóstico en vez de contra pedido.
 *
 * **La decisión que evita duplicar medio sistema:** un ensamble que se guarda en bodega es un
 * **producto terminado**. En vez de inventar `ensamble_stock`, `ensamble_movimientos` y sus
 * pantallas —una copia del módulo de inventario que habría que mantener en paralelo—, el
 * ensamble obtiene su producto terminado en `productos`, y con él hereda todo lo que ya
 * existe: stock por bodega, movimientos con su historia, traslados, mínimos, el aviso diario
 * de stock bajo, los informes y la etiqueta de disponibles al cotizar.
 *
 * `productos.ensamble_id` es el vínculo, y es lo que permite distinguir un producto terminado
 * de uno comprado: no es un producto cualquiera con un nombre parecido, es **el** producto de
 * ese ensamble.
 *
 * Las dos columnas admiten nulo y nada se vuelve obligatorio: los ensambles que ya existen
 * siguen siendo recetas sin stock, que es lo que eran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ensambles', 'maneja_stock')) {
            Schema::table('ensambles', function (Blueprint $table) {
                $table->boolean('maneja_stock')->default(false)->after('unidad_medida');
            });
        }

        if (! Schema::hasColumn('productos', 'ensamble_id')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->unsignedBigInteger('ensamble_id')->nullable()->after('id');

                // Si se borra el ensamble, el producto terminado se queda: sus movimientos
                // son historia de inventario y su stock es algo que existe en una estantería.
                $table->foreign('ensamble_id')->references('id')->on('ensambles')->nullOnDelete();

                // Un ensamble tiene un solo producto terminado. Dos serían dos verdades sobre
                // cuántas unidades hay.
                $table->unique('ensamble_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('productos', 'ensamble_id')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->dropForeign(['ensamble_id']);
                $table->dropUnique(['ensamble_id']);
                $table->dropColumn('ensamble_id');
            });
        }

        if (Schema::hasColumn('ensambles', 'maneja_stock')) {
            Schema::table('ensambles', fn (Blueprint $t) => $t->dropColumn('maneja_stock'));
        }
    }
};
