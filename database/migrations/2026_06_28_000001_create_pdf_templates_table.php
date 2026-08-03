<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_templates', function (Blueprint $table) {
            $table->id();
            $table->string('modulo', 50)->unique();
            $table->string('nombre', 200)->default('Plantilla por defecto');
            // Colores
            $table->string('color_primario', 7)->default('#0A4283');
            $table->string('color_secundario', 7)->default('#F8FAFC');
            $table->string('color_texto', 7)->default('#1A1A1A');
            $table->string('fuente', 50)->default('Arial');
            $table->integer('tamanio_fuente')->default(10);
            // Logo
            $table->string('logo_url', 500)->nullable();
            $table->boolean('mostrar_logo')->default(true);
            $table->enum('logo_posicion', ['izquierda', 'centro', 'derecha'])->default('izquierda');
            $table->integer('logo_ancho')->default(120);
            // Encabezado
            $table->boolean('mostrar_encabezado')->default(true);
            $table->string('encabezado_titulo', 200)->nullable();
            $table->text('encabezado_subtitulo')->nullable();
            $table->text('encabezado_html')->nullable();
            // Pie de página
            $table->boolean('mostrar_pie')->default(true);
            $table->text('pie_html')->nullable();
            $table->string('pie_texto', 300)->nullable();
            // Secciones y config avanzada
            $table->json('secciones_visibles')->nullable();
            $table->json('config_avanzada')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_templates');
    }
};
