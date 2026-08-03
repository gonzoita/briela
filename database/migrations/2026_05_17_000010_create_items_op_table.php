<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items_op', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_produccion_id')
                  ->constrained('ordenes_produccion')
                  ->cascadeOnDelete();
            $table->enum('tipo', ['panel', 'puerta', 'componente']);
            $table->integer('cantidad')->default(1);
            $table->text('observaciones')->nullable();

            // ── Campos PANEL ──────────────────────────────────────────────
            $table->decimal('panel_ancho', 8, 2)->nullable();
            $table->decimal('panel_alto', 8, 2)->nullable();
            $table->integer('panel_espesor')->nullable();          // mm
            $table->string('panel_tipo')->nullable();              // poliuretano, EPS, otro
            $table->string('panel_acabado_interior')->nullable();
            $table->string('panel_acabado_exterior')->nullable();

            // ── Campos PUERTA ─────────────────────────────────────────────
            $table->decimal('puerta_ancho', 8, 2)->nullable();
            $table->decimal('puerta_alto', 8, 2)->nullable();
            $table->string('puerta_tipo')->nullable();             // vaiven, corredera, abatible
            $table->string('puerta_sentido')->nullable();          // izquierda, derecha
            $table->string('puerta_acabado')->nullable();
            $table->text('puerta_herrajes')->nullable();

            // ── Campos COMPONENTE ─────────────────────────────────────────
            $table->string('comp_referencia')->nullable();
            $table->text('comp_descripcion')->nullable();
            $table->string('comp_unidad')->nullable();             // unidad, metro, metro2, kilo, litro, juego

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items_op');
    }
};
