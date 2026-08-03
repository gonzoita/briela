<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ensambles')) return;

        Schema::create('ensambles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_id')->constrained('plantillas_ensamble');
            $table->string('nombre', 150);
            $table->json('variables');
            $table->json('componentes_resultado');
            $table->decimal('precio_costo', 14, 2)->default(0);
            $table->decimal('precio_mayorista', 14, 2)->default(0);
            $table->decimal('precio_distribuidor', 14, 2)->default(0);
            $table->decimal('precio_cliente_final', 14, 2)->default(0);
            $table->decimal('margen_aplicado', 5, 2)->default(30);
            $table->foreignId('creado_por')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ensambles');
    }
};
