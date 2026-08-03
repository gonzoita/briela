<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Sedes de la empresa. Una sede puede ser solo de ventas, solo fábrica, o
    // ambas — por eso son dos banderas independientes y no un "tipo" rígido.
    public function up(): void
    {
        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo', 10)->unique(); // BOG, CAL, CUC — se usa en los prefijos
            $table->boolean('tiene_ventas')->default(true);
            $table->boolean('tiene_produccion')->default(false);
            $table->boolean('es_principal')->default(false);
            $table->string('nit')->nullable();
            $table->string('direccion')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        // Sedes reales de Interfrigo. Bogotá queda como principal: todos los
        // datos que ya existen en el sistema se le asignan a ella.
        DB::table('sedes')->insert([
            [
                'nombre'           => 'Bogotá',
                'codigo'           => 'BOG',
                'tiene_ventas'     => true,
                'tiene_produccion' => true,
                'es_principal'     => true,
                'ciudad'           => 'Bogotá',
                'activa'           => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'nombre'           => 'Cali',
                'codigo'           => 'CAL',
                'tiene_ventas'     => true,
                'tiene_produccion' => true,
                'es_principal'     => false,
                'ciudad'           => 'Cali',
                'activa'           => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'nombre'           => 'Cúcuta',
                'codigo'           => 'CUC',
                'tiene_ventas'     => true,
                'tiene_produccion' => false,
                'es_principal'     => false,
                'ciudad'           => 'Cúcuta',
                'activa'           => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sedes');
    }
};
