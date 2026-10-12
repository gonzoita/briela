<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El libro local del consumo de IA: una fila por llamada.
 *
 * El costo y el cobro los lleva el superadmin; esto **no** calcula dinero. Sirve para que la
 * instalación pueda responder «¿en qué gasto la IA?»: cuántos tokens, con qué modelo, para qué
 * módulo y, cuando lo hizo un agente, cuál. Se llena en un solo sitio, `IaService::llamar()`.
 *
 * Sin llaves foráneas a propósito: un agente se puede borrar y su historial de consumo debe
 * seguir ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ia_consumo')) {
            return;
        }

        Schema::create('ia_consumo', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');

            // asistente | agentes. Es la misma etiqueta que viaja al superadmin.
            $table->string('origen', 20)->default('asistente');
            $table->unsignedBigInteger('agente_id')->nullable();
            $table->unsignedBigInteger('ejecucion_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('tipo', 12)->default('texto');
            $table->string('modelo', 100)->nullable();
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_salida')->default(0);
            $table->unsignedInteger('ms')->default(0);
            $table->boolean('por_proxy')->default(true);
            $table->boolean('ok')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index(['fecha', 'origen']);
            $table->index(['agente_id', 'fecha']);
        });
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }
};
