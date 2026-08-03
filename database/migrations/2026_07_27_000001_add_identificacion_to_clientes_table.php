<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            if (! Schema::hasColumn('clientes', 'digito_verificacion')) {
                $table->string('digito_verificacion', 1)->nullable()->after('numero_identificacion');
            }
            if (! Schema::hasColumn('clientes', 'datos_rues')) {
                // Lo que devolvió el RUES el día que se consultó: estado de la
                // matrícula, cámara de comercio, actividad. Se guarda como
                // referencia, no como verdad permanente.
                $table->json('datos_rues')->nullable()->after('digito_verificacion');
            }
        });

        // El número de identificación se consulta seguido para detectar
        // duplicados: sin índice, cada búsqueda recorre toda la tabla.
        Schema::table('clientes', function (Blueprint $table) {
            $table->index('numero_identificacion', 'clientes_numero_identificacion_idx');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex('clientes_numero_identificacion_idx');
            $table->dropColumn(['digito_verificacion', 'datos_rues']);
        });
    }
};
