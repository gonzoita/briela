<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los gráficos que la empresa arma para sus tableros.
 *
 * Guarda la PREGUNTA, no el resultado: qué fuente, qué se mide, cómo se agrupa y con qué
 * filtros. Los números se calculan al abrir la pantalla — un gráfico con datos congelados
 * envejece sin avisar, y nadie se da cuenta hasta que toma una decisión con él.
 *
 * Fuente, medida, dimensión y filtros son **claves de un catálogo en código**, nunca columnas
 * que llegan del navegador: es la misma regla de `App\Support\Orden`, y es lo que impide que
 * armar un gráfico se convierta en escribir SQL desde el navegador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('graficos_dashboard')) {
            return;
        }

        Schema::create('graficos_dashboard', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 120);
            // A qué tablero pertenece: el gráfico se ve donde el dato tiene sentido.
            $table->string('modulo', 40);
            $table->string('fuente', 40);
            $table->string('tipo', 20)->default('barra');
            $table->string('medida', 20)->default('conteo');
            $table->string('dimension', 40)->nullable();
            $table->json('filtros')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['modulo', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graficos_dashboard');
    }
};
