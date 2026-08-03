<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operario_turnos_config')) return;

        Schema::create('operario_turnos_config', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operario_turnos_config');
    }
};
