<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Pivote: por cada cuenta a la que apunta una publicación, guarda el
    // resultado individual (una publicación puede triunfar en Facebook y
    // fallar en Instagram, por ejemplo).
    public function up(): void
    {
        Schema::create('publicaciones_rrss_cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicacion_rrss_id')->constrained('publicaciones_rrss')->cascadeOnDelete();
            $table->foreignId('cuenta_rrss_id')->constrained('cuentas_rrss')->cascadeOnDelete();
            $table->enum('estado', ['pendiente', 'publicada', 'fallida'])->default('pendiente');
            $table->timestamp('publicado_en')->nullable();
            $table->string('id_publicacion_externa')->nullable(); // ID que devuelve la API de la red
            $table->string('url_publicacion')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['publicacion_rrss_id', 'cuenta_rrss_id'], 'pub_rrss_cuenta_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicaciones_rrss_cuentas');
    }
};
