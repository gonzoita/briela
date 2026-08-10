<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zona horaria por sede.
 *
 * Una empresa con sedes en ciudades distintas necesita que cada una vea su propia
 * hora: la de un turno de producción, la de una entrega, la de un registro de
 * asistencia. Antes la zona estaba fija en la configuración de la aplicación.
 *
 * La **hora global del sistema** —la que se usa para guardar en la base— es la de
 * la sede principal, y se aplica desde App\Support\HoraSistema.
 *
 * Ojo con cambiar la zona de la sede principal en una instalación que ya viene
 * operando: las fechas guardadas no se convierten, se reinterpretan. Un registro
 * de las 8:00 pasa a leerse como 8:00 de la zona nueva. Por eso el valor por
 * defecto conserva el que tenía la aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sedes', 'zona_horaria')) {
            return;
        }

        Schema::table('sedes', function (Blueprint $table) {
            $table->string('zona_horaria', 64)
                ->default('America/Bogota')
                ->after('ciudad');
        });
    }

    public function down(): void
    {
        // Migración hacia adelante: quitar la columna dejaría al sistema sin saber
        // en qué hora vive cada sede. Ver docs/BRIELA-PLAN.md, reglas del producto.
    }
};
