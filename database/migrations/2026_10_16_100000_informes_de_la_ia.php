<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los informes que pide la guía en el chat, y las fuentes nuevas.
 *
 * - `fuente` deja de ser una lista cerrada: cada fuente nueva (leads, ventas, cartera…) es una
 *   clase, y una lista en la base obligaría a una migración por cada una. Pasa a texto; los
 *   valores que había siguen igual.
 * - `agrupar`: por qué se resume («por canal», «por vendedor»).
 * - `origen`, `pregunta`, `analisis`: el informe que armó la guía guarda lo que se le preguntó y
 *   lo que respondió, para volver a leerlo sin el chat.
 *
 * - `asistente_mensajes.informe`: la tarjeta del informe que dio la guía, para que siga ahí al
 *   recargar el chat (una foto: volver a calcularlo cambiaría las cifras de una respuesta vieja).
 *
 * Solo agrega o ensancha: nada se borra ni se renombra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE informes MODIFY COLUMN fuente VARCHAR(40) NOT NULL");
            DB::statement("ALTER TABLE informes MODIFY COLUMN tipo_grafica VARCHAR(20) NULL");
        }

        Schema::table('informes', function (Blueprint $table) {
            if (! Schema::hasColumn('informes', 'agrupar')) {
                $table->json('agrupar')->nullable()->after('filtros');
            }
            if (! Schema::hasColumn('informes', 'origen')) {
                $table->string('origen', 20)->default('manual')->after('publico');
                $table->text('pregunta')->nullable()->after('origen');
                $table->longText('analisis')->nullable()->after('pregunta');
                $table->timestamp('analizado_at')->nullable()->after('analisis');
            }
        });

        if (Schema::hasTable('asistente_mensajes') && ! Schema::hasColumn('asistente_mensajes', 'informe')) {
            Schema::table('asistente_mensajes', function (Blueprint $table) {
                $table->json('informe')->nullable()->after('consulta');
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }
};
