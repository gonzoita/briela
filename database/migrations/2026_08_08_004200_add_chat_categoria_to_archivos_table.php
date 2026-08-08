<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La columna "categoria" de archivos es un ENUM de MySQL: hay que ampliarlo
 * para poder guardar los adjuntos del chat con categoria='chat'.
 *
 * Se marcan aparte —en vez de meterlos en 'otro'— para poder distinguirlos en
 * Multimedia: un archivo que alguien mandó por chat no es lo mismo que un
 * documento formal de una OP, y mezclarlos ensucia el módulo.
 *
 * Mismo patrón que la migración que agregó 'rrss'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE archivos MODIFY categoria ENUM('plano','foto_calidad','documento','otro','rrss','chat') NOT NULL DEFAULT 'otro'");
    }

    public function down(): void
    {
        DB::statement("UPDATE archivos SET categoria = 'otro' WHERE categoria = 'chat'");
        DB::statement("ALTER TABLE archivos MODIFY categoria ENUM('plano','foto_calidad','documento','otro','rrss') NOT NULL DEFAULT 'otro'");
    }
};
