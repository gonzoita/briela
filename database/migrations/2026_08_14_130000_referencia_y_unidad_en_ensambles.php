<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un ensamble también necesita referencia y unidad de medida.
 *
 * Los productos las tienen desde siempre; los ensambles no, y eso se nota en cuanto salen
 * del sistema: en una cotización, en una orden de producción o en una remisión, el ensamble
 * era la única línea sin código con el que buscarlo. La pantalla lo escribía como
 * `ENS-{id}` a mano en algunos sitios, que es un identificador de base de datos disfrazado
 * de referencia: cambia si se migra la base y no se puede dictar por teléfono.
 *
 * La unidad, igual: todo ensamble se cotizaba «por unidad» aunque el fabricante venda
 * metros lineales de mueble o juegos de dos puertas.
 *
 * `referencia` NO se declara única. En productos tampoco lo está a nivel de base, y aquí
 * hacerlo obligaría a inventar un valor para las filas existentes antes de poner el índice
 * — con bases de clientes al otro lado y la regla 2 de migraciones hacia adelante, un índice
 * único que puede fallar en una base ajena es exactamente lo que no se hace.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ensambles', 'referencia')) {
            Schema::table('ensambles', function (Blueprint $table) {
                $table->string('referencia', 60)->nullable()->after('nombre');
                $table->index('referencia');
            });
        }

        if (! Schema::hasColumn('ensambles', 'unidad_medida')) {
            Schema::table('ensambles', function (Blueprint $table) {
                $table->string('unidad_medida', 30)->default('unidad')->after('referencia');
            });
        }

        // A los ensambles que ya existen se les pone una referencia con el mismo formato que
        // usarán los nuevos, en su orden de creación. Sin esto quedarían en blanco justo en
        // la columna que se acaba de agregar para poder buscarlos.
        $sinReferencia = DB::table('ensambles')
            ->whereNull('referencia')->orWhere('referencia', '')
            ->orderBy('id')
            ->pluck('id');

        $consecutivo = 0;

        foreach ($sinReferencia as $id) {
            $consecutivo++;
            DB::table('ensambles')->where('id', $id)->update([
                'referencia' => 'ENS-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ensambles', 'referencia')) {
            Schema::table('ensambles', function (Blueprint $table) {
                $table->dropIndex(['referencia']);
                $table->dropColumn('referencia');
            });
        }

        if (Schema::hasColumn('ensambles', 'unidad_medida')) {
            Schema::table('ensambles', fn (Blueprint $t) => $t->dropColumn('unidad_medida'));
        }
    }
};
