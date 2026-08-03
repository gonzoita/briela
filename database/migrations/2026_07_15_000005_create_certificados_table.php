<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('certificados')) return;

        Schema::create('certificados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->unique()->constrained('inscripciones')->cascadeOnDelete();
            $table->string('codigo_verificacion')->unique();
            $table->string('pdf_path')->nullable();
            $table->dateTime('emitido_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificados');
    }
};
