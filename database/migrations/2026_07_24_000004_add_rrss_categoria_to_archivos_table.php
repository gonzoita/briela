<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // La columna "categoria" de archivos es un ENUM a nivel de MySQL — hay que
    // ampliarlo para poder guardar imágenes del módulo RRSS con categoria='rrss'.
    public function up(): void
    {
        DB::statement("ALTER TABLE archivos MODIFY categoria ENUM('plano','foto_calidad','documento','otro','rrss') NOT NULL DEFAULT 'otro'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE archivos MODIFY categoria ENUM('plano','foto_calidad','documento','otro') NOT NULL DEFAULT 'otro'");
    }
};
