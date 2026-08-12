<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un precio por canal, para productos y para ensambles.
 *
 * Antes eran columnas fijas: `precio_mayorista`, `precio_distribuidor`,
 * `precio_cliente_final`, y sus márgenes, comisiones y descuentos máximos — dieciséis
 * columnas para tres canales, en dos tablas. Agregar un cuarto canal significaba una
 * migración y tocar cada pantalla.
 *
 * Ahora los canales son filas. Uno por cada tipo de contacto marcado con
 * `define_precio`, y quien quiera un canal nuevo lo crea desde la interfaz.
 *
 * **Las columnas viejas NO se borran.** La regla 2 del producto: migraciones hacia
 * adelante y nunca destructivas, porque al otro lado hay bases de clientes a las que no
 * se tiene acceso. Quedan un período de compatibilidad, se llenan en paralelo mientras
 * exista código que las lea, y se retiran cuando ya nadie las use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canal_precios', function (Blueprint $table) {
            $table->id();

            // Morfológica porque productos y ensambles necesitan lo mismo, y duplicar la
            // tabla obliga a duplicar cada consulta, cada pantalla y cada arreglo.
            $table->morphs('precionable');

            $table->foreignId('segmentacion_opcion_id')
                ->constrained('segmentacion_opciones')
                ->cascadeOnDelete();

            // El margen es lo que se edita; el precio, lo que se calcula y se guarda ya
            // resuelto. Se guardan los dos porque una cotización vieja tiene que poder
            // mostrar el precio con el que se hizo, aunque el margen haya cambiado.
            $table->decimal('margen_pct', 6, 2)->default(0);
            $table->decimal('precio', 14, 2)->default(0);

            $table->decimal('comision_min_pct', 5, 2)->default(0);
            $table->decimal('comision_max_pct', 5, 2)->default(0);
            $table->decimal('descuento_max_pct', 5, 2)->default(0);

            $table->timestamps();

            // Un canal no puede estar dos veces en el mismo producto.
            $table->unique(['precionable_type', 'precionable_id', 'segmentacion_opcion_id'], 'canal_unico_por_item');
        });

        $this->rellenar();
    }

    /**
     * Pasa lo que ya está cargado en columnas a las filas nuevas.
     *
     * Se hace por tandas y no de un golpe: una instalación con miles de productos en un
     * hosting compartido se queda sin memoria leyéndolos todos a la vez.
     */
    private function rellenar(): void
    {
        $opciones = DB::table('segmentacion_opciones')
            ->where('tipo', 'tipo_contacto')
            ->whereIn('valor', ['mayorista', 'distribuidor', 'cliente_directo'])
            ->pluck('id', 'valor');

        // El nombre de la columna vieja de cada canal. `cliente_directo` corresponde a
        // las columnas `*_cliente_final`: los nombres nunca coincidieron.
        $mapa = [
            'mayorista'       => 'mayorista',
            'distribuidor'    => 'distribuidor',
            'cliente_directo' => 'cliente_final',
        ];

        foreach ([['productos', \App\Models\Producto::class], ['ensambles', \App\Models\Ensamble::class]] as [$tabla, $clase]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            DB::table($tabla)->orderBy('id')->chunk(200, function ($filas) use ($tabla, $clase, $opciones, $mapa) {
                $nuevas = [];

                foreach ($filas as $fila) {
                    foreach ($mapa as $valor => $sufijo) {
                        if (! isset($opciones[$valor])) {
                            continue;
                        }

                        $nuevas[] = [
                            'precionable_type'       => $clase,
                            'precionable_id'         => $fila->id,
                            'segmentacion_opcion_id' => $opciones[$valor],
                            // Los ensambles no tienen columnas de margen por canal: su
                            // precio sale del cotizador, no de un margen sobre el costo.
                            'margen_pct'        => (float) ($fila->{'margen_' . $sufijo} ?? 0),
                            'precio'            => (float) ($fila->{'precio_' . $sufijo} ?? 0),
                            // El canal base no lleva comisión, y esas columnas no existen
                            // para mayorista: nunca las tuvo.
                            'comision_min_pct'  => (float) ($fila->{'comision_min_' . $sufijo} ?? 0),
                            'comision_max_pct'  => (float) ($fila->{'comision_max_' . $sufijo} ?? 0),
                            'descuento_max_pct' => (float) ($fila->{'descuento_max_' . $sufijo} ?? 0),
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ];
                    }
                }

                if ($nuevas !== []) {
                    DB::table('canal_precios')->insert($nuevas);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('canal_precios');
    }
};
