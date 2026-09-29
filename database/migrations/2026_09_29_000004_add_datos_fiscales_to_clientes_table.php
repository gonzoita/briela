<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que dice el RUT del cliente y decide qué retenciones va a practicar.
 *
 *  - `responsabilidades_fiscales`: los códigos de la casilla 53 del RUT (13 gran
 *    contribuyente, 07 agente de retención en renta, 09 agente de retención de IVA…).
 *  - `actividad_economica`: el código CIIU principal.
 *  - `retenedor_ica`: si retiene ICA. No sale del RUT —el ICA es municipal y cada ciudad
 *    nombra a sus agentes—, así que se marca a mano.
 *  - `datos_rut`: lo que se leyó del documento, como referencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            if (! Schema::hasColumn('clientes', 'responsabilidades_fiscales')) {
                $table->json('responsabilidades_fiscales')->nullable()->after('datos_rues');
            }
            if (! Schema::hasColumn('clientes', 'actividad_economica')) {
                $table->string('actividad_economica', 10)->nullable()->after('responsabilidades_fiscales');
            }
            if (! Schema::hasColumn('clientes', 'retenedor_ica')) {
                $table->boolean('retenedor_ica')->default(false)->after('actividad_economica');
            }
            if (! Schema::hasColumn('clientes', 'datos_rut')) {
                $table->json('datos_rut')->nullable()->after('retenedor_ica');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['responsabilidades_fiscales', 'actividad_economica', 'retenedor_ica', 'datos_rut']);
        });
    }
};
