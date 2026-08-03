<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'semaforo_dias_alerta'],
            [
                'valor'       => '7',
                'tipo'        => 'integer',
                'grupo'       => 'alertas',
                'etiqueta'    => 'Días de alerta en cartera',
                'descripcion' => 'Cuotas que vencen dentro de este plazo se marcan en amarillo',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('configuraciones')->where('clave', 'semaforo_dias_alerta')->delete();
    }
};
