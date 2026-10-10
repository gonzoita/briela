<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los datos fiscales de un proveedor, los mismos que ya tiene un cliente.
 *
 * Un proveedor tenía un NIT en una caja de texto y nada más. Pero lo que decide cómo se le
 * compra está en su RUT: si es responsable de IVA o no —y por tanto si la factura trae IVA—,
 * si es autorretenedor o gran contribuyente, a qué actividad se dedica. Se guarda con la misma
 * forma que en `clientes` para que `LectorRutService`, `Fiscal` y la pantalla de datos fiscales
 * sirvan para los dos sin traducir nada.
 *
 * `nit` NO se toca: sigue siendo lo que se escribe a mano y lo que muestran las listas. Cuando
 * se lee un RUT se rellena también, para que nada de lo que lo lee se entere del cambio.
 *
 * Solo agrega, y todo nace nulo: un proveedor viejo se lee igual que antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            if (! Schema::hasColumn('proveedores', 'tipo_persona')) {
                $table->string('tipo_persona', 10)->nullable()->after('nit');
            }
            if (! Schema::hasColumn('proveedores', 'tipo_identificacion')) {
                $table->string('tipo_identificacion', 5)->nullable()->after('tipo_persona');
            }
            if (! Schema::hasColumn('proveedores', 'numero_identificacion')) {
                $table->string('numero_identificacion', 30)->nullable()->after('tipo_identificacion')->index();
            }
            if (! Schema::hasColumn('proveedores', 'digito_verificacion')) {
                $table->string('digito_verificacion', 1)->nullable()->after('numero_identificacion');
            }
            if (! Schema::hasColumn('proveedores', 'actividad_economica')) {
                $table->string('actividad_economica', 10)->nullable()->after('digito_verificacion');
            }
            if (! Schema::hasColumn('proveedores', 'responsabilidades_fiscales')) {
                $table->json('responsabilidades_fiscales')->nullable()->after('actividad_economica');
            }
            if (! Schema::hasColumn('proveedores', 'datos_rut')) {
                $table->json('datos_rut')->nullable()->after('responsabilidades_fiscales');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            foreach (['datos_rut', 'responsabilidades_fiscales', 'actividad_economica', 'digito_verificacion', 'numero_identificacion', 'tipo_identificacion', 'tipo_persona'] as $columna) {
                if (Schema::hasColumn('proveedores', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
