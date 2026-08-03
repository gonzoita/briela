<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_numeros')) return;

        Schema::create('whatsapp_numeros', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // ej. "Renier Dominguez", "SGI Central"
            $table->string('numero_telefono'); // formato +573XXXXXXXXX
            $table->string('phone_number_id')->unique(); // ID que da Meta
            $table->string('rol')->default('asesor'); // central | asesor
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_numeros');
    }
};
