<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensambles armados a mano, sin plantilla ni fórmulas.
 *
 * Hasta ahora todo ensamble nacía de una `PlantillaEnsamble`: se escribían las medidas y las
 * fórmulas calculaban los materiales. Eso es lo que hace falta para fabricar por medidas —una
 * puerta de 2400 x 2600— pero es demasiado para un kit que siempre lleva lo mismo: dos
 * bisagras, un motor y cuatro metros de perfil.
 *
 * Un **ensamble directo** es la misma cosa con la receta escrita a mano: líneas con cantidades
 * exactas, sin variables. Guarda sus componentes con la MISMA forma que los calculados, así
 * que la orden de producción, el consumo de inventario al despachar y los PDF no distinguen
 * uno de otro: no hubo que tocar nada de eso.
 *
 * Dos cambios, los dos hacia adelante:
 *
 * - `plantilla_id` pasa a admitir nulo. Los ensambles que ya existen conservan la suya.
 * - `tipo_armado` dice de cuál se trata sin tener que deducirlo de si hay plantilla o no.
 *   Deducirlo funcionaría hoy y se rompería el día que un ensamble directo se quiera asociar
 *   a una plantilla como referencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ensambles', 'tipo_armado')) {
            Schema::table('ensambles', function (Blueprint $table) {
                $table->string('tipo_armado', 20)->default('plantilla')->after('plantilla_id');
            });
        }

        // Lo que ya existe nació de una plantilla: se deja dicho explícitamente.
        DB::table('ensambles')->whereNull('tipo_armado')->orWhere('tipo_armado', '')
            ->update(['tipo_armado' => 'plantilla']);

        // Sin `doctrine/dbal` no hay `->change()`: va en SQL. Se repite el tipo completo
        // porque un MODIFY parcial perdería el resto de la definición.
        $this->quitarLlaveForanea();

        DB::statement('ALTER TABLE `ensambles` MODIFY `plantilla_id` BIGINT UNSIGNED NULL');

        DB::statement(
            'ALTER TABLE `ensambles` ADD CONSTRAINT `ensambles_plantilla_id_foreign` '
            .'FOREIGN KEY (`plantilla_id`) REFERENCES `plantillas_ensamble` (`id`) ON DELETE SET NULL'
        );
    }

    /**
     * La columna no se puede volver nula mientras la llave foránea la sostenga con su
     * definición vieja, así que se retira y se vuelve a poner — ahora con SET NULL, que es
     * lo que corresponde cuando el valor puede faltar.
     */
    private function quitarLlaveForanea(): void
    {
        $existe = collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
            .'AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['ensambles', 'plantilla_id']
        ))->pluck('CONSTRAINT_NAME');

        foreach ($existe as $nombre) {
            DB::statement("ALTER TABLE `ensambles` DROP FOREIGN KEY `{$nombre}`");
        }
    }

    /**
     * No se vuelve `plantilla_id` obligatoria: si hay ensambles directos, ponerla NOT NULL
     * fallaría o —peor— exigiría inventarles una plantilla. La columna admite nulo y ya.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ensambles', 'tipo_armado')) {
            Schema::table('ensambles', fn (Blueprint $table) => $table->dropColumn('tipo_armado'));
        }
    }
};
