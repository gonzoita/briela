<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la tienda en línea muestra de un producto además de su nombre: la marca, el origen y
 * sus características especiales («Material: acero inoxidable»), y si su precio se publica o no.
 *
 * Solo columnas nuevas y nulables. `publicar_precio` nace en verdadero para que los productos que
 * ya estaban publicados sigan mostrando su precio como hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            if (! Schema::hasColumn('productos', 'marca')) {
                $t->string('marca', 120)->nullable()->after('referencia');
            }
            if (! Schema::hasColumn('productos', 'origen')) {
                $t->string('origen', 120)->nullable()->after('marca');
            }
            if (! Schema::hasColumn('productos', 'caracteristicas')) {
                $t->json('caracteristicas')->nullable()->after('descripcion_cotizacion');
            }
            if (! Schema::hasColumn('productos', 'publicar_precio')) {
                $t->boolean('publicar_precio')->default(true)->after('publicado_web_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            foreach (['marca', 'origen', 'caracteristicas', 'publicar_precio'] as $columna) {
                if (Schema::hasColumn('productos', $columna)) {
                    $t->dropColumn($columna);
                }
            }
        });
    }
};
