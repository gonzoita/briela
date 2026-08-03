<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comisiones_vendedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('total_comision', 12, 2)->default(0);
            $table->enum('estado', [
                'proyectada',
                'confirmada',
                'ejecutada',
                'liquidada',
            ])->default('proyectada');
            $table->date('periodo_mes');
            $table->timestamp('liquidada_at')->nullable();
            $table->timestamps();

            $table->unique('cotizacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_vendedor');
    }
};
