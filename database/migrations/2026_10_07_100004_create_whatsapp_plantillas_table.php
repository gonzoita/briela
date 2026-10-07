<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las plantillas aprobadas de WhatsApp.
 *
 * **Por qué hacen falta para vender el módulo.** Meta solo deja escribir texto libre a quien
 * nos escribió en las últimas 24 horas. Pasado ese rato —o para iniciar una conversación en
 * frío— el único mensaje que sale es una **plantilla aprobada por Meta**. Sin esto, el sistema
 * no puede avisar que una cotización está lista, que la orden salió a despacho, ni que hay una
 * factura por vencer: justo los avisos por los que una empresa paga un ERP con WhatsApp.
 *
 * No se escriben acá: se crean y se aprueban en Meta, y esta tabla es **el espejo local** de lo
 * que allá existe (`GET /{waba_id}/message_templates`). Guardar el espejo en vez de preguntar
 * cada vez es lo que permite pintar un selector sin una llamada a Meta por cada apertura de la
 * bandeja, y saber qué variables pide cada una antes de intentar mandarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_plantillas')) {
            return;
        }

        Schema::create('whatsapp_plantillas', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre');                   // el nombre exacto en Meta
            $tabla->string('idioma', 10)->default('es');
            $tabla->string('categoria', 30)->nullable(); // MARKETING | UTILITY | AUTHENTICATION
            $tabla->string('estado', 30)->nullable();    // APPROVED | PENDING | REJECTED | PAUSED
            $tabla->string('externo_id')->nullable();

            $tabla->text('encabezado')->nullable();
            $tabla->text('cuerpo')->nullable();
            $tabla->text('pie')->nullable();

            // Cuántas variables pide el cuerpo ({{1}}, {{2}}...). Se calcula al sincronizar
            // para no tener que leer el texto cada vez que se arma el formulario.
            $tabla->unsignedSmallInteger('variables')->default(0);

            // Lo que devolvió Meta, tal cual. Sirve para no perder nada de lo que todavía no
            // sabemos leer —botones, archivos de cabecera— y poder usarlo después sin pedir
            // otra sincronización.
            $tabla->json('componentes')->nullable();

            $tabla->timestamp('sincronizada_at')->nullable();
            $tabla->timestamps();

            // La misma plantilla existe en varios idiomas, y son mensajes distintos.
            $tabla->unique(['nombre', 'idioma']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_plantillas');
    }
};
