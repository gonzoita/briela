<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatizaciones: lo que Briela hace sola cuando pasa algo, o a una hora.
 *
 * El flujo es un DATO, no código: un grafo JSON (`grafo`) con un disparador, condiciones, acciones
 * y esperas. El mismo motor lo ejecuta venga de la lista o, más adelante, de un lienzo.
 *
 * - `automatizaciones`: el flujo. `disparador_tipo` y `disparador_clave` repiten lo que ya dice el
 *   grafo para poder buscar «qué automatizaciones escuchan este evento» sin abrir cada JSON.
 * - `automatizacion_ejecuciones`: una corrida. Guarda **su propia copia del grafo**: editar la
 *   automatización no cambia lo que ya está esperando. Una espera no deja nada corriendo: queda
 *   `esperando` con su `reanudar_at` y la reanuda el cron.
 * - `automatizacion_pasos`: la caja negra de cada corrida, nodo por nodo.
 *
 * Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automatizaciones')) {
            Schema::create('automatizaciones', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 150);
                $table->string('descripcion', 400)->nullable();
                $table->boolean('activa')->default(false);
                $table->json('grafo');
                $table->unsignedInteger('version')->default(1);

                // evento | horario | manual
                $table->string('disparador_tipo', 12)->default('manual');
                $table->string('disparador_clave', 60)->nullable();

                // Frenos: un bucle mal armado no puede llenar la base ni gastar sin parar.
                $table->unsignedInteger('max_por_hora')->default(60);
                $table->unsignedTinyInteger('fallas_seguidas')->default(0);

                // Quién la armó: sus permisos son el techo de lo que la automatización puede hacer.
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('ultima_ejecucion_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['activa', 'disparador_tipo', 'disparador_clave'], 'automatizacion_busqueda');
            });
        }

        if (! Schema::hasTable('automatizacion_ejecuciones')) {
            Schema::create('automatizacion_ejecuciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('automatizacion_id')->constrained('automatizaciones')->cascadeOnDelete();
                // en_cola | corriendo | esperando | terminada | fallida | cancelada
                $table->string('estado', 12)->default('en_cola');
                // evento | horario | manual | prueba
                $table->string('origen', 10)->default('manual');
                $table->string('evento', 60)->nullable();
                $table->json('grafo');
                // Lo que se sabía del documento cuando pasó el evento; se refresca al salir de una espera.
                $table->json('contexto')->nullable();
                $table->string('entidad_tipo', 20)->nullable();
                $table->unsignedBigInteger('entidad_id')->nullable();
                // Cuántas automatizaciones atrás nació esta (anti-bucles).
                $table->unsignedTinyInteger('profundidad')->default(0);
                $table->string('nodo_actual', 40)->nullable();
                $table->timestamp('reanudar_at')->nullable();
                $table->text('error')->nullable();
                $table->string('clave_idempotencia', 150)->nullable()->unique();
                $table->foreignId('disparado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('iniciada_at')->nullable();
                $table->timestamp('terminada_at')->nullable();
                $table->timestamps();

                $table->index(['estado', 'reanudar_at']);
                $table->index(['automatizacion_id', 'id']);
            });
        }

        if (! Schema::hasTable('automatizacion_pasos')) {
            Schema::create('automatizacion_pasos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ejecucion_id')->constrained('automatizacion_ejecuciones')->cascadeOnDelete();
                $table->unsignedInteger('orden');
                $table->string('nodo_id', 40);
                $table->string('tipo', 12);
                // ok | omitido | espera | error
                $table->string('estado', 10);
                $table->text('resumen')->nullable();
                $table->unsignedInteger('ms')->default(0);
                $table->timestamp('created_at')->nullable();

                $table->index(['ejecucion_id', 'orden']);
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }
};
