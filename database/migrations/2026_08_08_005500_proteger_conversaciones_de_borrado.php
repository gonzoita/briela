<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las conversaciones son EVIDENCIA: no se pueden perder.
 *
 * Al crear la tabla, `user_id` y `destinatario_id` quedaron con borrado en
 * cascada. Eso significa que borrar un usuario **borraba de la base todos sus
 * mensajes**, en silencio y sin vuelta atrás. Y `User` no usa borrado suave,
 * así que habría sido definitivo.
 *
 * Se cambia a RESTRICT: si alguien intenta borrar un usuario que tiene
 * mensajes, la base lo impide y falla de forma ruidosa, en vez de destruir el
 * historial sin que nadie se entere.
 *
 * No rompe el flujo normal: el módulo de Usuarios **desactiva** (activo=false)
 * en vez de borrar, justamente para conservar el rastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comentarios', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['destinatario_id']);
        });

        Schema::table('comentarios', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('destinatario_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comentarios', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['destinatario_id']);
        });

        Schema::table('comentarios', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('destinatario_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
