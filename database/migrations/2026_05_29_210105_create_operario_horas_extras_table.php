<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operario_horas_extras')) return;

        Schema::create('operario_horas_extras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operario_id');
            $table->foreign('operario_id')->references('id')->on('operarios')->cascadeOnDelete();
            $table->date('fecha');
            $table->enum('tipo', ['diurna', 'nocturna', 'dominical']);
            $table->decimal('horas', 5, 2);
            $table->unsignedBigInteger('aprobado_por')->nullable();
            $table->foreign('aprobado_por')->references('id')->on('users')->nullOnDelete();
            $table->text('observacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operario_horas_extras');
    }
};
