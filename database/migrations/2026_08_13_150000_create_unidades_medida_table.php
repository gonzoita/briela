<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las unidades de medida, administrables por la empresa.
 *
 * Estaban escritas en el código, en dos arreglos dentro de las pantallas de crear y editar
 * producto. Un fabricante que mida en pulgadas o en rollos tenía que pedir un cambio de
 * código, y eso viola la regla 1 del producto instalable: lo que alguien necesite distinto
 * va como opción configurable en el core.
 *
 * **La clave no cambia; la etiqueta sí.** `productos.unidad_medida` guarda la clave, así
 * que renombrar la etiqueta no toca los productos existentes. Es la misma regla de las
 * listas de segmentación, y por el mismo motivo: al otro lado hay datos guardados.
 *
 * Borrar una unidad tampoco rompe nada: `unidad_medida` es texto libre en `productos`, y
 * un producto viejo conserva la suya. Simplemente deja de ofrecerse para los nuevos.
 */
return new class extends Migration
{
    /** Lo que estaba escrito en el código, tal cual, para que nada cambie el primer día. */
    private const SEMILLA = [
        ['unidad',      'Unidad',                'ambos'],
        ['ml',          'Mililitros (ml)',       'producto'],
        ['m2',          'Metros cuadrados (m²)', 'producto'],
        ['kg',          'Kilogramos (kg)',       'producto'],
        ['mm',          'Milímetros (mm)',       'producto'],
        ['metros',      'Metros',                'producto'],
        ['litros',      'Litros',                'producto'],
        ['docenas',     'Docenas',               'producto'],
        ['pack',        'Pack',                  'producto'],
        ['cajas',       'Cajas',                 'producto'],
        ['hora',        'Hora',                  'servicio'],
        ['dia',         'Día',                   'servicio'],
        ['instalacion', 'Instalación',           'servicio'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('unidades_medida')) {
            Schema::create('unidades_medida', function (Blueprint $table) {
                $table->id();
                // La clave es lo que se guarda en el producto y lo que se lee al lado de una
                // cantidad («3 ml»), así que se mantiene corta.
                $table->string('clave', 30)->unique();
                $table->string('etiqueta', 60);
                // Para qué sirve: un producto no se mide en horas, y un servicio no en kilos.
                $table->enum('tipo', ['producto', 'servicio', 'ambos'])->default('producto');
                $table->unsignedInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        // Idempotente: la migración puede correr sobre una instalación donde alguien ya
        // cargó unidades a mano, y no debe duplicarlas ni pisar sus etiquetas.
        $existentes = DB::table('unidades_medida')->pluck('clave')->all();
        $orden      = (int) DB::table('unidades_medida')->max('orden');
        $filas      = [];

        foreach (self::SEMILLA as [$clave, $etiqueta, $tipo]) {
            if (in_array($clave, $existentes, true)) {
                continue;
            }

            $filas[] = [
                'clave'      => $clave,
                'etiqueta'   => $etiqueta,
                'tipo'       => $tipo,
                'orden'      => ++$orden,
                'activo'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($filas !== []) {
            DB::table('unidades_medida')->insert($filas);
        }
    }

    /**
     * Se va la tabla, no los datos de los productos: su `unidad_medida` es texto y sigue
     * ahí. Al volver atrás, las pantallas usarían de nuevo su lista de siempre.
     */
    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
    }
};
