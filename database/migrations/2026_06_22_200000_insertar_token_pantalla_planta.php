<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'pantalla_planta_token'],
            [
                'clave'    => 'pantalla_planta_token',
                'valor'    => Str::random(32),
                'tipo'     => 'string',
                'grupo'    => 'general',
                'etiqueta' => 'Token de acceso pantalla de planta',
            ]
        );
    }

    public function down(): void
    {
        DB::table('configuraciones')->where('clave', 'pantalla_planta_token')->delete();
    }
};
