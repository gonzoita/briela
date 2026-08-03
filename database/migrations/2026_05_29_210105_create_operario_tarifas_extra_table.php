<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operario_tarifas_extra')) return;

        Schema::create('operario_tarifas_extra', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['diurna', 'nocturna', 'dominical']);
            $table->decimal('valor_hora', 15, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operario_tarifas_extra');
    }
};
