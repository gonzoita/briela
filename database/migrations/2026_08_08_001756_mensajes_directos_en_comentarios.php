<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes directos entre usuarios, sobre la misma tabla de comentarios.
 *
 * Se reutiliza `comentarios` en vez de crear una tabla aparte porque un
 * mensaje directo necesita exactamente lo mismo que un comentario de
 * documento: los tipos (comentario / solicitud / tarea), el estado, el
 * responsable, la fecha límite, las menciones y los avisos. Duplicar todo eso
 * en otra tabla obligaría a mantener dos veces la misma lógica.
 *
 * La diferencia es de dónde cuelga el mensaje:
 *  - Hilo de documento → `comentable_*` lleno, `destinatario_id` vacío.
 *  - Mensaje directo   → `comentable_*` vacío, `destinatario_id` lleno.
 *
 * Y `referencia_*` permite adjuntar un documento al mensaje: es el "compartir
 * una cotización o una orden de compra" por el chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        // morphs() creó las columnas NOT NULL; un mensaje directo no cuelga de
        // ningún documento, así que tienen que admitir nulo.
        DB::statement('ALTER TABLE comentarios MODIFY comentable_type VARCHAR(255) NULL');
        DB::statement('ALTER TABLE comentarios MODIFY comentable_id BIGINT UNSIGNED NULL');

        Schema::table('comentarios', function (Blueprint $table) {
            $table->foreignId('destinatario_id')->nullable()->after('user_id')
                ->constrained('users')->cascadeOnDelete();

            // Documento compartido dentro del mensaje (cotización, OC, OP...).
            $table->nullableMorphs('referencia');

            $table->timestamp('leido_at')->nullable()->after('resuelto_por');

            // La bandeja pregunta "mis conversaciones y qué tengo sin leer".
            $table->index(['destinatario_id', 'leido_at']);
        });
    }

    public function down(): void
    {
        Schema::table('comentarios', function (Blueprint $table) {
            $table->dropIndex(['destinatario_id', 'leido_at']);
            $table->dropConstrainedForeignId('destinatario_id');
            $table->dropMorphs('referencia');
            $table->dropColumn('leido_at');
        });

        DB::statement('ALTER TABLE comentarios MODIFY comentable_type VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE comentarios MODIFY comentable_id BIGINT UNSIGNED NOT NULL');
    }
};
