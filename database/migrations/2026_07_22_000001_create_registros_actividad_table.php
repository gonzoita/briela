<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_actividad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 30); // creado | actualizado | eliminado | movido | otro
            $table->string('modelo');     // nombre corto del modelo, ej. "Cotizacion"
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->string('descripcion', 500);
            $table->json('cambios')->nullable(); // ['campo' => ['antes' => x, 'despues' => y]]
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['modelo', 'modelo_id']);
            $table->index('user_id');
            $table->index('accion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_actividad');
    }
};
