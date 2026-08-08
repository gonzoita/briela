<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntar CUALQUIER cosa del sistema a un mensaje, no solo los cuatro tipos
 * que estaban en la lista blanca.
 *
 * En vez de ampliar esa lista módulo por módulo —y tener que volver a tocarla
 * cada vez que nazca uno nuevo—, se guarda lo que el buscador global ya
 * devuelve de cualquier módulo: **qué es, cómo se llama y a dónde lleva**.
 *
 * `referencia_type` / `referencia_id` se quedan para lo ya guardado y para
 * cuando se necesite el modelo de verdad; para compartir basta con esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comentarios', function (Blueprint $table) {
            // Qué es: 'cotizacion', 'remision', 'orden_compra', lo que dé el buscador.
            $table->string('referencia_tipo', 40)->nullable()->after('referencia_id');
            $table->string('referencia_titulo', 200)->nullable()->after('referencia_tipo');
            // Siempre una ruta interna (empieza por /). Se valida al guardar:
            // aceptar una URL externa convertiría el chat en un vector de phishing.
            $table->string('referencia_url', 300)->nullable()->after('referencia_titulo');
        });
    }

    public function down(): void
    {
        Schema::table('comentarios', function (Blueprint $table) {
            $table->dropColumn(['referencia_tipo', 'referencia_titulo', 'referencia_url']);
        });
    }
};
