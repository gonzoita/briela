<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formula_insumo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insumo_id')->constrained('insumos')->cascadeOnDelete();
            $table->enum('tipo_puerta', [
                'M_SUELO','M_TOPE','M_SUELO_D','M_TOPE_D',
                'VAIVEN','VAIVEN_D','INST','INST_D','SE12','SM20','P480',
            ]);
            $table->string('formula', 500)->nullable()->comment('Expresion: ej. 2*alto_hoja+2*ancho_hoja+0.664');
            $table->string('condicion', 300)->nullable()->comment('Expresion condicional opcional');
            $table->boolean('es_constante')->default(false);
            $table->decimal('valor_constante', 10, 6)->default(0);
            $table->boolean('activo')->default(true);
            $table->string('notas', 200)->nullable();
            $table->timestamps();

            $table->unique(['insumo_id', 'tipo_puerta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_insumo');
    }
};
