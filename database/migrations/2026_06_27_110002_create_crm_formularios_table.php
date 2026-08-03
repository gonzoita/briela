<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_formularios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200);
            $table->string('slug', 100)->unique();
            $table->unsignedBigInteger('etapa_id')->nullable();
            $table->foreign('etapa_id')->references('id')->on('crm_etapas')->nullOnDelete();
            $table->unsignedBigInteger('responsable_id')->nullable();
            $table->foreign('responsable_id')->references('id')->on('users')->nullOnDelete();
            $table->string('fuente', 100)->nullable();
            $table->json('campos');
            $table->string('titulo_formulario', 200)->default('Contáctanos');
            $table->text('descripcion_formulario')->nullable();
            $table->string('texto_boton', 100)->default('Enviar');
            $table->string('mensaje_exito', 300)->default('¡Gracias! Nos pondremos en contacto pronto.');
            $table->string('email_notificacion', 150)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_formularios');
    }
};
