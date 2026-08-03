<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulas_componente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insumo_id')->constrained('insumos')->cascadeOnDelete();
            $table->enum('tipo_puerta', [
                'M_SUELO','M_TOPE','M_SUELO_D','M_TOPE_D',
                'VAIVEN','VAIVEN_D','INST','INST_D',
                'SE12','SM20','P480',
            ]);
            $table->decimal('cantidad', 8, 4)->default(0);
            $table->boolean('es_lamina')->default(false);
            $table->boolean('escala_con_dimension')->default(false);
            $table->timestamps();

            $table->unique(['insumo_id', 'tipo_puerta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulas_componente');
    }
};
