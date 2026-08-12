<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El margen con el que nace cada canal en un producto nuevo.
 *
 * Estaba escrito en el servidor: 25 para el canal base, 35 para el precio público, 30 para
 * los demás. Antes de eso estaba escrito en la pantalla. En ninguno de los dos sitios lo
 * podía cambiar la empresa, y es un número que cambia con el mercado y con el rubro.
 *
 * Se sigue pudiendo ajustar producto por producto al crearlo: esto es el valor con el que
 * el formulario arranca, no un tope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segmentacion_opciones', function (Blueprint $table) {
            $table->decimal('margen_sugerido', 5, 2)->default(30)->after('es_precio_publico');
        });

        // Se conservan los valores que el servidor venía usando, para que un producto nuevo
        // nazca hoy con lo mismo que ayer.
        DB::table('segmentacion_opciones')->where('tipo', 'tipo_contacto')
            ->where('es_canal_base', true)->update(['margen_sugerido' => 25]);

        DB::table('segmentacion_opciones')->where('tipo', 'tipo_contacto')
            ->where('es_precio_publico', true)->update(['margen_sugerido' => 35]);
    }

    public function down(): void
    {
        Schema::table('segmentacion_opciones', function (Blueprint $table) {
            $table->dropColumn('margen_sugerido');
        });
    }
};
