<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo empezó y cuándo terminó de fabricarse esta unidad.
 *
 * Los pasos ya guardaban su hora de inicio y de cierre, pero la **unidad** no tenía ninguna: en
 * el tablero no había forma de saber cuándo arrancó una puerta ni cuándo salió de producción,
 * y lo único que se veía era «sin fecha», que es la fecha de entrega prometida y otra cosa
 * distinta.
 *
 * - `iniciado_at`  — la primera vez que alguien tocó un paso. Es cuando arrancó de verdad.
 * - `terminado_at` — cuando se cerró el último. **Es también la hora en que llegó a calidad**,
 *   porque es el mismo instante: una unidad entra a revisión en el momento en que sale de
 *   producción. Guardarlo dos veces sería tener dos versiones de un solo hecho.
 *
 * Se rellenan de los pasos, que ya lo sabían. Una fecha que se puede deducir del historial no
 * se deja vacía «desde ahora en adelante»: el tablero mostraría huecos en todo lo ya fabricado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('op_item_trabajos', 'iniciado_at')) {
            Schema::table('op_item_trabajos', function (Blueprint $table) {
                $table->timestamp('iniciado_at')->nullable()->after('total_unidades');
                $table->timestamp('terminado_at')->nullable()->after('iniciado_at');
            });
        }

        // Arrancó cuando arrancó su primer paso: se mira el inicio y el cierre, porque un paso
        // marcado de una sola vez no tiene `iniciado_at` propio.
        DB::table('op_item_trabajos')->whereNull('iniciado_at')->update([
            'iniciado_at' => DB::raw(
                '(select min(least(coalesce(p.iniciado_at, p.completado_at),'
                . ' coalesce(p.completado_at, p.iniciado_at)))'
                . ' from op_item_trabajo_pasos p'
                . ' where p.op_item_trabajo_id = op_item_trabajos.id'
                . ' and (p.iniciado_at is not null or p.completado_at is not null))'
            ),
        ]);

        // Y terminó cuando se cerró el último, pero solo si de verdad está completa: una unidad
        // a medio fabricar con tres pasos cerrados no terminó nada.
        DB::table('op_item_trabajos')
            ->whereNull('terminado_at')
            ->where('porcentaje_avance', 100)
            ->update([
                'terminado_at' => DB::raw(
                    '(select max(p.completado_at) from op_item_trabajo_pasos p'
                    . ' where p.op_item_trabajo_id = op_item_trabajos.id)'
                ),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('op_item_trabajos', 'iniciado_at')) {
            return;
        }

        Schema::table('op_item_trabajos', function (Blueprint $table) {
            $table->dropColumn(['iniciado_at', 'terminado_at']);
        });
    }
};
