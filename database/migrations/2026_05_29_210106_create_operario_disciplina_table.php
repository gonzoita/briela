<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operario_disciplina')) return;

        Schema::create('operario_disciplina', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operario_id');
            $table->foreign('operario_id')->references('id')->on('operarios')->cascadeOnDelete();
            $table->enum('tipo', ['falla', 'memorando', 'llamado_atencion']);
            $table->text('descripcion');
            $table->date('fecha');
            $table->unsignedBigInteger('creado_por');
            $table->foreign('creado_por')->references('id')->on('users');
            $table->boolean('firmado')->default(false);
            $table->timestamp('firmado_at')->nullable();
            $table->decimal('penalizacion_valor', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operario_disciplina');
    }
};
