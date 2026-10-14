<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sección de cada línea de una cotización («Puerta corredera», «Cocina → Estufa») y las opciones
 * de cada plantilla PDF (agrupar por sección, con o sin foto, con o sin precios).
 *
 * Solo agrega columnas nulables: las cotizaciones y plantillas de antes siguen saliendo igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $t) {
            if (! Schema::hasColumn('cotizacion_items', 'seccion')) {
                $t->string('seccion', 80)->nullable()->after('orden');
            }
        });

        Schema::table('pdf_plantillas', function (Blueprint $t) {
            if (! Schema::hasColumn('pdf_plantillas', 'opciones')) {
                $t->json('opciones')->nullable()->after('config_tabla');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $t) {
            if (Schema::hasColumn('cotizacion_items', 'seccion')) $t->dropColumn('seccion');
        });

        Schema::table('pdf_plantillas', function (Blueprint $t) {
            if (Schema::hasColumn('pdf_plantillas', 'opciones')) $t->dropColumn('opciones');
        });
    }
};
