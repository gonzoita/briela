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
            $table->string('codigo', 10)->unique(); // se usa en los prefijos de los documentos
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

        // Una sola sede, la principal, para que la instalación nazca usable.
        // Cada empresa la renombra y agrega las suyas desde
        // Configuración → Organización. En el sistema de origen aquí se sembraban
        // las tres sedes reales de esa empresa.
        DB::table('sedes')->insert([
            [
                'nombre'           => 'Principal',
                'codigo'           => 'PRI',
                'tiene_ventas'     => true,
                'tiene_produccion' => true,
                'es_principal'     => true,
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
