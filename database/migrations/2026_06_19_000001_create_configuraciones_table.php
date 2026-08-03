<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('configuraciones')) {
            return;
        }

        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->text('valor')->nullable();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        DB::table('configuraciones')->insert([
            [
                'clave'       => 'alerta_op_rojo_dias',
                'valor'       => '0',
                'descripcion' => 'Días antes de fecha entrega para mostrar alerta roja en OPs',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'clave'       => 'alerta_op_amarillo_dias',
                'valor'       => '2',
                'descripcion' => 'Días antes de fecha entrega para mostrar alerta amarilla en OPs',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
