<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las marcas que convierten un tipo de contacto en un canal de precio.
 *
 * Hasta ahora los canales eran tres textos escritos en el código —`mayorista`,
 * `distribuidor`, `cliente_final`— comparados a mano en `Cotizaciones/Create.vue`. Se
 * podían agregar tipos de contacto nuevos, pero cualquiera que se agregara caía en
 * «cliente final» sin que nada lo dijera. Un cliente con un cuarto canal no se podía
 * atender sin tocar código, y eso choca con la regla de que nada se personaliza.
 *
 * Tres marcas, porque hay tres papeles distintos que antes estaban implícitos:
 *
 * - `define_precio`: este tipo de contacto tiene su propia lista de precios.
 * - `es_canal_base`: es el piso de utilidad de la empresa. No paga comisión al
 *   vendedor, y la comisión de los demás canales se calcula contra su precio. Era
 *   `mayorista`, clavado en el código de comisiones.
 * - `es_precio_publico`: es el precio que ve alguien que no ha entrado al sistema, en
 *   el catálogo público. Era `precio_cliente_final`, también clavado.
 *
 * Se rellenan con los tres valores actuales para que nada cambie de comportamiento al
 * migrar: quien ya tenía precios cargados los conserva y los sigue viendo igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segmentacion_opciones', function (Blueprint $table) {
            $table->boolean('define_precio')->default(false)->after('color');
            $table->boolean('es_canal_base')->default(false)->after('define_precio');
            $table->boolean('es_precio_publico')->default(false)->after('es_canal_base');
        });

        // El comportamiento de hoy, tal cual, escrito ahora en datos y no en código.
        DB::table('segmentacion_opciones')
            ->where('tipo', 'tipo_contacto')
            ->whereIn('valor', ['mayorista', 'distribuidor', 'cliente_directo'])
            ->update(['define_precio' => true]);

        DB::table('segmentacion_opciones')
            ->where('tipo', 'tipo_contacto')->where('valor', 'mayorista')
            ->update(['es_canal_base' => true]);

        // El «Precio Cliente Final» de los productos pasa a ser el de «Cliente directo»,
        // que es la opción que ya cumple ese papel en la lista.
        DB::table('segmentacion_opciones')
            ->where('tipo', 'tipo_contacto')->where('valor', 'cliente_directo')
            ->update(['es_precio_publico' => true]);

        $this->conservarLaPrioridadDeHoy();
    }

    /**
     * El orden de la lista pasa a ser la prioridad, así que hay que dejarlo como el
     * código decidía hasta ahora.
     *
     * `Cotizaciones/Create.vue` preguntaba en este orden: mayorista, luego distribuidor,
     * y si no, cliente final. O sea que un cliente marcado como mayorista Y distribuidor
     * pagaba precio mayorista.
     *
     * El orden que traía la lista era el contrario —cliente directo, distribuidor,
     * mayorista—, así que sin esto un cliente con los dos tipos pasaría a pagar precio de
     * distribuidor de un día para otro. Con precios reales de una instalación en marcha,
     * eso es cobrarle quince mil pesos más por unidad sin que nadie lo note: exactamente
     * la clase de cambio silencioso que una migración no debe hacer.
     *
     * Después de migrar, la empresa puede reordenar la lista arrastrando, y ahí sí el
     * cambio es una decisión suya.
     */
    private function conservarLaPrioridadDeHoy(): void
    {
        $prioridad = ['mayorista' => 1, 'distribuidor' => 2, 'cliente_directo' => 3];

        foreach ($prioridad as $valor => $orden) {
            DB::table('segmentacion_opciones')
                ->where('tipo', 'tipo_contacto')->where('valor', $valor)
                ->update(['orden' => $orden]);
        }

        // Los tipos que no definen precio van después, conservando su orden relativo.
        $resto = DB::table('segmentacion_opciones')
            ->where('tipo', 'tipo_contacto')
            ->whereNotIn('valor', array_keys($prioridad))
            ->orderBy('orden')->pluck('id');

        foreach ($resto as $i => $id) {
            DB::table('segmentacion_opciones')->where('id', $id)
                ->update(['orden' => count($prioridad) + 1 + $i]);
        }
    }

    public function down(): void
    {
        Schema::table('segmentacion_opciones', function (Blueprint $table) {
            $table->dropColumn(['define_precio', 'es_canal_base', 'es_precio_publico']);
        });
    }
};
