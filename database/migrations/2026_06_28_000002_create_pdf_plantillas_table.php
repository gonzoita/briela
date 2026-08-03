<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_plantillas', function (Blueprint $table) {
            $table->id();
            $table->string('modulo', 80);
            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();
            $table->longText('html');
            $table->json('config_tabla')->nullable();
            $table->enum('papel', ['a4', 'a5', 'letter', 'legal'])->default('a4');
            $table->enum('orientacion', ['portrait', 'landscape'])->default('portrait');
            $table->boolean('es_default')->default(false);
            $table->boolean('activa')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->foreign('creado_por')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['modulo', 'es_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_plantillas');
    }
};
