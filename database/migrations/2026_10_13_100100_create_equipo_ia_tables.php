<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El equipo de IA: agentes internos que trabajan para la empresa.
 *
 * No es `agentes_ia`: esos atienden a clientes por fuera. Estos leen los datos del negocio y
 * dejan avisos, informes y **propuestas** que una persona aprueba. Por eso van aparte: mezclar
 * en una tabla a quien habla con desconocidos y a quien lee la cartera sería pedir un descuido.
 *
 * - `cargos_ia`: la plantilla de un puesto (instrucciones base, herramientas, rol de permisos).
 * - `agentes_internos`: quién lo ocupa, con su modelo, su prompt y su horario.
 * - `prompt_versiones_ia`: cada cambio de instrucciones, para compararlo y deshacerlo.
 * - `agente_ejecuciones` / `agente_pasos`: cada corrida y su caja negra.
 * - `agente_propuestas`: la cola de aprobación. Un agente nunca escribe en el negocio: propone.
 *
 * Solo agrega. Nada existente se renombra ni se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cargos_ia')) {
            Schema::create('cargos_ia', function (Blueprint $table) {
                $table->id();
                // Los cargos de fábrica tienen clave estable: una actualización puede ponerlos al día.
                $table->string('clave', 60)->nullable()->unique();
                $table->string('nombre', 120);
                $table->string('descripcion', 300)->nullable();
                $table->string('area', 40)->nullable();
                $table->string('color', 20)->nullable();
                $table->string('avatar', 40)->nullable();

                $table->text('instrucciones')->nullable();
                // Claves del catálogo de herramientas: el techo de cualquier agente de este cargo.
                $table->json('herramientas')->nullable();
                $table->foreignId('rol_id')->nullable()->constrained('roles')->nullOnDelete();

                // economico | potente: solo el punto de partida al elegir el modelo.
                $table->string('nivel_modelo', 12)->default('economico');
                $table->unsignedTinyInteger('max_pasos')->default(8);
                $table->unsignedInteger('max_tokens')->default(1500);

                $table->boolean('de_fabrica')->default(false);
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agentes_internos')) {
            Schema::create('agentes_internos', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 60)->nullable()->unique();
                $table->foreignId('cargo_id')->nullable()->constrained('cargos_ia')->nullOnDelete();
                $table->string('nombre', 120);
                $table->string('descripcion', 300)->nullable();
                $table->string('avatar', 40)->nullable();
                $table->string('color', 20)->nullable();

                // Se SUMAN a las del cargo.
                $table->text('instrucciones')->nullable();
                // La tarea que se le pone en cada corrida programada.
                $table->text('tarea')->nullable();
                // Subconjunto de las herramientas del cargo; nulo = todas las del cargo.
                $table->json('herramientas')->nullable();

                // Modelo propio (anula el nivel), su respaldo y el nivel.
                $table->string('modelo', 100)->nullable();
                $table->string('modelo_respaldo', 100)->nullable();
                $table->string('nivel_modelo', 12)->nullable();
                $table->decimal('temperatura', 3, 2)->nullable();
                $table->unsignedInteger('max_tokens')->nullable();
                $table->unsignedTinyInteger('max_pasos')->nullable();
                // Freno técnico por corrida (entrada + salida), no de dinero.
                $table->unsignedInteger('max_tokens_ejecucion')->default(60000);

                // {tipo: manual|diaria|semanal, hora: "06:30", dias: [1..7]}
                $table->json('frecuencia')->nullable();
                // solo_lectura | propone
                $table->string('modo', 20)->default('propone');
                // IDs de usuario a quienes avisar; vacío = quienes puedan aprobar.
                $table->json('avisar_a')->nullable();

                $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
                $table->boolean('activo')->default(false);
                $table->timestamp('ultima_ejecucion_at')->nullable();
                $table->unsignedInteger('orden')->default(0);
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['activo', 'orden']);
            });
        }

        if (! Schema::hasTable('prompt_versiones_ia')) {
            Schema::create('prompt_versiones_ia', function (Blueprint $table) {
                $table->id();
                // agente | cargo
                $table->string('origen_tipo', 10);
                $table->unsignedBigInteger('origen_id');
                $table->unsignedInteger('version');
                $table->text('instrucciones')->nullable();
                $table->text('tarea')->nullable();
                $table->string('nota', 200)->nullable();
                $table->foreignId('cambiado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->index(['origen_tipo', 'origen_id', 'version']);
            });
        }

        if (! Schema::hasTable('agente_ejecuciones')) {
            Schema::create('agente_ejecuciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agente_id')->constrained('agentes_internos')->cascadeOnDelete();
                // en_cola | corriendo | terminada | fallida | cancelada
                $table->string('estado', 12)->default('en_cola');
                // cron | manual | prueba
                $table->string('origen', 10)->default('manual');
                $table->boolean('prueba')->default(false);
                $table->foreignId('disparado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->text('tarea')->nullable();

                $table->string('modelo_usado', 100)->nullable();
                $table->unsignedInteger('pasos')->default(0);
                $table->unsignedInteger('tokens_entrada')->default(0);
                $table->unsignedInteger('tokens_salida')->default(0);

                $table->text('resumen')->nullable();
                $table->json('resultado')->nullable();
                $table->text('error')->nullable();

                // Que dos pasadas del cron no creen dos corridas de la misma ventana.
                $table->string('clave_idempotencia', 120)->nullable()->unique();

                $table->timestamp('iniciada_at')->nullable();
                $table->timestamp('terminada_at')->nullable();
                $table->timestamps();

                $table->index(['agente_id', 'id']);
                $table->index('estado');
            });
        }

        if (! Schema::hasTable('agente_pasos')) {
            Schema::create('agente_pasos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ejecucion_id')->constrained('agente_ejecuciones')->cascadeOnDelete();
                $table->unsignedInteger('orden');
                // mensaje | herramienta | error
                $table->string('tipo', 12);
                $table->string('herramienta', 60)->nullable();
                $table->json('argumentos')->nullable();
                // Recortado: la bitácora no debe duplicar datos de clientes.
                $table->text('resumen')->nullable();
                $table->unsignedInteger('ms')->default(0);
                $table->timestamp('created_at')->nullable();

                $table->index(['ejecucion_id', 'orden']);
            });
        }

        if (! Schema::hasTable('agente_propuestas')) {
            Schema::create('agente_propuestas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agente_id')->constrained('agentes_internos')->cascadeOnDelete();
                $table->foreignId('ejecucion_id')->nullable()->constrained('agente_ejecuciones')->nullOnDelete();

                $table->string('accion', 60);
                $table->json('parametros');
                $table->text('resumen');
                $table->string('entidad_type', 60)->nullable();
                $table->unsignedBigInteger('entidad_id')->nullable();
                // Quién debería revisarla (el responsable del documento), si se sabe.
                $table->foreignId('destinatario_id')->nullable()->constrained('users')->nullOnDelete();

                // pendiente | aprobada | rechazada | vencida | fallida
                $table->string('estado', 12)->default('pendiente');
                $table->foreignId('decidida_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decidida_at')->nullable();
                $table->text('motivo')->nullable();
                $table->text('resultado')->nullable();
                $table->timestamp('vence_at')->nullable();
                $table->timestamps();

                $table->index(['estado', 'vence_at']);
                $table->index(['agente_id', 'accion', 'entidad_type', 'entidad_id'], 'propuesta_por_entidad');
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }
};
