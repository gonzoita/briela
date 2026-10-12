<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta saber de un número vinculado por Embedded Signup.
 *
 * Solo columnas nuevas y nulables: la migración corre sobre cualquier versión anterior y no toca
 * lo que ya hay. Los números que se cargaron a mano quedan con todo en nulo y siguen funcionando
 * igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numeros', function (Blueprint $tabla) {
            // La cuenta de WhatsApp Business a la que pertenece la línea.
            if (! Schema::hasColumn('whatsapp_numeros', 'waba_id')) {
                $tabla->string('waba_id')->nullable()->after('phone_number_id');
            }

            // El número sigue vivo en la app WhatsApp Business del celular: lo que se escribe
            // allá llega a la bandeja como eco, y esta línea no se puede migrar a otro BSP sin
            // desvincularla primero.
            if (! Schema::hasColumn('whatsapp_numeros', 'coexistencia')) {
                $tabla->boolean('coexistencia')->default(false)->after('waba_id');
            }

            // El PIN de verificación en dos pasos con el que se registró en la API en la nube
            // (cifrado en el modelo). Sin él, volver a registrar el número exige pasar por el
            // administrador de WhatsApp.
            if (! Schema::hasColumn('whatsapp_numeros', 'pin_registro')) {
                $tabla->text('pin_registro')->nullable()->after('coexistencia');
            }

            if (! Schema::hasColumn('whatsapp_numeros', 'vinculado_at')) {
                $tabla->timestamp('vinculado_at')->nullable()->after('pin_registro');
            }
        });
    }

    public function down(): void
    {
        // Nada: una migración de un producto instalado no borra columnas.
    }
};
