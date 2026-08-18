<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La comisión pasa a ser un porcentaje DEL PRECIO, no del excedente.
 *
 * El número guardado no cambia de significado solo: hay que convertirlo, o una comisión del
 * 70 % del excedente pasaría a leerse como el 70 % del precio y el vendedor se llevaría catorce
 * veces lo pactado. La conversión es exacta y conserva la plata:
 *
 *     pct_del_precio = pct_del_excedente × (precio − precio_del_canal_base) / precio
 *
 * En el caso que la destapó: 70 % de un excedente de 102.000 sobre un precio de 1.428.000 son
 * 71.400, que es el 5 % del precio. La misma plata, dicha en la unidad que entiende un vendedor
 * —«gano el 5 % de lo que vendo»— y que hace que el descuento sea la resta de dos porcentajes.
 *
 * Hacia adelante y sin destruir nada: no borra ni renombra columnas. Una fila sin canal base,
 * sin precio o sin comisión se queda como está — no hay nada que convertir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('canal_precios') || ! Schema::hasTable('segmentacion_opciones')) {
            return;
        }

        $base = DB::table('segmentacion_opciones')
            ->where('tipo', 'tipo_contacto')
            ->where('es_canal_base', true)
            ->value('id');

        if (! $base) {
            return;
        }

        // El precio del canal base de cada producto o ensamble, que es el piso del excedente.
        $precioBase = DB::table('canal_precios')
            ->where('segmentacion_opcion_id', $base)
            ->get(['precionable_type', 'precionable_id', 'precio'])
            ->keyBy(fn ($f) => $f->precionable_type.'#'.$f->precionable_id);

        DB::table('canal_precios')
            ->where('segmentacion_opcion_id', '!=', $base)
            ->where(function ($q) {
                $q->where('comision_min_pct', '>', 0)->orWhere('comision_max_pct', '>', 0);
            })
            ->orderBy('id')
            ->chunkById(200, function ($filas) use ($precioBase) {
                foreach ($filas as $fila) {
                    $piso = $precioBase->get($fila->precionable_type.'#'.$fila->precionable_id);

                    $precio    = (float) $fila->precio;
                    $excedente = $precio - (float) ($piso->precio ?? 0);

                    if ($precio <= 0 || $excedente <= 0) {
                        continue;
                    }

                    $factor = $excedente / $precio;

                    DB::table('canal_precios')->where('id', $fila->id)->update([
                        'comision_min_pct' => round((float) $fila->comision_min_pct * $factor, 2),
                        'comision_max_pct' => round((float) $fila->comision_max_pct * $factor, 2),
                    ]);
                }
            });

        // Las columnas viejas dicen lo mismo que las filas, y hay código que todavía las lee.
        // Se vuelven a escribir desde las filas ya convertidas.
        foreach ([
            ['tabla' => 'productos', 'tipo' => 'App\Models\Producto'],
            ['tabla' => 'ensambles', 'tipo' => 'App\Models\Ensamble'],
        ] as $que) {
            if (! Schema::hasTable($que['tabla'])) {
                continue;
            }

            foreach (['distribuidor', 'cliente_final'] as $sufijo) {
                if (! Schema::hasColumn($que['tabla'], "comision_max_{$sufijo}")) {
                    continue;
                }

                DB::table($que['tabla'])->orderBy('id')->chunkById(200, function ($items) use ($que, $sufijo, $base) {
                    foreach ($items as $item) {
                        $filas = DB::table('canal_precios')
                            ->where('precionable_type', $que['tipo'])
                            ->where('precionable_id', $item->id)
                            ->where('segmentacion_opcion_id', '!=', $base)
                            ->get();

                        if ($filas->isEmpty()) {
                            continue;
                        }

                        // Distribuidor es el primero que no es base ni público; cliente final,
                        // el público. El espejo del servicio hace lo mismo por el papel del
                        // canal, y aquí se resuelve con las mismas dos consultas.
                        $publicos = DB::table('segmentacion_opciones')
                            ->where('es_precio_publico', true)->pluck('id')->all();

                        $fila = $sufijo === 'cliente_final'
                            ? $filas->firstWhere(fn ($f) => in_array($f->segmentacion_opcion_id, $publicos, true))
                            : $filas->first(fn ($f) => ! in_array($f->segmentacion_opcion_id, $publicos, true));

                        if (! $fila) {
                            continue;
                        }

                        DB::table($que['tabla'])->where('id', $item->id)->update([
                            "comision_min_{$sufijo}" => $fila->comision_min_pct,
                            "comision_max_{$sufijo}" => $fila->comision_max_pct,
                        ]);
                    }
                });
            }
        }
    }

    /**
     * No se deshace.
     *
     * Volver atrás multiplicaría por el inverso, y una fila cuyo precio haya cambiado en el
     * medio no volvería al número original: quedaría una comisión inventada. Si hiciera falta
     * revertir, se corre `comisiones:recalcular`, que reparte de cero con la regla vigente.
     */
    public function down(): void
    {
    }
};
