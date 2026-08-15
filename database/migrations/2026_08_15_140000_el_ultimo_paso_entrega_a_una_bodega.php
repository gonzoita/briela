<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se fabrica entra a una bodega, y el último paso dice a cuál.
 *
 * Hasta ahora un trabajo terminaba y ahí se quedaba: la unidad existía en el mundo real —
 * armada, aprobada, en una estantería — y en el sistema no existía en ninguna parte. El
 * material recién se descontaba al despachar, así que entre fabricar y despachar el inventario
 * mostraba insumos que ya no estaban y no mostraba el producto que sí estaba.
 *
 * Ahora **toda producción entra a bodega**. El último paso del flujo declara a cuál, porque es
 * el paso de entrega: quien lo cierra es quien físicamente deja la unidad en el estante. Al
 * cerrarlo, el sistema descuenta los materiales de esa unidad y registra su entrada.
 *
 * `bodega_destino_id` va en los dos lados a propósito:
 *
 * - En **la plantilla del flujo**: es la bodega de siempre para lo que sale de esa línea, y se
 *   define una vez.
 * - En **el paso real de la OP**: se copia de la plantilla y se puede cambiar en esa orden
 *   concreta, porque un lote puede ir a otra bodega sin que eso cambie la regla general.
 *
 * `entregado_at` en el trabajo es el candado: sin él, volver a marcar el último paso —o dos
 * personas marcándolo a la vez— metería la misma unidad dos veces al inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('template_trabajo_pasos', 'bodega_destino_id')) {
            Schema::table('template_trabajo_pasos', function (Blueprint $table) {
                $table->unsignedBigInteger('bodega_destino_id')->nullable()->after('es_paso_final');
                $table->foreign('bodega_destino_id')->references('id')->on('bodegas')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('op_item_trabajo_pasos', 'bodega_destino_id')) {
            Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
                $table->unsignedBigInteger('bodega_destino_id')->nullable()->after('es_paso_final');
                $table->foreign('bodega_destino_id')->references('id')->on('bodegas')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('op_item_trabajos', 'entregado_at')) {
            Schema::table('op_item_trabajos', function (Blueprint $table) {
                $table->timestamp('entregado_at')->nullable()->after('remisionado');

                // A qué bodega entró, que puede no ser la de la plantilla si se cambió en la OP.
                $table->unsignedBigInteger('bodega_entrega_id')->nullable()->after('entregado_at');
                $table->foreign('bodega_entrega_id')->references('id')->on('bodegas')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['template_trabajo_pasos', 'op_item_trabajo_pasos'] as $tabla) {
            if (Schema::hasColumn($tabla, 'bodega_destino_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropForeign(['bodega_destino_id']);
                    $table->dropColumn('bodega_destino_id');
                });
            }
        }

        if (Schema::hasColumn('op_item_trabajos', 'entregado_at')) {
            Schema::table('op_item_trabajos', function (Blueprint $table) {
                $table->dropForeign(['bodega_entrega_id']);
                $table->dropColumn(['entregado_at', 'bodega_entrega_id']);
            });
        }
    }
};
