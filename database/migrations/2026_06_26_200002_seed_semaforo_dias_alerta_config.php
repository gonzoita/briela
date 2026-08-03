<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (\DB::table('configuraciones')->where('clave', 'semaforo_dias_alerta')->doesntExist()) {
            \DB::table('configuraciones')->insert([
                'clave'       => 'semaforo_dias_alerta',
                'valor'       => '5',
                'tipo'        => 'integer',
                'grupo'       => 'alertas',
                'etiqueta'    => 'Días de alerta en semáforo de cartera',
                'descripcion' => 'Cuotas que vencen en este plazo se marcan en amarillo',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        \DB::table('configuraciones')->where('clave', 'semaforo_dias_alerta')->delete();
    }
};
