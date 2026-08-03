<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_tareas')) {
            Schema::create('crm_tareas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_id');
                $table->foreign('lead_id')->references('id')->on('crm_leads')->cascadeOnDelete();
                $table->unsignedBigInteger('responsable_id')->nullable();
                $table->foreign('responsable_id')->references('id')->on('users')->nullOnDelete();
                $table->string('titulo', 200);
                $table->text('descripcion')->nullable();
                $table->enum('tipo', ['llamada', 'email', 'reunion', 'seguimiento', 'otro'])->default('seguimiento');
                $table->date('fecha_vencimiento')->nullable();
                $table->boolean('completada')->default(false);
                $table->timestamp('completada_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tareas');
    }
};
