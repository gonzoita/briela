<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Publicaciones programadas para redes sociales. Un registro puede
    // publicarse en varias cuentas/redes a la vez (ver publicaciones_rrss_cuentas).
    public function up(): void
    {
        Schema::create('publicaciones_rrss', function (Blueprint $table) {
            $table->id();
            $table->text('contenido');
            $table->dateTime('fecha_programada');
            $table->enum('estado', ['borrador', 'programada', 'publicando', 'publicada', 'fallida', 'parcial'])
                ->default('borrador');
            $table->foreignId('creado_por')->constrained('users')->cascadeOnDelete();
            $table->timestamp('publicado_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'fecha_programada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicaciones_rrss');
    }
};
