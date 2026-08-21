<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El reglamento interno de trabajo, con su enlace público.
 *
 * Es un documento que la empresa **tiene que poder mostrarle a cualquiera**: a un colaborador
 * nuevo el primer día, a un inspector, a alguien que todavía no tiene usuario. Por eso vive
 * detrás de un token público y no de un login.
 *
 * La tabla admite varias filas aunque hoy se use una sola: el día que alguien publique una
 * versión nueva, la anterior tiene que poder seguir existiendo. Un reglamento derogado sigue
 * siendo el que estaba vigente cuando pasó algo, y borrarlo es perder esa prueba.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reglamentos')) {
            return;
        }

        Schema::create('reglamentos', function (Blueprint $table) {
            $table->id();

            $table->string('titulo', 200)->default('Reglamento Interno de Trabajo');

            // El documento entero, en HTML. `longText` y no `text`: un reglamento real pasa de
            // las 64 000 letras que aguanta `text`, y ahí se corta sin avisar.
            $table->longText('contenido')->nullable();

            $table->string('version', 30)->nullable();
            $table->date('vigente_desde')->nullable();

            // La dirección pública. Se puede regenerar: es lo que se hace cuando el enlace se
            // filtró a donde no debía.
            $table->string('token_publico', 64)->unique();

            // Apagado, el enlace público deja de responder sin borrar nada.
            $table->boolean('publicado')->default(false);

            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglamentos');
    }
};
