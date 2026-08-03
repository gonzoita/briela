<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_leads')) {
            Schema::create('crm_leads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('etapa_id');
                $table->foreign('etapa_id')->references('id')->on('crm_etapas')->cascadeOnDelete();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->foreign('cliente_id')->references('id')->on('clientes')->nullOnDelete();
                $table->unsignedBigInteger('responsable_id')->nullable();
                $table->foreign('responsable_id')->references('id')->on('users')->nullOnDelete();
                $table->string('titulo', 200);
                $table->string('nombre_contacto', 200)->nullable();
                $table->string('email_contacto', 150)->nullable();
                $table->string('telefono_contacto', 30)->nullable();
                $table->string('empresa_contacto', 200)->nullable();
                $table->text('descripcion')->nullable();
                $table->string('fuente', 100)->nullable();
                $table->enum('estado', ['activo', 'ganado', 'perdido'])->default('activo');
                $table->text('motivo_cierre')->nullable();
                $table->timestamp('fecha_cierre')->nullable();
                $table->integer('orden_en_etapa')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
