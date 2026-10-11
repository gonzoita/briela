<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responder a un mensaje concreto del chat. Si el original se borra, la respuesta queda: solo
 * pierde la cita.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('comentarios', 'respuesta_a_id')) {
            Schema::table('comentarios', function (Blueprint $table) {
                $table->foreignId('respuesta_a_id')->nullable()->constrained('comentarios')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }
};
