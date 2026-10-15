<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las tres tasas de una importación: la de la factura (`tasa`, la que ya existía), la de la
 * declaración de importación —con la que la aduana liquida y con la que sale el costo puesto en
 * bodega— y la de cada pago al proveedor. La diferencia entre la segunda y la tercera es la
 * diferencia en cambio: ganancia o pérdida financiera, que no mueve el costo del producto.
 *
 * Aditiva: una importación sin tasa de nacionalización sigue calculando con la de la factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('importaciones', 'tasa_nacionalizacion')) {
                $table->decimal('tasa_nacionalizacion', 18, 8)->nullable()->after('tasa_fecha');
            }
            if (! Schema::hasColumn('importaciones', 'tasa_nacionalizacion_fecha')) {
                $table->date('tasa_nacionalizacion_fecha')->nullable()->after('tasa_nacionalizacion');
            }
        });

        if (! Schema::hasTable('importacion_pagos')) {
            Schema::create('importacion_pagos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('importacion_id')->constrained('importaciones')->cascadeOnDelete();
                $table->date('fecha');
                $table->string('concepto', 20)->default('pago'); // anticipo, saldo, pago
                // En la moneda de la factura: lo que salió hacia el proveedor.
                $table->decimal('valor', 18, 2);
                // Moneda base por 1 unidad de la moneda de la factura, el día del pago.
                $table->decimal('tasa', 18, 8);
                $table->string('referencia', 120)->nullable();
                $table->string('notas', 500)->nullable();
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // A propósito vacío: los pagos registrados son datos de un cliente al que no tenemos acceso.
    }
};
