<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los agentes que atienden por fuera: la web, WhatsApp, lo que venga.
 *
 * Hasta ahora había UN agente público, configurado con un par de claves sueltas en
 * `configuracion`. Eso alcanza para «alguien escribe y le contestamos», pero no para lo que la
 * empresa necesita de verdad: uno de ventas que atiende desconocidos con tono comercial, y otro
 * de posventa que atiende a un cliente que ya compró y pregunta por su pedido. Son dos trabajos
 * distintos, con dos alcances distintos, y meterlos en un solo prompt es la forma de que el
 * primero termine hablando de cartera.
 *
 * El campo que manda es `perfil`, y no es una etiqueta: decide **qué catálogo de consultas** ve
 * el agente. `publico` solo ve lo que ya es público por otro lado; `cliente` ve los datos de UNA
 * persona y solo después de que esa persona demuestre quién es.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agentes_ia')) {
            Schema::create('agentes_ia', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 120);
                $table->string('descripcion', 300)->nullable();
                $table->boolean('activo')->default(false);

                // publico  → atiende a quien no sabemos quién es.
                // cliente  → atiende a un cliente YA verificado, con sus propios datos.
                $table->string('perfil', 20)->default('publico');

                // Por dónde atiende: web, whatsapp.
                $table->json('canales')->nullable();

                // Lo que puede consultar, del catálogo de su perfil. Claves, nunca consultas.
                $table->json('herramientas')->nullable();

                $table->text('instrucciones')->nullable();
                $table->string('saludo', 500)->nullable();

                // Cuándo suelta la conversación: pedido por el cliente, no sabe, fuera de
                // horario, o cuando ya le asignó un asesor al lead.
                $table->json('escalamiento')->nullable();
                $table->json('horario')->nullable();

                $table->unsignedInteger('orden')->default(0);
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['activo', 'perfil']);
            });
        }

        // La conversación recuerda a quién atiende y si ya demostró quién es. Sin esto, cada
        // mensaje volvería a pedir el dato de verificación.
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversaciones', 'agente_id')) {
                $table->foreignId('agente_id')->nullable()->after('cliente_id')
                    ->constrained('agentes_ia')->nullOnDelete();
            }

            if (! Schema::hasColumn('whatsapp_conversaciones', 'verificado_at')) {
                // Cuándo el cliente demostró ser quien dice. Null = todavía es un desconocido,
                // por más que su número esté en una ficha.
                $table->timestamp('verificado_at')->nullable()->after('agente_id');
            }

            if (! Schema::hasColumn('whatsapp_conversaciones', 'escalada_at')) {
                // Cuándo la tomó una persona. Desde ahí el agente no vuelve a contestar: dos
                // voces en la misma conversación es peor que ninguna.
                $table->timestamp('escalada_at')->nullable()->after('verificado_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            foreach (['agente_id', 'verificado_at', 'escalada_at'] as $col) {
                if (Schema::hasColumn('whatsapp_conversaciones', $col)) {
                    $col === 'agente_id' ? $table->dropConstrainedForeignId($col) : $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('agentes_ia');
    }
};
