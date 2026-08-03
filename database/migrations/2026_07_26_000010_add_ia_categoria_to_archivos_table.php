<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Amplía el ENUM de categoría para poder guardar las imágenes generadas
    // con IA dentro de Multimedia, junto al resto de archivos.
    public function up(): void
    {
        DB::statement("ALTER TABLE archivos MODIFY categoria ENUM('plano','foto_calidad','documento','otro','rrss','ia') NOT NULL DEFAULT 'otro'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE archivos MODIFY categoria ENUM('plano','foto_calidad','documento','otro','rrss') NOT NULL DEFAULT 'otro'");
    }
};
