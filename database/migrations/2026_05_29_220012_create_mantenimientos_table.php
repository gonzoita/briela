<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('mantenimientos')) {
            Schema::create('mantenimientos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('equipo_id')->constrained('equipos_mantenimiento')->cascadeOnDelete();
                $table->enum('tipo', ['preventivo', 'correctivo', 'predictivo'])->default('preventivo');
                $table->enum('estado', ['programado', 'en_proceso', 'completado', 'cancelado'])->default('programado');
                $table->date('fecha_programada');
                $table->date('fecha_inicio')->nullable();
                $table->date('fecha_fin')->nullable();
                $table->string('ejecutor_tipo')->default('interno'); // interno | externo
                $table->string('ejecutor_nombre')->nullable();
                $table->text('descripcion')->nullable();
                $table->text('hallazgos')->nullable();
                $table->text('acciones')->nullable();
                $table->decimal('costo_mano_obra', 12, 2)->default(0);
                $table->decimal('costo_repuestos', 12, 2)->default(0);
                $table->decimal('costo_total', 12, 2)->default(0);
                $table->integer('tiempo_horas')->nullable();
                $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mantenimientos');
    }
};
