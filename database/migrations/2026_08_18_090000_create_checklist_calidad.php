<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lista de revisión de calidad: qué hay que mirar antes de dar por buena una unidad.
 *
 * Hasta ahora calidad era una decisión de una sola pieza sobre la orden entera —aprobada o a
 * reproceso— con una foto y un comentario. En una orden de diez puertas eso no dice nada: no
 * queda registro de qué se revisó, ni de cuál unidad salió mal, ni de qué le faltaba.
 *
 * Dos tablas, con la misma forma que el resto del sistema:
 *
 * - `checklist_calidad` es la plantilla: cuelga del ensamble cuando es directo y de la
 *   plantilla de ensamble cuando no, igual que los pasos de producción. Por eso es morfológica
 *   en vez de tener dos columnas: la regla de a quién pertenece ya existe y es esa.
 * - `op_item_trabajo_checks` es lo que de verdad se revisó, **por unidad física**. Se copia de
 *   la plantilla al generar el trabajo, igual que los pasos, y se congela ahí: cambiar la
 *   plantilla después no reescribe lo que alguien ya revisó.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checklist_calidad')) {
            Schema::create('checklist_calidad', function (Blueprint $table) {
                $table->id();
                $table->morphs('checkeable');
                $table->string('titulo', 150);
                $table->text('descripcion')->nullable();
                $table->unsignedInteger('orden')->default(0);
                // Un punto que no admite «más o menos»: si exige foto, no se puede dar por
                // revisado sin ella. Sirve para lo que después se discute con el cliente.
                $table->boolean('exige_foto')->default(false);
                // Uno que no bloquea: se anota, pero no impide aprobar la unidad.
                $table->boolean('es_critico')->default(true);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->index(['checkeable_type', 'checkeable_id', 'orden'], 'checklist_calidad_dueno_orden');
            });
        }

        if (! Schema::hasTable('op_item_trabajo_checks')) {
            Schema::create('op_item_trabajo_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('op_item_trabajo_id')->constrained('op_item_trabajos')->cascadeOnDelete();
                // De qué punto de la plantilla salió. Se pone en null si la plantilla cambia:
                // lo revisado no se borra por eso.
                $table->unsignedBigInteger('checklist_calidad_id')->nullable();
                $table->string('titulo', 150);
                $table->text('descripcion')->nullable();
                $table->unsignedInteger('orden')->default(0);
                $table->boolean('exige_foto')->default(false);
                $table->boolean('es_critico')->default(true);
                // pendiente → el que revisa todavía no lo miró. cumple / falla → ya decidió.
                $table->enum('resultado', ['pendiente', 'cumple', 'falla'])->default('pendiente');
                $table->text('observaciones')->nullable();
                $table->json('fotos')->nullable();
                $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revisado_at')->nullable();
                $table->timestamps();

                $table->foreign('checklist_calidad_id')->references('id')->on('checklist_calidad')->nullOnDelete();
                $table->index(['op_item_trabajo_id', 'orden']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('op_item_trabajo_checks');
        Schema::dropIfExists('checklist_calidad');
    }
};
