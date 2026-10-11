<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El país de cada sede, cliente y proveedor, y la moneda en que escribe sus documentos.
 *
 *  - `sedes.pais` / `sedes.moneda`: la sede de Panamá cotiza en dólares aunque la casa lleve
 *    sus cuentas en pesos. Todo lo existente queda en Colombia, que es lo que ya era.
 *  - `clientes.pais` / `proveedores.pais`: null significa «el país de la casa». Así lo que ya
 *    estaba guardado no cambia ni una fila.
 *  - `cotizaciones.moneda` pasa de `enum('COP','USD','EUR')` a texto de tres letras: con un
 *    enum, cada moneda nueva exigía una migración sobre bases de clientes que no vemos.
 *
 * Solo agrega y ensancha: ninguna columna se renombra ni se elimina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            if (! Schema::hasColumn('sedes', 'pais')) {
                $table->string('pais', 2)->default('CO')->after('ciudad');
            }
            if (! Schema::hasColumn('sedes', 'moneda')) {
                // Null: la sede escribe sus documentos en la moneda de su país.
                $table->string('moneda', 3)->nullable()->after('pais');
            }
        });

        Schema::table('clientes', function (Blueprint $table) {
            if (! Schema::hasColumn('clientes', 'pais')) {
                $table->string('pais', 2)->nullable()->after('ciudad');
            }
        });

        Schema::table('proveedores', function (Blueprint $table) {
            if (! Schema::hasColumn('proveedores', 'pais')) {
                $table->string('pais', 2)->nullable()->after('ciudad');
            }
        });

        if (Schema::hasColumn('cotizaciones', 'moneda') && Schema::getColumnType('cotizaciones', 'moneda') === 'enum') {
            DB::statement("ALTER TABLE cotizaciones MODIFY moneda VARCHAR(3) NOT NULL DEFAULT 'COP'");
        }

    }

    public function down(): void
    {
        Schema::table('sedes', fn (Blueprint $t) => $t->dropColumn(['pais', 'moneda']));
        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn('pais'));
        Schema::table('proveedores', fn (Blueprint $t) => $t->dropColumn('pais'));
    }
};
