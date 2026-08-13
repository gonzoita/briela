<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El texto técnico corto, el que va en cotizaciones y órdenes de producción.
 *
 * Había dos descripciones y ninguna servía para esto:
 *
 * - **`descripcion_corta`** es comercial: la introducción persuasiva del catálogo y del
 *   sitio web. En una cotización suena a folleto.
 * - **`descripcion_larga`** es la ficha técnica completa. La cotización la imprimía
 *   entera, y una cotización de tres ítems salía de cuatro páginas con veinte viñetas por
 *   producto. Es exactamente lo que se ve mal.
 *
 * Este campo es la tercera: dos o tres líneas con lo que un cliente necesita leer al lado
 * del precio —medidas, material, potencia— y nada más. La ficha técnica sigue existiendo
 * para el catálogo, la web y quien la pida.
 *
 * 600 caracteres y no 300: una puerta frigorífica con medidas, lámina, aislamiento y
 * voltaje no cabe en 300, y el que quiera dos líneas escribe dos líneas. El tope está
 * para que nadie pegue la ficha completa aquí, no para pelear con el redactor.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['productos', 'ensambles'] as $tabla) {
            if (Schema::hasColumn($tabla, 'descripcion_cotizacion')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->string('descripcion_cotizacion', 600)->nullable()->after('descripcion_larga');
            });
        }
    }

    public function down(): void
    {
        foreach (['productos', 'ensambles'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'descripcion_cotizacion')) {
                continue;
            }

            Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn('descripcion_cotizacion'));
        }
    }
};
