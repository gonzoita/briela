<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las conversaciones que llegan por las redes: mensajes directos y comentarios de Instagram y
 * Facebook.
 *
 * **Por qué no van en `whatsapp_conversaciones`.** Esa tabla es de WhatsApp y así se llama; un
 * comentario de Instagram metido ahí obligaría a renombrarla, y renombrar columnas está
 * prohibido en un producto instalado (ver «Reglas del producto instalable»). Tampoco se
 * duplica la pantalla: la bandeja es UNA, y cada canal entra por su adaptador
 * (`App\Services\Bandeja`). Lo que se repite es el almacenamiento, que de verdad es distinto:
 * WhatsApp identifica a la gente por número de teléfono y Meta por un identificador por página.
 *
 * **Un comentario también es una conversación.** El hilo de un comentario y sus respuestas se
 * atiende igual que un mensaje directo —se lee, se contesta, se asigna—, así que vive en la
 * misma tabla con otro `canal`. `contexto` guarda de qué publicación salió, que es lo que la
 * persona necesita ver para entender qué le están preguntando.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bandeja_conversaciones')) {
            return;
        }

        Schema::create('bandeja_conversaciones', function (Blueprint $tabla) {
            $tabla->id();

            // instagram_dm | facebook_dm | instagram_comentario | facebook_comentario.
            // Cadena y no enum: un canal nuevo no debería necesitar un ALTER TABLE en la base
            // de cada cliente. Lo válido lo declara `App\Support\Canales`.
            $tabla->string('canal', 40);

            // Por dónde entró y por dónde se contesta. Si la cuenta se borra del todo, la
            // conversación se conserva —es historial— y queda sin poder responderse, que es
            // exactamente la verdad.
            $tabla->foreignId('cuenta_rrss_id')->nullable()->constrained('cuentas_rrss')->nullOnDelete();

            // El identificador del otro lado: el PSID de quien escribe en un directo, o el id
            // del comentario raíz cuando el hilo es de comentarios.
            $tabla->string('externo_id');
            $tabla->string('nombre_contacto')->nullable();
            $tabla->string('usuario_externo')->nullable(); // @usuario, cuando la red lo da

            // De qué publicación salió el comentario: id, enlace y un pedazo del texto. Sin
            // esto, «¿cuánto vale?» en la bandeja no se puede responder.
            $tabla->json('contexto')->nullable();

            $tabla->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $tabla->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $tabla->foreignId('asignado_a')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('agente_id')->nullable()->constrained('agentes_ia')->nullOnDelete();

            $tabla->timestamp('ultimo_mensaje_at')->nullable();
            $tabla->timestamp('ultimo_entrante_at')->nullable();
            $tabla->boolean('leido')->default(true);
            $tabla->timestamp('escalada_at')->nullable();
            $tabla->timestamp('archivada_at')->nullable();
            $tabla->timestamps();

            // El mismo identificador externo puede repetirse entre canales y entre cuentas: la
            // persona que escribe al Instagram de la empresa y al Facebook son dos hilos.
            $tabla->unique(['canal', 'cuenta_rrss_id', 'externo_id'], 'bandeja_conv_externa_unica');
            $tabla->index(['archivada_at', 'ultimo_mensaje_at'], 'bandeja_conv_lista_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandeja_conversaciones');
    }
};
