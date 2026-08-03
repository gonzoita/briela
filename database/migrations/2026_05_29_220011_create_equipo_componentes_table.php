<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('equipo_componentes')) {
            Schema::create('equipo_componentes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('equipo_id')->constrained('equipos_mantenimiento')->cascadeOnDelete();
                $table->string('nombre');
                $table->string('referencia')->nullable();
                $table->string('unidad')->default('und');
                $table->decimal('cantidad', 10, 2)->default(1);
                $table->text('descripcion')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipo_componentes');
    }
};
