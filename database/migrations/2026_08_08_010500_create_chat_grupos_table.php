<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de chat: conversaciones de varias personas (producción, compras,
 * el equipo de una obra...).
 *
 * Los mensajes siguen viviendo en `comentarios`. Un mensaje cuelga de UNA de
 * tres cosas, y solo una:
 *   - un documento  → comentable_*
 *   - una persona   → destinatario_id
 *   - un grupo      → grupo_id
 *
 * Se sigue reutilizando la misma tabla porque un mensaje de grupo necesita
 * exactamente lo mismo: tipos, tareas, adjuntos, menciones y avisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('descripcion', 255)->nullable();
            // Quién lo creó. RESTRICT igual que los mensajes: borrar a esa
            // persona no puede llevarse por delante la conversación del grupo.
            $table->foreignId('creado_por')->constrained('users')->restrictOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('chat_grupo_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_grupo_id')->constrained('chat_grupos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Hasta cuándo leyó cada quien: así el contador de no leídos es
            // por persona sin guardar una fila por mensaje y por miembro.
            $table->timestamp('leido_hasta')->nullable();
            $table->timestamps();

            $table->unique(['chat_grupo_id', 'user_id']);
        });

        Schema::table('comentarios', function (Blueprint $table) {
            $table->foreignId('grupo_id')->nullable()->after('destinatario_id')
                ->constrained('chat_grupos')->cascadeOnDelete();

            $table->index(['grupo_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('comentarios', function (Blueprint $table) {
            $table->dropIndex(['grupo_id', 'created_at']);
            $table->dropConstrainedForeignId('grupo_id');
        });

        Schema::dropIfExists('chat_grupo_usuario');
        Schema::dropIfExists('chat_grupos');
    }
};
