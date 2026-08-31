<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo calidad dio por buena ESTA unidad, y quién.
 *
 * El candado del despacho pasó a ser por unidad, y se estaba deduciendo de los puntos de
 * revisión: «no le queda ninguno pendiente». Eso deja fuera a **las unidades sin lista de
 * revisión**, que en una instalación real son la mayoría —la lista se carga plantilla por
 * plantilla, y casi ninguna la tiene todavía—. Esas unidades no tenían nada que resolver, así
 * que caían al sello de la orden; pero el tablero de Calidad tampoco las mostraba, porque
 * filtraba por puntos pendientes. Resultado: no había dónde aprobarlas, su botón «Terminar» no
 * cambiaba nada, y nada se podía remisionar.
 *
 * Con un sello propio la regla es una sola y vale para las dos: **una unidad se despacha cuando
 * calidad la firmó**. Los puntos de revisión siguen siendo lo que hay que mirar para firmarla,
 * pero dejan de ser la firma.
 *
 * **El relleno importa más que la columna.** Las unidades de órdenes ya aprobadas se sellan con
 * la fecha de esa aprobación: lo que hoy se puede despachar tiene que poder despacharse mañana.
 * Una migración que deje inventario terminado sin salida es peor que no haberla corrido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('op_item_trabajos', 'calidad_revisada_at')) {
            Schema::table('op_item_trabajos', function (Blueprint $table) {
                $table->timestamp('calidad_revisada_at')->nullable()->after('bodega_material_id');
                $table->foreignId('calidad_revisada_por')->nullable()
                    ->after('calidad_revisada_at')
                    ->constrained('users')->nullOnDelete();
            });
        }

        // Lo que la orden ya había aprobado queda firmado con esa misma fecha.
        DB::table('op_item_trabajos')
            ->whereNull('calidad_revisada_at')
            ->whereIn('op_item_id', function ($q) {
                $q->select('op_items.id')
                    ->from('op_items')
                    ->join('ops', 'ops.id', '=', 'op_items.op_id')
                    ->whereNotNull('ops.calidad_aprobada_at');
            })
            ->update([
                'calidad_revisada_at' => DB::raw(
                    '(select ops.calidad_aprobada_at from op_items'
                    . ' join ops on ops.id = op_items.op_id'
                    . ' where op_items.id = op_item_trabajos.op_item_id limit 1)'
                ),
            ]);

        // Y las unidades cuya lista de revisión ya estaba resuelta, aunque la orden no se
        // hubiera sellado: alguien las miró punto por punto, y eso es exactamente la firma.
        DB::table('op_item_trabajos')
            ->whereNull('calidad_revisada_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('op_item_trabajo_checks')
                ->whereColumn('op_item_trabajo_checks.op_item_trabajo_id', 'op_item_trabajos.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('op_item_trabajo_checks')
                ->whereColumn('op_item_trabajo_checks.op_item_trabajo_id', 'op_item_trabajos.id')
                ->where('op_item_trabajo_checks.resultado', 'pendiente'))
            ->update(['calidad_revisada_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('op_item_trabajos', 'calidad_revisada_at')) {
            return;
        }

        Schema::table('op_item_trabajos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calidad_revisada_por');
            $table->dropColumn('calidad_revisada_at');
        });
    }
};
