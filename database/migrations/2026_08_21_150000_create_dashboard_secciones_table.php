<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las secciones que la empresa arma en su tablero de inicio.
 *
 * El tablero traía métricas fijas, decididas al escribir el código. Sirven para el fabricante
 * que se tuvo en mente y le sobran o le faltan a cualquier otro — y Briela se instala en
 * empresas distintas, así que «lo que hay que mirar hoy» no lo puede decidir el programa.
 *
 * **La decisión que evita duplicar el motor de gráficos:** una sección no guarda gráficos. Una
 * sección **es un módulo** para el sistema de gráficos que ya existe, y su `clave` es lo que
 * `graficos_dashboard.modulo` va a guardar. Así la sección «Producción» del tablero usa el
 * mismo constructor, el mismo catálogo de fuentes y el mismo dibujo que los tableros de
 * Cotizaciones o Financiero: aquí solo se agrega el agrupar y el ordenar.
 *
 * La clave se genera del título y no se vuelve a tocar: si se renombra la sección, sus gráficos
 * la siguen porque cuelgan de la clave, no del nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_secciones')) {
            return;
        }

        Schema::create('dashboard_secciones', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 80);

            // Lo que queda guardado en `graficos_dashboard.modulo`. Única y estable: sobrevive
            // a que le cambien el nombre a la sección.
            $table->string('clave', 60)->unique();

            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activa')->default(true);

            $table->unsignedBigInteger('creado_por')->nullable();
            $table->foreign('creado_por')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_secciones');
    }
};
