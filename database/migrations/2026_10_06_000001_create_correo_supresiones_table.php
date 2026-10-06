<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quién no se le vuelve a escribir desde esta instalación.
 *
 * Llega del panel de Briela (rebotes, quejas y bajas que avisa el proveedor) y la usan los
 * envíos masivos para no intentar siquiera. El panel tiene su propia lista y no envía a
 * esas direcciones de todas formas; esta copia sirve para mostrarlas aquí y para no gastar
 * una tanda en direcciones muertas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('correo_supresiones')) {
            return;
        }

        Schema::create('correo_supresiones', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            // rebote_duro, queja, baja.
            $table->string('motivo', 20);
            $table->string('detalle', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correo_supresiones');
    }
};
